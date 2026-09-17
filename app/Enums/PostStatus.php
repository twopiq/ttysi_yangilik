<?php

namespace App\Enums;

enum PostStatus: string
{
    /** Albomning qolgan rasmlari kutilmoqda. */
    case Collecting = 'collecting';
    /** Tarjima navbatda / jarayonda. */
    case Translating = 'translating';
    /** Tarjima tayyor, moderator ko'rishi kerak. */
    case Review = 'review';
    /** Moderator tasdiqladi, saytga yuborish navbatda. */
    case Approved = 'approved';
    /** Saytda chop etildi. */
    case Published = 'published';
    /** Moderator rad etdi. */
    case Rejected = 'rejected';
    /** Tarjima yoki nashr xato bilan tugadi. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Collecting => 'Yig\'ilmoqda',
            self::Translating => 'Tarjima qilinmoqda',
            self::Review => 'Tekshiruvda',
            self::Approved => 'Tasdiqlangan',
            self::Published => 'Chop etilgan',
            self::Rejected => 'Rad etilgan',
            self::Failed => 'Xato',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Review => 'badge-review',
            self::Published => 'badge-ok',
            self::Approved => 'badge-ok',
            self::Failed, self::Rejected => 'badge-bad',
            default => 'badge-muted',
        };
    }
}
