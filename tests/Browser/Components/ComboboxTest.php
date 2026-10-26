<?php

beforeEach(function () {
    $this->path = componentPage(<<<'BLADE'
        <div x-data="{ genre: null, genres: [] }">
            <x-combobox data-test="single" label="Genre" placeholder="Pick a genre" x-model="genre" :options="['fantasy' => 'Fantasy', 'mystery' => 'Mystery', 'cafe' => 'Café stories', 'sci-fi' => 'Science fiction']" />
            <output data-test="genre" x-text="genre"></output>

            <x-combobox data-test="multiple" label="Genres" multiple x-model="genres" :options="['fantasy' => 'Fantasy', 'mystery' => 'Mystery', 'sci-fi' => 'Science fiction']" />
            <output data-test="genres" x-text="genres.join(',')"></output>
        </div>
        BLADE);

    // Small JS probes scoped to one of the two comboboxes, so each test reads like the behaviour it checks.
    $this->input = '[data-test=single] [role=combobox]';
    $this->multipleInput = '[data-test=multiple] [role=combobox]';
    $this->in = fn (string $which, string $expression) => "(() => { const root = document.querySelector('[data-test={$which}]'); return {$expression}; })()";
    $this->expanded = ($this->in)('single', 'root.querySelector("[role=combobox]").getAttribute("aria-expanded")');
    $this->visibleOptions = ($this->in)('single', 'Array.from(root.querySelectorAll("[role=option]")).filter((o) => o.offsetParent !== null).map((o) => o.textContent.trim()).join("|")');
    $this->out = fn (string $name) => "document.querySelector('[data-test={$name}]').textContent";
});

test('a combobox follows the APG pattern: typing filters, arrows move, Enter selects, Escape closes and focus stays, before and after wire:navigate', function () {
    assertComponentAcceptance($this->path, function ($page) {
        $page->assertScript(($this->in)('single', 'root.querySelector("[role=combobox]").getAttribute("aria-autocomplete")'), 'list')
            ->assertScript($this->expanded, 'false')
            ->keys($this->input, ['m', 'y'])
            ->assertScript($this->expanded, 'true')
            ->assertScript($this->visibleOptions, 'Mystery')
            ->assertScript(($this->in)('single', 'root.querySelector("ul").getAttribute("role")'), 'listbox')
            ->assertScript(($this->in)('single', 'root.querySelector("[role=combobox]").getAttribute("aria-activedescendant") === root.querySelector("[role=option]:not([style*=none])").id'), true)
            ->keys($this->input, 'Enter')
            ->assertScript(($this->out)('genre'), 'mystery')
            ->assertScript(($this->in)('single', 'root.querySelector("[role=combobox]").value'), 'Mystery')
            ->assertScript($this->expanded, 'false')
            ->keys($this->input, 'ArrowDown')
            ->assertScript($this->expanded, 'true')
            ->keys($this->input, 'Escape')
            ->assertScript($this->expanded, 'false')
            ->assertScript(($this->in)('single', 'document.activeElement === root.querySelector("[role=combobox]")'), true);
    });
});

test('arrow keys walk the options, and up from the first goes back', function () {
    $page = visit($this->path)->wait(1);

    $page->keys($this->input, 'ArrowDown')->wait(0.3)
        ->keys($this->input, ['ArrowDown', 'ArrowDown'])->wait(0.3)
        ->assertScript($this->visibleOptions, 'Fantasy|Mystery|Café stories|Science fiction')
        ->assertScript(($this->in)('single', 'root.querySelector("[role=combobox]").getAttribute("aria-activedescendant") === root.querySelectorAll("[role=option]")[2].id'), true)
        ->keys($this->input, ['ArrowUp', 'Enter'])
        ->assertScript(($this->out)('genre'), 'mystery');
});

test('the filter ignores case and accents', function () {
    $page = visit($this->path)->wait(1);

    $page->keys($this->input, ['c', 'a', 'f', 'e'])
        ->assertScript($this->visibleOptions, 'Café stories');
});

test('no match shows the status line and an empty list', function () {
    $page = visit($this->path)->wait(1);

    $page->keys($this->input, ['z', 'z', 'z'])
        ->assertScript($this->visibleOptions, '')
        ->assertScript(($this->in)('single', 'Array.from(root.querySelectorAll("[role=status]")).some((s) => s.offsetParent !== null && s.textContent.trim() === "No matches")'), true);
});

test('the toggle button opens the list and clicking an option selects it', function () {
    $page = visit($this->path)->wait(1);

    $page->click('[data-test=single] [aria-label="Show options"]')
        ->wait(0.3)
        ->assertScript($this->expanded, 'true')
        ->assertScript(($this->in)('single', 'root.querySelector("[aria-label=\"Show options\"]").getBoundingClientRect().height >= 44'), true)
        ->click('[data-test=single] [role=option]:nth-of-type(4)')
        ->assertScript(($this->out)('genre'), 'sci-fi')
        ->assertScript($this->expanded, 'false')
        ->assertScript(($this->in)('single', 'document.activeElement === root.querySelector("[role=combobox]")'), true);
});

test('multiple mode keeps the list open, shows removable chips and removes them with a button', function () {
    $page = visit($this->path)->wait(1);

    $page->keys($this->multipleInput, 'ArrowDown')->wait(0.3)
        ->keys($this->multipleInput, ['Enter', 'ArrowDown', 'Enter'])->wait(0.3)
        ->assertScript(($this->out)('genres'), 'fantasy,mystery')
        ->assertScript(($this->in)('multiple', 'root.querySelector("[role=combobox]").getAttribute("aria-expanded")'), 'true')
        ->assertScript(($this->in)('multiple', 'root.querySelector("ul[role=listbox]").getAttribute("aria-multiselectable")'), 'true')
        ->assertScript(($this->in)('multiple', 'root.querySelector("[role=combobox]").hasAttribute("aria-multiselectable")'), false)
        ->assertScript(($this->in)('multiple', 'Array.from(root.querySelectorAll("ul[aria-label=Selected] li")).filter((li) => li.offsetParent !== null).map((li) => li.textContent.trim()).join("|")'), 'Fantasy|Mystery')
        ->assertScript(($this->in)('multiple', 'root.querySelector("[aria-label=\"Remove Fantasy\"]").getBoundingClientRect().height >= 44'), true)
        ->click('[data-test=multiple] [aria-label="Remove Fantasy"]')
        ->wait(0.3)
        ->assertScript(($this->out)('genres'), 'mystery')
        ->assertScript(($this->in)('multiple', 'document.activeElement === root.querySelector("[role=combobox]")'), true);
});

test('a combobox works the same with reduced motion', function () {
    assertComponentAcceptance($this->path, function ($page) {
        // The list takes two frames to open and set its active option, as it would for a person pressing the next key.
        $page->keys($this->input, 'ArrowDown')->wait(0.3)
            ->keys($this->input, 'Enter')
            ->assertScript(($this->out)('genre'), 'fantasy');
    }, ['reducedMotion' => 'reduce']);
});
