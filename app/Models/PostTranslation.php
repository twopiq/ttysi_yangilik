<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostTranslation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['edited_by_human' => 'boolean'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function localeName(): string
    {
        return (string) config("translation.locale_names.{$this->locale}", $this->locale);
    }
}
