<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PostMedia extends Model
{
    protected $table = 'post_media';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_cover' => 'boolean'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function isDownloaded(): bool
    {
        return $this->path !== null && Storage::disk('local')->exists($this->path);
    }

    public function contents(): ?string
    {
        return $this->isDownloaded() ? Storage::disk('local')->get($this->path) : null;
    }

    public function filename(): string
    {
        return $this->path ? basename($this->path) : "{$this->file_unique_id}.bin";
    }
}
