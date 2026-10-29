/**
 * <x-filter-panel>: the filter controls of the Search and Discover pages, built in the browser from the page's one JSON data island (#filter-data)
 * and one markup template (#filter-panel-template), so the tree of about five hundred genres is sent once per page, not per Livewire update and
 * not once for the sidebar and again for the phone sheet. Only one copy of the panel exists at a time: in the sidebar from the lg breakpoint,
 * in the sheet below it; both read and write the same draft here.
 *
 * `draft` is what the reader is choosing; `$wire.filters` is what the page has applied. On a wide screen a change is applied after a short pause
 * (live); on a phone nothing is applied until Apply, and closing the sheet drops the draft. The server never sees the draft.
 *
 * Genres are a two level tree. Choosing a family selects everything under it: its subgenres show as included and cannot be chosen one by one.
 * Choosing a subgenre selects only it; a family with some (not all) subgenres chosen is mixed, and choosing the last of them turns them into the
 * family. A subgenre listed under two families is one slug, checked under both.
 */
const LISTS = ['mediaTypes', 'genres', 'themes', 'tropes', 'demographics', 'ratings', 'excludeWarnings', 'statuses', 'languages'];

// Which draft keys each section of the panel owns (for the count beside its heading).
const SECTIONS = {
    types: ['mediaTypes'],
    genres: ['genres'],
    themes: ['themes'],
    tropes: ['tropes'],
    demographics: ['demographics'],
    ratings: ['ratings'],
    warnings: ['excludeWarnings'],
    more: ['statuses', 'languages', 'years', 'length', 'minRating'],
};

const MAX_MATCHES = 60;

const fold = (text) => String(text).normalize('NFD').replace(/\p{Mn}/gu, '').toLowerCase();

const blank = () => ({
    mediaTypes: [],
    genres: [],
    themes: [],
    tropes: [],
    demographics: [],
    ratings: [],
    excludeWarnings: [],
    statuses: [],
    languages: [],
    years: [],
    length: '',
    minRating: '',
});

const read = (id) => {
    try {
        return JSON.parse(document.getElementById(id)?.textContent ?? 'null');
    } catch {
        return null;
    }
};

export default function filterPanel(Alpine) {
    Alpine.data('filterPanel', ({ years = [0, 0], pause = 400, labels = {} } = {}) => {
        // Outside Alpine's reactive proxy: a Map would not survive it.
        const meta = new Map();
        let media;
        let listen;
        let leave;

        return {
            data: { types: [], genres: [], themes: [], tropes: [], demographics: [], ratings: [], warnings: [], statuses: [], languages: [], lengths: [], minRatings: [] },
            panelHtml: '',
            draft: blank(),
            applied: blank(), // what the page has: the last committed or server state
            desktop: true,
            open: { types: true, genres: true },
            families: {},
            query: { genres: '', themes: '', tropes: '', warnings: '' },
            timer: null,

            init() {
                this.data = { ...this.data, ...(read('filter-data') ?? {}) };
                this.panelHtml = document.getElementById('filter-panel-template')?.innerHTML ?? '';
                this.index();

                media = window.matchMedia('(min-width: 64rem)');
                this.desktop = media.matches;
                listen = (event) => (this.desktop = event.matches);
                media.addEventListener('change', listen);

                // Livewire keeps a copy of the page for the back button, taken right after this event: without the panel built in it, the copy restores clean and the panel is built again.
                leave = () => document.querySelectorAll('[data-filter-mount]').forEach((mount) => mount.replaceChildren());
                document.addEventListener('livewire:navigating', leave);

                this.draft = this.fromWire();
                this.applied = this.fromWire();
                this.reveal();

                // The year slider and the two selects write the draft themselves (x-model): a change there is a change like any other.
                ['draft.years', 'draft.length', 'draft.minRating'].forEach((path) => this.$watch(path, () => this.changed()));

                // Something else changed the filters (a chip, Clear all, the back button): the draft follows, unless a newer choice is waiting to be applied.
                this.$watch('$wire.filters', () => this.resync());
            },

            destroy() {
                clearTimeout(this.timer);
                media?.removeEventListener('change', listen);
                document.removeEventListener('livewire:navigating', leave);
            },

            // The tree as lookups: every slug (families and subgenres) with its name, parents, leaves and where it is listed.
            index() {
                meta.clear();

                for (const family of this.data.genres) {
                    const entry = { slug: family.slug, name: family.name, family: true, count: family.count, leaves: [], parents: [], paths: [] };

                    meta.set(family.slug, entry);

                    for (const group of family.groups) {
                        for (const item of group.items) {
                            const leaf = meta.get(item.slug) ?? { slug: item.slug, name: item.name, family: false, count: item.count, leaves: [], parents: [], paths: [] };

                            leaf.parents.push(family.slug);
                            leaf.paths.push({ family: family.name, group: group.name });
                            meta.set(item.slug, leaf);
                            entry.leaves.includes(item.slug) || entry.leaves.push(item.slug);
                        }
                    }
                }

                for (const entry of meta.values()) {
                    entry.folded = fold(entry.name);
                }
            },

            // The page's filters as a draft: lists of strings, the year range, the two single choices.
            fromWire() {
                const wire = this.$wire.filters ?? {};
                const next = blank();

                for (const key of LISTS) {
                    next[key] = Array.isArray(wire[key]) ? wire[key].map(String) : [];
                }

                next.years = Array.isArray(wire.years) ? wire.years.map(Number).filter(Number.isFinite) : [];
                next.length = typeof wire.length === 'string' ? wire.length : '';
                next.minRating = wire.minRating === undefined || wire.minRating === null ? '' : String(wire.minRating);

                return next;
            },

            // A state as the server should get it: no duplicates, a year range only when it narrows, nothing redundant under a chosen family.
            snapshot(state = this.draft) {
                const next = blank();

                for (const key of LISTS) {
                    next[key] = [...new Set(state[key])];
                }

                const covered = new Set(next.genres.filter((slug) => meta.get(slug)?.family).flatMap((slug) => meta.get(slug).leaves));

                next.genres = next.genres.filter((slug) => ! covered.has(slug));

                const [from, to] = state.years.map(Number);

                next.years = Number.isFinite(from) && Number.isFinite(to) && (from > years[0] || to < years[1]) ? [from, to] : [];
                next.length = state.length || '';
                next.minRating = state.minRating || '';

                return next;
            },

            // Open the sections and families that already hold a choice, so what is applied is visible.
            reveal() {
                for (const [name, keys] of Object.entries(SECTIONS)) {
                    keys.some((key) => (Array.isArray(this.draft[key]) ? this.draft[key].length : this.draft[key])) && (this.open[name] = true);
                }

                for (const slug of this.draft.genres) {
                    meta.get(slug)?.parents.forEach((parent) => (this.families[parent] = true));
                }
            },

            resync() {
                if (this.timer) {
                    return;
                }

                const next = this.fromWire();

                this.applied = next;

                if (JSON.stringify(this.snapshot()) !== JSON.stringify(this.snapshot(next))) {
                    this.draft = next;
                    this.reveal();
                }
            },

            get dirty() {
                return JSON.stringify(this.snapshot()) !== JSON.stringify(this.snapshot(this.applied));
            },

            // How many filters the page has applied (a year range, a length and a rating count one each).
            get activeCount() {
                const state = this.snapshot(this.applied);

                return LISTS.reduce((total, key) => total + state[key].length, 0) + (state.years.length ? 1 : 0) + (state.length ? 1 : 0) + (state.minRating ? 1 : 0);
            },

            // Choices in the draft that a section owns: the number beside its heading.
            sectionCount(name) {
                return SECTIONS[name].reduce((total, key) => {
                    const value = this.draft[key];

                    return total + (key === 'years' ? this.snapshot().years.length ? 1 : 0 : Array.isArray(value) ? value.length : value ? 1 : 0);
                }, 0);
            },

            isOpen(name) {
                return !! this.open[name];
            },

            toggleSection(name) {
                this.open[name] = ! this.open[name];
            },

            // A change to the draft: applied after a pause on a wide screen, held for Apply on a phone.
            changed() {
                if (! this.desktop || ! this.dirty) {
                    return;
                }

                clearTimeout(this.timer);
                this.timer = setTimeout(() => {
                    this.timer = null;
                    this.commit();
                }, pause);
            },

            commit() {
                clearTimeout(this.timer);
                this.timer = null;

                const next = this.snapshot();

                if (JSON.stringify(next) === JSON.stringify(this.snapshot(this.applied))) {
                    return;
                }

                this.applied = next;
                this.$wire.$set('filters', next);
            },

            // The sheet's Apply button.
            apply() {
                this.commit();
            },

            // The sheet closed without Apply: the draft goes back to what the page has.
            discard() {
                clearTimeout(this.timer);
                this.timer = null;
                this.draft = this.fromWire();
            },

            // Clear all: the draft empties; on a wide screen that is applied at once, on a phone it waits for Apply.
            clear() {
                this.draft = blank();

                if (this.desktop) {
                    this.commit();
                }
            },

            has(key, value) {
                return this.draft[key].includes(value);
            },

            toggle(key, value) {
                const list = this.draft[key];
                const at = list.indexOf(value);

                at === -1 ? list.push(value) : list.splice(at, 1);
                this.changed();
            },

            // Genres.

            isFamilyOpen(slug) {
                return !! this.families[slug];
            },

            toggleFamily(slug) {
                this.families[slug] = ! this.families[slug];
            },

            // The selected families that include this subgenre.
            coveredBy(slug) {
                return (meta.get(slug)?.parents ?? []).filter((parent) => this.draft.genres.includes(parent));
            },

            // 'on' (chosen), 'implied' (included by a chosen family), 'mixed' (a family with some of its subgenres chosen) or 'off'.
            genreState(slug) {
                const entry = meta.get(slug);

                if (! entry) {
                    return 'off';
                }

                if (entry.family) {
                    if (this.draft.genres.includes(slug)) {
                        return 'on';
                    }

                    return entry.leaves.some((leaf) => this.draft.genres.includes(leaf) || this.coveredBy(leaf).length) ? 'mixed' : 'off';
                }

                return this.coveredBy(slug).length ? 'implied' : this.draft.genres.includes(slug) ? 'on' : 'off';
            },

            impliedText(slug) {
                const by = this.coveredBy(slug);

                return by.length ? `${labels.includedWith ?? 'included with'} ${by.map((parent) => meta.get(parent).name).join(', ')}` : '';
            },

            toggleGenre(slug) {
                const entry = meta.get(slug);
                const chosen = new Set(this.draft.genres);

                if (! entry || this.genreState(slug) === 'implied') {
                    return;
                }

                if (entry.family) {
                    if (chosen.has(slug)) {
                        chosen.delete(slug);
                    } else {
                        chosen.add(slug);
                        entry.leaves.forEach((leaf) => chosen.delete(leaf));
                    }
                } else if (chosen.has(slug)) {
                    chosen.delete(slug);
                } else {
                    chosen.add(slug);

                    // The last of a family's subgenres turns them into the family.
                    for (const parent of entry.parents) {
                        const { leaves } = meta.get(parent);

                        if (leaves.every((leaf) => chosen.has(leaf))) {
                            leaves.forEach((leaf) => chosen.delete(leaf));
                            chosen.add(parent);
                        }
                    }
                }

                this.draft.genres = [...chosen];
                this.changed();
            },

            // The genre search: families and subgenres whose name holds the text, each with where it is listed. Exact starts first.
            get genreMatches() {
                const text = fold(this.query.genres.trim());

                if (! text) {
                    return [];
                }

                const hits = [];

                for (const entry of meta.values()) {
                    if (entry.folded.includes(text)) {
                        const [first, ...others] = entry.paths;

                        hits.push({
                            slug: entry.slug,
                            name: entry.name,
                            count: entry.count,
                            path: entry.family ? labels.family ?? '' : [first.family, first.group].filter(Boolean).join(' · '),
                            also: others.map((path) => path.family),
                            rank: entry.folded.startsWith(text) ? 0 : 1,
                        });
                    }
                }

                return hits.sort((a, b) => a.rank - b.rank || a.name.localeCompare(b.name)).slice(0, MAX_MATCHES);
            },

            get genreStatus() {
                if (! this.query.genres.trim()) {
                    return '';
                }

                const total = this.genreMatches.length;

                return total ? (labels.matches ?? ':count matches').replace(':count', total === MAX_MATCHES ? `${MAX_MATCHES}+` : total) : labels.noMatches ?? 'No matches';
            },

            // The flat lists (themes, demographics, warnings...) narrowed by their search text, when they have one.
            listFor(source, search) {
                const text = search ? fold(this.query[search].trim()) : '';
                const items = this.data[source];

                return text ? items.filter((item) => fold(item.label).includes(text)) : items;
            },

            // Tropes come in display groups; a group with no match is left out.
            get tropeGroups() {
                const text = fold(this.query.tropes.trim());

                return this.data.tropes
                    .map((group) => ({ ...group, items: text ? group.items.filter((item) => fold(item.label).includes(text)) : group.items }))
                    .filter((group) => group.items.length);
            },

            searchStatus(source) {
                const search = { themes: 'themes', tropes: 'tropes', warnings: 'warnings' }[source];

                if (! this.query[search].trim()) {
                    return '';
                }

                const total = source === 'tropes' ? this.tropeGroups.reduce((sum, group) => sum + group.items.length, 0) : this.listFor(source, search).length;

                return total ? (labels.matches ?? ':count matches').replace(':count', total) : labels.noMatches ?? 'No matches';
            },

            // Arrow keys move between the rows of a list (every checkbox and disclosure in it, in order); Right and Left open and close a family.
            // Tab still walks every control: the arrows are the shortcut.
            navigate(event) {
                const keys = ['ArrowDown', 'ArrowUp', 'Home', 'End', 'ArrowRight', 'ArrowLeft'];

                if (! keys.includes(event.key) || event.altKey || event.ctrlKey || event.metaKey || event.target.matches('input[type=search]')) {
                    return;
                }

                const stops = [...event.currentTarget.querySelectorAll('[data-nav]')].filter((stop) => stop.offsetParent !== null);
                const at = stops.indexOf(event.target);

                if (at === -1) {
                    return;
                }

                const toggle = event.target.matches('[data-family-toggle]') ? event.target : null;
                const slug = toggle?.dataset.slug;

                if (event.key === 'ArrowRight' && toggle) {
                    event.preventDefault();
                    this.isFamilyOpen(slug) ? stops[at + 1]?.focus() : this.toggleFamily(slug);

                    return;
                }

                if (event.key === 'ArrowLeft') {
                    event.preventDefault();

                    if (toggle && this.isFamilyOpen(slug)) {
                        this.toggleFamily(slug);
                    } else {
                        // From a subgenre, back to its family's disclosure.
                        event.target.closest('[data-family]')?.querySelector('[data-family-toggle]')?.focus();
                    }

                    return;
                }

                if (event.key === 'ArrowRight') {
                    return;
                }

                event.preventDefault();

                const target = { ArrowDown: stops[at + 1], ArrowUp: stops[at - 1], Home: stops[0], End: stops[stops.length - 1] }[event.key];

                target?.focus();
            },
        };
    });
}
