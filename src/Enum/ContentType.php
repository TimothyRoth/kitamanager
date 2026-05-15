<?php

namespace App\Enum;

enum ContentType: string
{
    case IMAGE = 'image';
    case ARTICLE = 'article';

    public function getGermanLabel(): string
    {
        return match ($this) {
            self::IMAGE => 'Bild',
            self::ARTICLE => 'Artikel',
        };
    }
}
