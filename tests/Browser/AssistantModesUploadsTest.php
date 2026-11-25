<?php

use App\Models\AssistantConversation;
use App\Models\Genre;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * The assistant's task picker, depth switch and attach button in a real browser: keyboard use, an attached .txt going all the way (chip, send,
 * chip in the transcript), the audit, and the phone. The model is faked.
 */

/**
 * The browser-test server hands a multipart field named `files[]` to Laravel under that literal name; this puts it where PHP's own parser would
 * (see UploadPageTest). A test harness shim only.
 */
class AssistantFoldUploadedFilesShim
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->files->has('files[]')) {
            $request->files->set('files', Arr::wrap($request->files->get('files[]')));
            $request->files->remove('files[]');
        }

        return $next($request);
    }
}

beforeEach(function () {
    app('router')->prependMiddlewareToGroup('web', AssistantFoldUploadedFilesShim::class);
    Storage::fake('tmp-for-tests');
    $this->seed([MediaTypeSeeder::class, TaxonomySeeder::class, DeweySeeder::class]);
    config()->set('bookflix.ai', array_merge(config('bookflix.ai'), [
        'enabled' => true, 'base_url' => 'https://llm.test/v1', 'api_key' => 'sk-test',
        'models' => ['fast' => 'tiny-model', 'smart' => 'big-model', 'vision' => null], 'log_payloads' => false,
    ]));

    $court = Media::factory()->manga()->create(['title' => 'Crimson Court', 'cover_url' => '/favicon.svg']);
    $court->genres()->attach(Genre::where('slug', 'dark-fantasy')->firstOrFail());

    $answer = ['choices' => [['message' => ['role' => 'assistant', 'content' => 'Nothing in my notes beats Crimson Court.'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]];
    Http::fake(['*' => Http::response($answer)]);

    $this->actingAs(User::factory()->create());
    $this->get(route('assistant'))->assertOk();

    $this->notes = sys_get_temp_dir().'/reading-notes.txt';
    file_put_contents($this->notes, "I liked dark fantasy with court politics.\n");

    $this->desktop = ['viewport' => ['width' => 1440, 'height' => 900], 'reducedMotion' => 'reduce'];
    $this->phone = ['viewport' => ['width' => 375, 'height' => 812], 'reducedMotion' => 'reduce'];
});

afterEach(fn () => @unlink($this->notes));

test('the task picker is a radio group and the depth switch a toggle button, both usable from the keyboard', function () {
    $page = visit(route('assistant'), $this->desktop)->wait(1);

    $page->assertScript('document.querySelectorAll("[role=radiogroup] input[type=radio]").length', 3)
        ->assertScript('document.querySelector("[role=radiogroup] input:checked").value', 'find')
        ->assertAttribute('[data-test=depth-toggle]', 'aria-pressed', 'false')
        ->assertScript('Array.from(document.querySelectorAll("[role=radiogroup] label > span, [data-test=depth-toggle], [data-test=attach]")).every((el) => el.getBoundingClientRect().height >= 40)', true)
        ->assertScript('document.querySelector("[data-test=attach]").getBoundingClientRect().width', 44)
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'assistant-modes-1440');

    // Arrow keys move through the radio group, as a native one does.
    $page->script('document.querySelector("[role=radiogroup] input:checked").focus()');
    $page->keys(':root', 'ArrowRight')
        ->assertScript('document.querySelector("[role=radiogroup] input:checked").value', 'ask')
        ->assertScript('getComputedStyle(document.activeElement.nextElementSibling).boxShadow !== "none"', true)
        ->keys(':root', 'ArrowRight')
        ->assertScript('document.querySelector("[role=radiogroup] input:checked").value', 'plan');

    // Space presses the depth switch.
    $page->script('document.querySelector("[data-test=depth-toggle]").focus()');
    $page->keys(':root', 'Space')->wait(0.8)
        ->assertAttribute('[data-test=depth-toggle]', 'aria-pressed', 'true')
        ->assertNoJavaScriptErrors();
});

test('an attached text file shows a chip, is sent with the message and stays in the transcript as a chip', function () {
    $page = visit(route('assistant'), $this->desktop)->wait(1);

    $page->assertScript('document.querySelector("[data-test=attach]").getAttribute("aria-label")', 'Attach files')
        ->attach('input[type=file]', $this->notes)->wait(1.5)
        ->assertSee('reading-notes.txt')
        ->assertAttribute('button[aria-label="Remove reading-notes.txt"]', 'type', 'button')
        ->assertScript('document.querySelector("button[aria-label=\"Remove reading-notes.txt\"]").getBoundingClientRect().width >= 44', true)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'assistant-attached-1440')
        ->type('#assistant-message', 'what fits my notes?')
        ->keys('#assistant-message', 'Enter')->wait(2.5)
        ->assertSee('Nothing in my notes beats Crimson Court.')
        ->assertScript('document.querySelector("[aria-live=polite] ul[aria-label=\"Attached files\"]").textContent.includes("reading-notes.txt")', true)
        ->assertScript('document.querySelectorAll("form ~ ul, ul[aria-label=\"Attached files\"]").length', 1)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues();

    $sent = Http::recorded()->first()[0]->data()['messages'][1]['content'];
    expect($sent)->toContain('<attachment name="reading-notes.txt">I liked dark fantasy');
    expect(array_filter(Storage::disk('tmp-for-tests')->allFiles('livewire-tmp'), fn (string $path): bool => ! str_ends_with($path, '.json')))->toBeEmpty();
});

test('the attach and remove buttons are reachable by keyboard and removing a chip drops it', function () {
    $page = visit(route('assistant'), $this->desktop)->wait(1);

    $page->attach('input[type=file]', $this->notes)->wait(1.5)->assertSee('reading-notes.txt');
    $page->script('document.getElementById("assistant-message").focus()');

    $order = [];

    foreach (range(1, 4) as $step) {
        $page->keys(':root', 'Shift+Tab');
        $order[] = $page->script('document.activeElement.dataset.test || document.activeElement.getAttribute("aria-label") || document.activeElement.textContent.trim()');
    }

    expect($order)->toContain('attach')->toContain('Remove reading-notes.txt');

    $page->script('document.querySelector("button[aria-label=\"Remove reading-notes.txt\"]").focus()');
    $page->keys(':root', 'Enter')->wait(1)->assertDontSee('reading-notes.txt');
});

test('on a phone the pickers and a chip fit without sideways scrolling', function () {
    $page = visit(route('assistant'), $this->phone)->wait(1);

    $page->attach('input[type=file]', $this->notes)->wait(1.5)
        ->assertSee('reading-notes.txt')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertScript('Array.from(document.querySelectorAll("[role=radiogroup] label, [data-test=depth-toggle], [data-test=attach]")).every((el) => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= document.documentElement.clientWidth; })', true)
        ->assertScript('document.getElementById("assistant-message").getBoundingClientRect().height >= 44', true)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'assistant-attached-375');
});

test('on a desktop the chat uses the full width: history flush left, an 880 px column, the input bar at the foot of a long chat', function () {
    $chat = AssistantConversation::factory()->for(auth()->user())->create(['title' => 'A long chat']);

    foreach (range(1, 14) as $n) {
        $chat->messages()->create(['role' => 'user', 'text' => "Question {$n}: something long enough to take a line or two of the column."]);
        $chat->messages()->create(['role' => 'assistant', 'text' => str_repeat("Answer {$n} goes on for a while so lines would run long if nothing capped them. ", 6)]);
    }

    $page = visit(route('assistant.show', $chat->id), $this->desktop)->wait(1.5);

    $page->assertScript('Math.round(document.querySelector("aside").getBoundingClientRect().left)', 0)
        ->assertScript('Math.round(document.querySelector("aside").getBoundingClientRect().height)', 900)
        ->assertScript('Math.round(document.getElementById("assistant-message").closest("form").getBoundingClientRect().width)', 880)
        ->assertScript('(() => { const f = document.getElementById("assistant-message").closest("form").getBoundingClientRect(); const a = document.querySelector("aside").getBoundingClientRect(); return Math.abs((f.left + f.right) / 2 - (a.right + innerWidth) / 2) < 2; })()', true)
        ->assertScript('Math.round(document.getElementById("assistant-message").closest(".sticky").getBoundingClientRect().bottom)', 900)
        ->assertScript('Array.from(document.querySelectorAll("[data-latest-answer] p")).every((p) => p.getBoundingClientRect().width <= 70 * 9.5)', true)
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()
        ->screenshot(filename: 'assistant-long-1440');
});

test('a PDF is read in the browser: its text goes to the model in an attachment block and no PDF bytes are uploaded', function () {
    $pdf = sys_get_temp_dir().'/small-story.pdf';
    file_put_contents($pdf, tinyPdf(1));

    $page = visit(route('assistant'), $this->desktop)->wait(1);

    $page->attach('input[type=file]', $pdf)->wait(3)
        ->assertSee('small-story.pdf')
        ->assertDontSee('Reading PDF')
        ->assertNoJavaScriptErrors()
        ->type('#assistant-message', 'what is in my PDF?')
        ->keys('#assistant-message', 'Enter')->wait(2.5)
        ->assertSee('Nothing in my notes beats Crimson Court.')
        ->assertScript('document.querySelector("[aria-live=polite] ul[aria-label=\"Attached files\"]").textContent.includes("small-story.pdf")', true)
        ->assertNoJavaScriptErrors();

    @unlink($pdf);

    $sent = Http::recorded()->first()[0]->data()['messages'][1]['content'];

    expect($sent)->toContain('<attachment name="small-story.pdf">')->toContain('Page 1 of 1')
        ->and(Storage::disk('tmp-for-tests')->allFiles())->toBeEmpty();
});
