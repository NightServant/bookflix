<?php

namespace App\Enums;

enum CreatorRole: string
{
    case Author = 'author';
    case Artist = 'artist';
    case Illustrator = 'illustrator';
    case Writer = 'writer';
    case Penciller = 'penciller';
    case Inker = 'inker';
    case Colorist = 'colorist';
    case Translator = 'translator';
    case Developer = 'developer';
}
