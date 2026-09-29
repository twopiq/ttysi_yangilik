<?php

namespace App\Services\Site\AdminPanel;

/** HTML formadagi bitta maydon. */
class HtmlField
{
    /**
     * @param  array<int, string>  $options  select uchun mumkin bo'lgan qiymatlar
     */
    public function __construct(
        public readonly string $name,
        public readonly string $tag,
        public readonly string $type,
        public readonly ?string $value = null,
        public readonly bool $required = false,
        public readonly array $options = [],
    ) {}

    public function isHidden(): bool
    {
        return $this->type === 'hidden';
    }

    public function isFile(): bool
    {
        return $this->type === 'file';
    }

    /** Yuborishga yaramaydigan maydonlar: tugmalar va fayl kiritish. */
    public function isSubmittableValue(): bool
    {
        return ! $this->isFile() && ! in_array($this->type, ['submit', 'button', 'reset', 'image'], true);
    }

    public function describe(): string
    {
        $parts = ["{$this->tag}/{$this->type}"];

        if ($this->required) {
            $parts[] = 'majburiy';
        }

        if ($this->options !== []) {
            $parts[] = 'qiymatlar: '.implode(', ', array_slice($this->options, 0, 6));
        } elseif ($this->value !== null && $this->value !== '') {
            $parts[] = 'qiymat: '.mb_strimwidth($this->value, 0, 40, '…');
        }

        return implode(' · ', $parts);
    }
}
