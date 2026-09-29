<?php

namespace App\Services\Site\AdminPanel;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * HTML sahifadan formalarni ajratib oladi.
 *
 * `dom` kengaytmasi ishlatiladi — qo'shimcha paket kerak emas. Admin
 * panellarining HTML'i har xil bo'lgani uchun parser bag'rikeng: noto'g'ri
 * yopilgan teglar libxml ogohlantirishlarini bermaydi, shunchaki o'tkazib
 * yuboriladi.
 */
class FormParser
{
    /**
     * @return array<int, HtmlForm>
     */
    public function forms(string $html, string $baseUrl): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $forms = [];

        foreach ($xpath->query('//form') ?: [] as $element) {
            if ($element instanceof DOMElement) {
                $forms[] = $this->form($element, $xpath, $baseUrl);
            }
        }

        return $forms;
    }

    /**
     * Kerakli formani tanlaydi.
     *
     * Avval action'i ishoraga mos kelgani, so'ng fayl kiritishi bori (yangilik
     * formasida deyarli har doim rasm bo'ladi), oxirida maydoni eng ko'pi.
     *
     * @param  array<int, HtmlForm>  $forms
     */
    public function pick(array $forms, ?string $actionHint = null): ?HtmlForm
    {
        if ($forms === []) {
            return null;
        }

        if (filled($actionHint)) {
            foreach ($forms as $form) {
                if (Str::contains($form->action, $actionHint)) {
                    return $form;
                }
            }
        }

        $withFiles = array_filter($forms, fn (HtmlForm $form) => $form->multipart);

        $candidates = $withFiles !== [] ? $withFiles : $forms;

        usort($candidates, fn (HtmlForm $a, HtmlForm $b) => count($b->fields) <=> count($a->fields));

        return $candidates[0];
    }

    /** Parol maydoni bor forma — kirish formasi. */
    public function pickLogin(string $html, string $baseUrl): ?HtmlForm
    {
        foreach ($this->forms($html, $baseUrl) as $form) {
            if ($form->passwordFields() !== []) {
                return $form;
            }
        }

        return null;
    }

    private function form(DOMElement $element, DOMXPath $xpath, string $baseUrl): HtmlForm
    {
        $fields = [];

        foreach ($xpath->query('.//input | .//textarea | .//select | .//button', $element) ?: [] as $input) {
            if (! $input instanceof DOMElement) {
                continue;
            }

            $name = $input->getAttribute('name');

            if ($name === '') {
                continue;
            }

            $field = $this->field($input, $xpath);

            // Bir nechta checkbox bir nomda bo'lishi mumkin — birinchisi yetarli.
            $fields[$name] ??= $field;
        }

        $enctype = Str::lower($element->getAttribute('enctype'));

        return new HtmlForm(
            action: $this->resolve($element->getAttribute('action'), $baseUrl),
            method: Str::upper($element->getAttribute('method') ?: 'GET'),
            fields: $fields,
            multipart: $enctype === 'multipart/form-data'
                || array_any($fields, fn (HtmlField $field) => $field->isFile()),
        );
    }

    private function field(DOMElement $input, DOMXPath $xpath): HtmlField
    {
        $tag = Str::lower($input->tagName);

        $type = match ($tag) {
            'textarea' => 'textarea',
            'select' => 'select',
            'button' => Str::lower($input->getAttribute('type')) ?: 'submit',
            default => Str::lower($input->getAttribute('type')) ?: 'text',
        };

        $value = match ($tag) {
            'button' => $input->getAttribute('value') ?: trim($input->textContent),
            'textarea' => $input->textContent,
            'select' => $this->selectedOption($input, $xpath),
            default => $input->getAttribute('value'),
        };

        return new HtmlField(
            name: $input->getAttribute('name'),
            tag: $tag,
            type: $type,
            value: $value === '' ? null : $value,
            required: $input->hasAttribute('required'),
            options: $tag === 'select' ? $this->options($input, $xpath) : [],
        );
    }

    /** @return array<int, string> */
    private function options(DOMElement $select, DOMXPath $xpath): array
    {
        $options = [];

        foreach ($xpath->query('.//option', $select) ?: [] as $option) {
            if ($option instanceof DOMElement) {
                $options[] = $this->optionValue($option);
            }
        }

        return $options;
    }

    private function selectedOption(DOMElement $select, DOMXPath $xpath): string
    {
        foreach ($xpath->query('.//option[@selected]', $select) ?: [] as $option) {
            if ($option instanceof DOMElement) {
                return $this->optionValue($option);
            }
        }

        return '';
    }

    /**
     * HTML qoidasi: `value` atributi bo'lsa — o'sha, bo'lmasa option matni.
     * Bo'sh `value=""` esa "tanlanmagan" degani, matnga almashtirilmaydi.
     */
    private function optionValue(DOMElement $option): string
    {
        return $option->hasAttribute('value')
            ? $option->getAttribute('value')
            : trim($option->textContent);
    }

    /** Nisbiy action'ni absolyut manzilga aylantiradi. */
    private function resolve(string $action, string $baseUrl): string
    {
        if ($action === '') {
            return $baseUrl;
        }

        if (Str::startsWith($action, ['http://', 'https://'])) {
            return $action;
        }

        $parts = parse_url($baseUrl);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (Str::startsWith($action, '/')) {
            return $origin.$action;
        }

        $directory = rtrim(dirname($parts['path'] ?? '/'), '/');

        return "{$origin}{$directory}/{$action}";
    }
}
