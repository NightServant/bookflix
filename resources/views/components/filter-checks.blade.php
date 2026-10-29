@props(['model', 'source', 'search' => null, 'columns' => 1])

{{--
    A checkbox list of <x-filter-panel>: every option of `data[source]` (value and label, from the page's data island) as a 44px row bound to the
    draft's `model` list. With `search`, the rows are narrowed by that query as the reader types.
--}}
<ul @keydown="navigate($event)" @class(['grid', 'grid-cols-2' => $columns === 2])>
    <template x-for="option in listFor('{{ $source }}', {{ $search ? "'".$search."'" : 'null' }})" :key="option.value">
        <li>
            <label class="filter-row">
                <input type="checkbox" class="filter-check" data-filter="{{ $model }}" :value="option.value" :checked="has('{{ $model }}', option.value)" @change="toggle('{{ $model }}', option.value)" data-nav>
                <span x-text="option.label"></span>
            </label>
        </li>
    </template>
</ul>
