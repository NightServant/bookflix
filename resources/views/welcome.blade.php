@props(['books' => [], 'covers' => [], 'topTen' => []])

<x-layouts::landing
    :title="__('Your Library. Every Story.')"
    :description="__('Bookflix keeps every comic, manga, novel and webcomic you own, read and love on one calm shelf, filed the way a library would.')"
>
    <x-landing.hero :books="$books" />
    <x-landing.top-ten :items="$topTen" />
</x-layouts::landing>
