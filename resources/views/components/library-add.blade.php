@props(['media', 'variant' => 'secondary', 'iconOnly' => false, 'form' => true])

{{--
    The Add to Library button of a tile that has no controls of its own (<x-hero>, <x-story-summary>). A click raises the `library-add` event with
    the media id: the signed-in layout's <livewire:library-listener> adds the story and confirms with a toast. With `form` (the default) the button is
    also a plain form that posts to `library.store` and comes back, so it still works where scripts do not run; <x-story-summary> turns it off, since its
    card only exists once scripts have cloned it. `iconOnly` is the compact "+" button, which carries the label as its aria-label.
    Library controls are for readers: a guest gets nothing.
--}}
@auth
    @if ($form)
        <form method="POST" action="{{ route('library.store') }}" {{ $attributes }}>
            @csrf
            <input type="hidden" name="media_id" value="{{ $media->id }}">

            @if ($iconOnly)
                <x-cta type="submit" :variant="$variant" icon="plus" @click.prevent="$dispatch('library-add', { id: {{ $media->id }} })" aria-label="{{ __('Add to Library') }}" />
            @else
                <x-cta type="submit" :variant="$variant" icon="plus" @click.prevent="$dispatch('library-add', { id: {{ $media->id }} })">{{ __('Add to Library') }}</x-cta>
            @endif
        </form>
    @elseif ($iconOnly)
        <x-cta :variant="$variant" icon="plus" @click="$dispatch('library-add', { id: {{ $media->id }} })" aria-label="{{ __('Add to Library') }}" class="{{ $attributes->get('class') }}" />
    @else
        <x-cta :variant="$variant" icon="plus" @click="$dispatch('library-add', { id: {{ $media->id }} })" class="{{ $attributes->get('class') }}">{{ __('Add to Library') }}</x-cta>
    @endif
@endauth
