{{--
    Profile strength, from the profile page's `strength` list (included twice: after the header on a phone, in the rail on a wide screen;
    the copy that is not shown is display:none, so it is never read twice). Six steps on a meter, each done or a link to where to do it.
--}}
<section aria-labelledby="strength-title-{{ $where ?? 'main' }}" data-section="strength" class="rounded-card border border-hairline bg-surface-deep p-5">
    <h2 id="strength-title-{{ $where ?? 'main' }}" class="text-lead text-ink">{{ __('Profile strength') }}</h2>

    <div aria-hidden="true" class="mt-3 grid grid-cols-6 gap-1.5">
        @foreach ($strength as $step)
            <span @class(['h-1 rounded-full', 'bg-ink' => $loop->index < $done, 'bg-surface-card' => $loop->index >= $done])></span>
        @endforeach
    </div>

    <p class="mt-3 text-meta text-ink-subtle">
        {{ __(':level · :done of :total done.', ['level' => $level, 'done' => $done, 'total' => count($strength)]) }}
        @if ($done < count($strength))
            {{ __('A fuller profile gives you better picks on Home.') }}
        @endif
    </p>

    <ul class="mt-2">
        @foreach ($strength as $step)
            @if ($step['done'])
                <li data-done class="flex min-h-11 items-center gap-2.5 text-meta text-ink-muted">
                    <flux:icon name="check-circle" class="size-5 shrink-0 text-success" />
                    <span><span class="sr-only">{{ __('Done:') }} </span>{{ $step['label'] }}</span>
                </li>
            @else
                <li>
                    <a href="{{ $step['href'] }}" wire:navigate class="focus-ring flex min-h-11 items-center gap-2.5 rounded-control text-meta text-link hover:underline">
                        <span aria-hidden="true" class="size-5 shrink-0 rounded-full border-[1.5px] border-ink-muted"></span>
                        {{ $step['label'] }}
                    </a>
                </li>
            @endif
        @endforeach
    </ul>
</section>
