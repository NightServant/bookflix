@props(['steps', 'current' => 1, 'label' => null, 'action' => null])

{{--
    The steps of a flow, numbered `01 Types`, `02 Genres`, `03 Stories`, as one row of hairlines: an eyebrow (the number, `text-legal`,
    uppercase, wide tracking) over the name. The rule above a step is white once it is reached and a hairline until then.
    `current` is the 1-based step. The step the reader is on carries `aria-current="step"`, and every step says its number to a screen
    reader ("Step 2 of 3: Genres"), since the visible numerals are decoration. With `action` (the name of a Livewire method that takes
    the number) the steps before the current one are buttons that go back to them; a step ahead never is, because each step has to be
    answered first. Targets are 44px. Colour is the only thing that changes between states, and it fades only where motion is welcome.
--}}
@php
    $steps = array_values($steps);
    $total = count($steps);
@endphp

<nav aria-label="{{ $label ?? __('Steps') }}" {{ $attributes }}>
    <ol class="grid gap-3 sm:gap-6" style="grid-template-columns: repeat({{ max(1, $total) }}, minmax(0, 1fr))">
        @foreach ($steps as $index => $name)
            @php
                $number = $index + 1;
                $isCurrent = $number === (int) $current;
                $isDone = $number < (int) $current;
                $reachable = $isDone && $action !== null;
                $numeral = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
                $spoken = $reachable
                    ? __('Go back to step :number of :total: :name', ['number' => $number, 'total' => $total, 'name' => $name])
                    : __('Step :number of :total: :name', ['number' => $number, 'total' => $total, 'name' => $name]).($isDone ? ' ('.__('done').')' : '');
                $tag = $reachable ? 'button' : 'div';
            @endphp

            <li wire:key="step-{{ $number }}" @if ($isCurrent) aria-current="step" @endif>
                <{{ $tag }}
                    @if ($reachable) type="button" wire:click="{{ $action }}({{ $number }})" @endif
                    @class([
                        'block min-h-11 w-full border-t-2 pt-3 pb-1 text-start motion-safe:transition-colors motion-safe:duration-(--motion-fast) motion-safe:ease-out-bookflix',
                        'border-ink' => $isCurrent || $isDone,
                        'border-hairline' => ! $isCurrent && ! $isDone,
                        'focus-ring rounded-control hover:border-ink-subtle' => $reachable,
                    ])
                >
                    <span class="sr-only">{{ $spoken }}</span>

                    <span aria-hidden="true" class="block">
                        <span data-stepper-number class="flex items-center gap-2 text-legal uppercase tracking-widest tabular-nums {{ $isCurrent ? 'text-ink' : 'text-ink-subtle' }}">
                            {{ $numeral }}
                            @if ($isDone)
                                <flux:icon name="check" variant="micro" class="size-4" />
                            @endif
                        </span>
                        <span @class(['mt-1 block truncate text-body', 'font-medium text-ink' => $isCurrent, 'text-ink-subtle' => ! $isCurrent])>{{ $name }}</span>
                    </span>
                </{{ $tag }}>
            </li>
        @endforeach
    </ol>
</nav>
