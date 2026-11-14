<?php

use App\Enums\DuplicateStatus;
use App\Enums\IngestionStatus;
use App\Models\DuplicateCandidate;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use App\Models\Media;
use App\Models\MediaExternalId;
use App\Models\MlJobRun;
use App\Models\ModelVersion;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Cache::forget('admin.dashboard-counts');
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
    $this->actingAs(User::factory()->admin()->create());
});

test('the overview lists what needs attention with the oldest item, and the at a glance lines', function () {
    User::factory()->create();
    DuplicateCandidate::factory()->count(2)->create(['created_at' => now()->subDays(3)]);
    classified_pending_for_overview();

    $this->get(route('admin.index'))->assertOk()
        ->assertSee('Needs attention')->assertSee('At a glance')
        ->assertSee('2 pairs waiting')->assertSee('3 days ago')
        ->assertSee('Open media')->assertSee('Open sync')->assertSee('Open models')->assertSee('Open users')
        ->assertSee('2 accounts, 1 admin')
        ->assertDontSee('Duplicate review</a>', false);
});

test('the overview says all clear when nothing waits', function () {
    $this->get(route('admin.index'))->assertOk()->assertSee('All clear');
});

test('sync shows each source with its status, last sync and record count', function () {
    $source = ExternalSource::factory()->create(['key' => 'openlibrary', 'name' => 'Open Library', 'enabled' => true]);
    MediaExternalId::factory()->count(3)->create(['external_source_id' => $source->id]);
    $idle = ExternalSource::factory()->create(['key' => 'anilist', 'name' => 'AniList', 'enabled' => false]);

    $page = Livewire::test('pages::admin.providers.index');

    expect($page->instance()->records)->toBe([$source->id => 3])
        ->and($page->instance()->running)->toBe([]);
    $page->assertSee('Enabled')->assertSee('Disabled')->assertSee('Records');
    expect($idle->exists)->toBeTrue();
});

test('a source with a sync in progress shows as running', function () {
    $source = ExternalSource::factory()->create(['key' => 'openlibrary', 'name' => 'Open Library', 'enabled' => true]);
    IngestionRun::factory()->create(['external_source_id' => $source->id, 'status' => IngestionStatus::Running]);

    Livewire::test('pages::admin.providers.index')->assertSee('Running now');
});

test('a long run detail is cut to one line but kept in full in the title', function () {
    $error = trim(str_repeat('Connection reset by the source. ', 12));
    IngestionRun::factory()->create(['status' => IngestionStatus::Failed, 'error' => $error]);

    $html = Livewire::test('pages::admin.providers.index')->html();

    expect($html)->toContain('title="'.$error.'"')->and($html)->toContain('truncate');
});

test('the duplicate table has the three row actions and a compare button for each pair', function () {
    $candidate = DuplicateCandidate::factory()->create();

    Livewire::test('pages::admin.duplicates.index')
        ->assertSee('Keep first')->assertSee('Keep second')->assertSee('Not a duplicate')
        ->assertSeeHtml('wire:click="merge('.$candidate->id.', '.$candidate->media_id.')"')
        ->assertSeeHtml('wire:click="merge('.$candidate->id.', '.$candidate->duplicate_media_id.')"')
        ->assertSeeHtml('wire:click="compare('.$candidate->id.')"');
});

test('the compare dialog shows both records in full with the same actions', function () {
    $candidate = DuplicateCandidate::factory()->create();
    $candidate->media->update(['description' => 'The first one is described at some length.']);
    $candidate->duplicateMedia->update(['description' => 'The second one has its own long description.']);

    Livewire::test('pages::admin.duplicates.index')
        ->assertDontSee('described at some length')
        ->call('compare', $candidate->id)
        ->assertSet('comparing', $candidate->id)
        ->assertSee('The first one is described at some length.')->assertSee('The second one has its own long description.')
        ->assertSee('Keep this one')->assertSee('Not a duplicate');
});

test('a pair is settled from the compare dialog and the dialog closes', function () {
    $candidate = DuplicateCandidate::factory()->create();

    Livewire::test('pages::admin.duplicates.index')
        ->call('compare', $candidate->id)->call('merge', $candidate->id, $candidate->duplicate_media_id)
        ->assertSet('comparing', null);

    expect($candidate->fresh()->status)->toBe(DuplicateStatus::Merged);

    $other = DuplicateCandidate::factory()->create();

    Livewire::test('pages::admin.duplicates.index')->call('compare', $other->id)->call('dismiss', $other->id)->assertSet('comparing', null);

    expect($other->fresh()->status)->toBe(DuplicateStatus::Dismissed);
});

test('compare is refused for a settled pair and for a non admin', function () {
    $settled = DuplicateCandidate::factory()->create(['status' => DuplicateStatus::Dismissed]);

    Livewire::test('pages::admin.duplicates.index')->call('compare', $settled->id)->assertNotFound();

    $pair = DuplicateCandidate::factory()->create();
    $page = Livewire::test('pages::admin.duplicates.index');
    auth()->user()->forceFill(['is_admin' => false])->save();

    $page->call('compare', $pair->id)->assertForbidden();
});

test('the classification filters are chips, and All lists every status', function () {
    $this->seed(DeweySeeder::class);
    classified(Media::factory()->create(['title' => 'Waiting Tale']), '813', status: 'pending');
    classified(Media::factory()->create(['title' => 'Kept Tale']), '813', status: 'accepted');
    classified(Media::factory()->create(['title' => 'Refused Tale']), '813', status: 'rejected');

    $page = Livewire::test('pages::admin.classifications.index')
        ->assertSee('Pending')->assertSee('Accepted')->assertSee('Rejected')->assertSee('All')
        ->assertSee('Waiting Tale')->assertDontSee('Kept Tale')->assertDontSee('Refused Tale');

    expect($page->html())->toContain('aria-current="true"');

    $page->set('status', 'all')->assertSee('Waiting Tale')->assertSee('Kept Tale')->assertSee('Refused Tale');
    $page->set('status', 'rejected')->assertSee('Refused Tale')->assertDontSee('Kept Tale');
});

test('a classification row shows its confidence, method, source and a status word', function () {
    $this->seed(DeweySeeder::class);
    classified(Media::factory()->create(['title' => 'Dune']), '813', status: 'pending', confidence: 0.64);

    Livewire::test('pages::admin.classifications.index')
        ->assertSee('64%')->assertSee('Automatic')->assertSee('Pending')->assertSee('Change class');
});

test('the models page splits the metrics into columns and names a skipped run', function () {
    ModelVersion::factory()->active()->create(['name' => 'lsa-test', 'metrics' => ['ndcg@10' => 0.1234, 'coverage' => 0.5]]);
    MlJobRun::factory()->create(['name' => 'train-dewey-classifier', 'status' => 'succeeded', 'metrics' => ['skipped' => ['reason' => 'too few accepted primaries', 'have' => 40, 'need' => 100]], 'finished_at' => now()]);

    $this->get(route('admin.models'))->assertOk()
        ->assertSee('NDCG@10')->assertSee('Coverage')->assertSee('0.123')->assertSee('0.500')
        ->assertSee('Outcome')->assertSee('Skipped')->assertSee('Skipped: too few accepted primaries (40 of 100)')
        ->assertSee('Compare versions');
});

function classified_pending_for_overview(): void
{
    test()->seed(DeweySeeder::class);
    classified(Media::factory()->create(), '813', status: 'pending');
}
