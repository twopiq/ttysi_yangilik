<?php

namespace Tests\Unit;

use App\Services\Site\AdminPanel\FormParser;
use Tests\TestCase;

class FormParserTest extends TestCase
{
    private const BASE = 'https://institut.uz/admin/news/create';

    private function parser(): FormParser
    {
        return new FormParser;
    }

    public function test_it_reads_fields_actions_and_the_multipart_flag(): void
    {
        $html = <<<'HTML'
            <form method="post" action="/admin/news" enctype="multipart/form-data">
                <input type="hidden" name="_token" value="abc123">
                <input type="text" name="title[uz]" required>
                <textarea name="content[uz]">oldingi matn</textarea>
                <select name="category_id"><option value="">—</option><option value="3" selected>Yangilik</option></select>
                <input type="file" name="rasm">
                <button type="submit" name="save">Saqlash</button>
            </form>
            HTML;

        $form = $this->parser()->forms($html, self::BASE)[0];

        $this->assertSame('POST', $form->method);
        $this->assertSame('https://institut.uz/admin/news', $form->action);
        $this->assertTrue($form->multipart);
        $this->assertSame(
            ['_token', 'title[uz]', 'content[uz]', 'category_id', 'rasm', 'save'],
            $form->fieldNames(),
        );
        $this->assertTrue($form->fields['title[uz]']->required);
        $this->assertSame('oldingi matn', $form->fields['content[uz]']->value);
        $this->assertSame('3', $form->fields['category_id']->value);
        $this->assertSame(['', '3'], $form->fields['category_id']->options);
    }

    /**
     * Nomli tugma yig'iladi (ba'zi panellarda "saqlash" va "saqlab chop etish"
     * bir formada bo'ladi), lekin avtomatik yuborilmaydi.
     */
    public function test_a_named_submit_button_is_listed_but_not_sent(): void
    {
        $html = '<form method="post" action="/x"><input name="a"><button type="submit" name="save">Saqlash</button></form>';

        $form = $this->parser()->forms($html, self::BASE)[0];

        $this->assertTrue($form->has('save'));
        $this->assertFalse($form->fields['save']->isSubmittableValue());
        $this->assertArrayNotHasKey('save', $form->carriedValues());
    }

    /** Yashirin maydonlar va oldindan to'ldirilganlari o'zgarmagan qaytishi kerak. */
    public function test_carried_values_keep_the_csrf_token_and_drop_the_submit_button(): void
    {
        $html = <<<'HTML'
            <form method="post" action="/admin/news">
                <input type="hidden" name="_token" value="abc123">
                <input type="hidden" name="author_id" value="7">
                <input type="text" name="title" value="">
                <input type="file" name="rasm">
                <button type="submit" name="save" value="1">Saqlash</button>
            </form>
            HTML;

        $carried = $this->parser()->forms($html, self::BASE)[0]->carriedValues();

        $this->assertSame(['_token' => 'abc123', 'author_id' => '7'], $carried);
    }

    public function test_a_form_without_a_file_input_is_not_multipart(): void
    {
        $html = '<form method="post" action="save"><input type="text" name="a"></form>';

        $form = $this->parser()->forms($html, self::BASE)[0];

        $this->assertFalse($form->multipart);
        $this->assertSame('https://institut.uz/admin/news/save', $form->action);
    }

    public function test_an_absolute_action_is_left_alone_and_an_empty_one_posts_to_the_page(): void
    {
        $absolute = '<form method="post" action="https://boshqa.uz/x"><input name="a"></form>';
        $empty = '<form method="post"><input name="a"></form>';

        $this->assertSame('https://boshqa.uz/x', $this->parser()->forms($absolute, self::BASE)[0]->action);
        $this->assertSame(self::BASE, $this->parser()->forms($empty, self::BASE)[0]->action);
    }

    public function test_the_login_form_is_the_one_with_a_password_field(): void
    {
        $html = <<<'HTML'
            <form action="/search"><input type="text" name="q"></form>
            <form method="post" action="/admin/login">
                <input type="hidden" name="_token" value="t">
                <input type="email" name="email">
                <input type="password" name="password">
            </form>
            HTML;

        $form = $this->parser()->pickLogin($html, self::BASE);

        $this->assertNotNull($form);
        $this->assertSame('https://institut.uz/admin/login', $form->action);
        $this->assertCount(1, $form->passwordFields());
    }

    public function test_a_page_without_a_password_field_has_no_login_form(): void
    {
        $this->assertNull($this->parser()->pickLogin('<form><input name="q"></form>', self::BASE));
    }

    public function test_pick_prefers_the_action_hint_then_the_form_that_takes_files(): void
    {
        $html = <<<'HTML'
            <form action="/logout" method="post"><input name="_token" type="hidden" value="t"></form>
            <form action="/admin/news/store" method="post" enctype="multipart/form-data">
                <input name="title"><input type="file" name="rasm">
            </form>
            HTML;

        $forms = $this->parser()->forms($html, self::BASE);

        $this->assertSame('https://institut.uz/logout', $this->parser()->pick($forms, 'logout')->action);
        $this->assertSame('https://institut.uz/admin/news/store', $this->parser()->pick($forms)->action);
    }

    /** Admin panellarning HTML'i ko'pincha nuqsonli — parser yiqilmasligi kerak. */
    public function test_malformed_html_does_not_throw(): void
    {
        $html = '<div><form method=post action=/x><input name=a><p>yopilmagan<form></div>';

        $this->assertNotEmpty($this->parser()->forms($html, self::BASE));
    }

    public function test_an_empty_page_yields_no_forms(): void
    {
        $this->assertSame([], $this->parser()->forms('   ', self::BASE));
    }
}
