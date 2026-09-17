<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Enums\PostType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => PostType::class,
            'status' => PostStatus::class,
            'posted_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(PostTranslation::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('position');
    }

    public function translation(string $locale): ?PostTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    public function cover(): ?PostMedia
    {
        return $this->media->firstWhere('is_cover', true) ?? $this->media->first();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => PostStatus::Failed,
            'last_error' => mb_substr($message, 0, 2000),
        ])->save();
    }
}
