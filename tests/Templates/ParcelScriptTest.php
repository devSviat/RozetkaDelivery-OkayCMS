<?php

namespace Modules\Sviat\RozetkaDelivery\Templates;

use PHPUnit\Framework\TestCase;

/** Помилка дії з ЕН: менеджер має побачити текст сервера, а не лише загальне «Не вдалося». */
class ParcelScriptTest extends TestCase
{
    private static function script(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Backend/design/js/parcel.js');
    }

    private static function showIssues(): string
    {
        $script = self::script();
        $start = (int)strpos($script, 'function showIssues(');
        $end = (int)strpos($script, "\n    }\n", $start);

        return substr($script, $start, $end - $start);
    }

    public function testToastCarriesServerTextWithGenericFallback(): void
    {
        self::assertMatchesRegularExpression("/toast\\('error', toastText\\(root, issues\\), root\\.getAttribute\\('data-toast-error'\\)\\)/", self::showIssues());
        self::assertMatchesRegularExpression("/function toastText\\(root, issues\\) \\{[^}]*issue\\.text[^}]*\\}[^}]*data-failed/s", self::script());
    }

    /** Toastr за замовчуванням вставляє текст як HTML; текст Rozetka мусить пройти екранованим. */
    public function testToastEscapesHtml(): void
    {
        self::assertMatchesRegularExpression('/window\.toastr\[kind\]\(text, title, \{ escapeHtml: true \}\)/', self::script());
    }

    /** Блок прив'язки прихований: помилка етикетки чи оновлення в ньому нікому не видна. */
    public function testDefaultIssueBoxIsNotTheHiddenAttachOne(): void
    {
        self::assertStringContainsString("root.querySelector('.rz-parcel__issues:not(.rz-parcel__issues--attach)')", self::showIssues());
    }

    /** Один обробник для списку й картки; окремого копіювання картки (кнопка з title) більше немає. */
    public function testOneCopyHandlerForListAndCard(): void
    {
        $script = self::script();

        self::assertStringContainsString("event.target.closest('.fn_rz_copy')", $script);
        self::assertStringNotContainsString('fn_rz_parcel_copy', $script);
        self::assertStringNotContainsString('fn_rz_list_copy', $script);
    }
}
