<?php

use App\Catalog\Popularity\PopularityScorer;
use App\Models\ContentSource;
use App\Models\ExternalSource;
use App\Models\LibraryEntry;
use App\Models\Media;
use App\Models\ReadingProgress;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * The production app role (database/sql/app-role.sql, P1-R16): rows in, rows out, the popularity refresh, and nothing else. The committed script runs here inside
 * the test's transaction against a role named for this process (`bookflix_app` swapped for `bookflix_app_t<pid>`; CREATE ROLE is transactional, so nothing
 * outlives the test and parallel runs cannot collide). Then `SET LOCAL ROLE` makes every following query on the connection run as that role.
 */

beforeEach(function () {
    $this->role = 'bookflix_app_t'.getmypid();
    DB::unprepared(str_replace('bookflix_app', $this->role, (string) file_get_contents(database_path('sql/app-role.sql'))));

    $this->asAppRole = fn () => DB::statement("set local role {$this->role}");
    $this->asOwner = fn () => DB::statement('reset role');
});

/** The error text of a statement the role must not be allowed to run (inside a savepoint, so the test's transaction survives). */
function refusedStatement(string $sql): string
{
    try {
        DB::transaction(fn () => DB::statement($sql));
    } catch (QueryException $e) {
        return $e->getMessage();
    }

    return 'it ran';
}

test('the role reads and writes rows, sequences included', function () {
    $user = User::factory()->create();
    $media = Media::factory()->create();
    ($this->asAppRole)();

    $entry = LibraryEntry::factory()->create(['user_id' => $user->id, 'media_id' => $media->id]);
    $entry->update(['favorited_at' => now()]);

    expect(LibraryEntry::query()->whereKey($entry->getKey())->value('favorited_at'))->not->toBeNull();

    $second = Media::factory()->create();
    expect($second->id)->toBeGreaterThan($media->id)->and(Media::query()->count())->toBe(2);

    $entry->delete();
    expect(LibraryEntry::query()->count())->toBe(0);
});

test('an authenticated request can add to the library and save a reading place as the role', function () {
    Storage::fake(ContentSource::disk());
    $reader = User::factory()->create();
    $media = Media::factory()->create();
    $source = ContentSource::factory()->publicDomain()->verified()->ready()->create();
    ($this->asAppRole)();

    $this->actingAs($reader)->post(route('library.store'), ['media_id' => $media->id])->assertRedirect();
    expect(LibraryEntry::query()->where('user_id', $reader->id)->where('media_id', $media->id)->exists())->toBeTrue();

    $this->actingAs($reader)->postJson(route('reader.progress', $source), [
        'locator' => ['cfi' => 'epubcfi(/6/8!/4/2)', 'fraction' => 0.25], 'percent' => 25.5, 'chapter' => 'Chapter I', 'seconds' => 30, 'client_session' => 'session-aaaa1111', 'client_seq' => 1,
    ])->assertNoContent();
    expect(ReadingProgress::query()->where('user_id', $reader->id)->value('percent'))->not->toBeNull();
});

test('the popularity scorer works as the role: its temporary table needs no right beyond the default', function () {
    $source = ExternalSource::factory()->create(['key' => 'anilist']);
    $media = Media::factory()->create();
    DB::table('media_metrics')->insert(['media_id' => $media->id, 'external_source_id' => $source->id, 'metric' => 'popularity', 'value' => 100, 'observed_at' => now()]);
    ($this->asAppRole)();

    expect(app(PopularityScorer::class)->run())->toBe(1);
});

test('the role can refresh the popularity view, plainly and concurrently, without owning it', function () {
    ($this->asAppRole)();

    DB::statement('refresh materialized view media_popularity');
    DB::statement('refresh materialized view concurrently media_popularity');

    expect(DB::selectOne('select count(*) as total from media_popularity')->total)->toBeInt();
});

test('the role cannot change the schema, rewrite the migration history or take other tables over', function (string $sql) {
    ($this->asAppRole)();

    expect(refusedStatement($sql))->toMatch('/permission denied|must be owner/i');
})->with([
    'create a table' => ['create table app_role_probe (id int)'],
    'alter a table' => ['alter table media add column app_role_probe int'],
    'drop a table' => ['drop table media'],
    'truncate a table' => ['truncate table user_library'],
    'create an extension' => ['create extension if not exists hstore'],
    'create an index' => ['create index app_role_probe on media (title)'],
    'drop the view' => ['drop materialized view media_popularity'],
    'rewrite the migration history' => ["delete from migrations where migration like '%users%'"],
    'create a schema' => ['create schema app_role_probe'],
    'create a role' => ['create role app_role_probe_two'],
]);

test('the role is not a superuser and bypasses nothing', function () {
    $row = DB::selectOne('select rolsuper, rolcreaterole, rolcreatedb, rolreplication, rolbypassrls, rolcanlogin from pg_roles where rolname = ?', [$this->role]);

    expect($row->rolsuper)->toBeFalse()->and($row->rolcreaterole)->toBeFalse()->and($row->rolcreatedb)->toBeFalse()
        ->and($row->rolreplication)->toBeFalse()->and($row->rolbypassrls)->toBeFalse()->and($row->rolcanlogin)->toBeTrue();
});

test('the script puts no password anywhere and can be run twice', function () {
    $sql = (string) file_get_contents(database_path('sql/app-role.sql'));

    // The comments tell the owner how to set one; no statement does.
    $statements = preg_replace('/^\s*--.*$/m', '', $sql);

    expect($statements)->not->toMatch('/password/i');

    // Re-running after new migrations must not fail or loosen anything.
    DB::unprepared(str_replace('bookflix_app', $this->role, $sql));
    ($this->asAppRole)();

    expect(refusedStatement('create table app_role_probe (id int)'))->toMatch('/permission denied/i');
});

test('after RESET ROLE the owner is back', function () {
    ($this->asAppRole)();
    expect(refusedStatement('create table app_role_probe (id int)'))->toMatch('/permission denied/i');

    ($this->asOwner)();
    DB::statement('create table app_role_probe (id int)');

    expect(DB::selectOne("select to_regclass('app_role_probe') is not null as present")->present)->toBeTrue();
});

test('pgsql_owner mirrors pgsql until the DB_OWNER settings are given', function () {
    $pgsql = config('database.connections.pgsql');
    $owner = config('database.connections.pgsql_owner');

    expect($owner)->toBe($pgsql);
});

test('pgsql_owner reads its own settings when they are set', function () {
    $names = ['DB_OWNER_HOST', 'DB_OWNER_USERNAME', 'DB_OWNER_PASSWORD', 'DB_OWNER_URL'];
    $keep = array_map(fn (string $name) => $_ENV[$name] ?? null, array_combine($names, $names));

    $_ENV['DB_OWNER_HOST'] = $_SERVER['DB_OWNER_HOST'] = 'owner.example.test';
    $_ENV['DB_OWNER_USERNAME'] = $_SERVER['DB_OWNER_USERNAME'] = 'bookflix_owner';
    $_ENV['DB_OWNER_PASSWORD'] = $_SERVER['DB_OWNER_PASSWORD'] = 'x';

    try {
        $connections = (require config_path('database.php'))['connections'];
    } finally {
        foreach ($names as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    expect($connections['pgsql_owner'])->toMatchArray(['host' => 'owner.example.test', 'username' => 'bookflix_owner', 'password' => 'x'])
        ->and($connections['pgsql'])->not->toMatchArray(['host' => 'owner.example.test']);
});

test('migrating with --database=pgsql_owner makes it the default connection for the run', function () {
    $during = app('migrator')->usingConnection('pgsql_owner', fn () => DB::getDefaultConnection());

    expect($during)->toBe('pgsql_owner')->and(DB::getDefaultConnection())->not->toBe('pgsql_owner');
});
