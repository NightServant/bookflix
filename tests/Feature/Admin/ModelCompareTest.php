<?php

use App\Enums\ModelKind;
use App\Enums\ModelStatus;
use App\Models\ModelVersion;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * The admin model comparison (8.4): every version of one model over time, a chart of one metric and a table of all of them. It reads
 * `model_versions` only, so the ML service is never called.
 */

beforeEach(function () {
    Http::preventStrayRequests();
});

function itemCfVersion(string $trained, array $metrics, ModelStatus $status = ModelStatus::Retired, ?string $version = null): ModelVersion
{
    return ModelVersion::factory()->create([
        'name' => 'item-cf',
        'version' => $version ?? 'v'.str_replace(['-', ' ', ':'], '', $trained),
        'kind' => ModelKind::ItemCf,
        'status' => $status,
        'trained_at' => $trained,
        'metrics' => $metrics,
    ]);
}

test('non admins and guests are kept out of the page', function () {
    $this->get(route('admin.models.compare'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.models.compare'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.models.compare'))->assertOk()->assertSee('Compare model versions');
});

test('an unknown model name is not found', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.models.compare', 'nope'))->assertNotFound();
});

test('the series is oldest first by training time, the table newest first, and retired versions are included', function () {
    itemCfVersion('2026-10-04 03:00', ['ndcg@10' => 0.149, 'coverage' => 0.43, 'promoted' => false, 'reason' => 'coverage fell']);
    itemCfVersion('2026-09-27 03:00', ['ndcg@10' => 0.151, 'coverage' => 0.44, 'promoted' => true]);
    itemCfVersion('2026-10-11 03:00', ['ndcg@10' => 0.163, 'coverage' => 0.47, 'promoted' => true], ModelStatus::Active);
    ModelVersion::factory()->create(['name' => 'lsa', 'metrics' => ['ndcg@10' => 0.9]]);
    $this->actingAs(User::factory()->admin()->create());

    $page = Livewire::test('pages::admin.models.compare', ['name' => 'item-cf']);

    expect($page->instance()->series)->toBe([
        'labels' => ['Sep 27', 'Oct 4', 'Oct 11'],
        'values' => [
            'ndcg@10' => [0.151, 0.149, 0.163],
            'coverage' => [0.44, 0.43, 0.47],
            'genre_diversity' => [null, null, null],
            'novelty' => [null, null, null],
        ],
        'promoted' => [true, false, true],
    ]);

    $page->assertSeeInOrder(['2026-10-11 03:00', '2026-10-04 03:00', '2026-09-27 03:00'])
        ->assertSee('Retired')->assertSee('Active')->assertSee('Not promoted: coverage fell')->assertDontSee('0.900');
});

test('a version that never finished training and one with no metrics show blanks and say why, without an error', function () {
    itemCfVersion('2026-10-11 03:00', ['ndcg@10' => 0.163, 'promoted' => true], ModelStatus::Active);
    ModelVersion::factory()->create(['name' => 'item-cf', 'kind' => ModelKind::ItemCf, 'status' => ModelStatus::Retired, 'trained_at' => null, 'created_at' => '2026-10-18 03:00', 'metrics' => []]);
    itemCfVersion('2026-10-25 03:00', [], ModelStatus::Candidate);
    $this->actingAs(User::factory()->admin()->create());

    $page = Livewire::test('pages::admin.models.compare')->assertOk();

    expect($page->instance()->series['values']['ndcg@10'])->toBe([0.163, null, null]);
    $page->assertSee('Failed')->assertSee('Training failed, no metrics')->assertSee('Not evaluated yet')->assertSee('—');
});

test('a single version renders the table without a chart, and two or more add the chart', function () {
    itemCfVersion('2026-10-11 03:00', ['ndcg@10' => 0.163, 'promoted' => true], ModelStatus::Active);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.models.compare')->assertSee('2026-10-11 03:00')->assertDontSeeHtml('data-test="version-chart"');

    itemCfVersion('2026-10-18 03:00', ['ndcg@10' => 0.171, 'promoted' => true]);

    Livewire::test('pages::admin.models.compare')->assertSeeHtml('data-test="version-chart"')->assertSeeHtml('role="img"');
});

test('a model with no versions says what to do', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.models.compare', ['name' => 'als'])->assertSee('No versions yet')->assertSee('Run Similar readers from Models');
});

test('each model shows the metrics its evaluation records', function () {
    ModelVersion::factory()->create(['name' => 'dewey-classifier', 'kind' => ModelKind::DeweyClassifier, 'metrics' => ['accuracy' => 0.71, 'baseline' => 0.4, 'promoted' => true]]);
    ModelVersion::factory()->create(['name' => 'ltr', 'kind' => ModelKind::Ltr, 'metrics' => ['ndcg@10' => 0.3, 'hybrid_ndcg@10' => 0.28, 'ndcg_delta' => 0.02, 'promoted' => false, 'reason' => 'NDCG@10 is not above the hybrid order']]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.models.compare', ['name' => 'dewey-classifier'])->assertSee('Accuracy')->assertSee('0.710')->assertSee('Baseline')->assertDontSee('Coverage');
    Livewire::test('pages::admin.models.compare', ['name' => 'ltr'])->assertSee('Hybrid NDCG@10')->assertSee('0.280')->assertSee('Not promoted: NDCG@10 is not above the hybrid order');
});

test('the models page links to the comparison, and the sidebar keeps Models current there', function () {
    $this->actingAs(User::factory()->admin()->create());
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);

    $this->get(route('admin.models'))->assertSee(route('admin.models.compare'), false);

    $current = collect(adminNav($this->get(route('admin.models.compare'))->getContent()))->firstWhere('current', true);
    expect($current['label'] ?? null)->toBe('Models');
});

test('the page re-authorizes on every request', function () {
    itemCfVersion('2026-10-11 03:00', ['ndcg@10' => 0.163]);
    $admin = User::factory()->admin()->create();
    $page = Livewire::actingAs($admin)->test('pages::admin.models.compare');

    $admin->forceFill(['is_admin' => false])->save();
    $this->actingAs($admin->fresh());

    $page->call('$refresh')->assertForbidden();
});
