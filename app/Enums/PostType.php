<?php

namespace App\Enums;

enum PostType: string
{
    case Announcement = 'announcement';
    case News = 'news';

    public function label(): string
    {
        return match ($this) {
            self::Announcement => "E'lon",
            self::News => 'Yangilik',
        };
    }

    /** Saytdagi bo'lim sluggi. */
    public function section(): string
    {
        return (string) config("site.sections.{$this->value}", $this->value);
    }
}
