<?php

namespace App\Services\Site\AdminPanel;

/** Sahifadan ajratib olingan HTML forma. */
class HtmlForm
{
    /**
     * @param  array<string, HtmlField>  $fields
     */
    public function __construct(
        public readonly string $action,
        public readonly string $method,
        public readonly array $fields,
        public readonly bool $multipart = false,
    ) {}

    public function has(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    /** @return array<int, HtmlField> */
    public function passwordFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (HtmlField $field) => $field->type === 'password',
        ));
    }

    /**
     * Formaning o'zidagi qiymatlar: yashirin maydonlar (shu jumladan Laravel
     * `_token`) va oldindan to'ldirilgan qiymatlar. Ular o'zgarmagan holda
     * qaytarilishi kerak, aks holda sayt so'rovni rad etadi.
     *
     * @return array<string, string>
     */
    public function carriedValues(): array
    {
        $values = [];

        foreach ($this->fields as $field) {
            if (! $field->isSubmittableValue()) {
                continue;
            }

            if ($field->isHidden() || ($field->value !== null && $field->value !== '')) {
                $values[$field->name] = (string) $field->value;
            }
        }

        return $values;
    }

    /** @return array<int, string> */
    public function fieldNames(): array
    {
        return array_keys($this->fields);
    }
}
