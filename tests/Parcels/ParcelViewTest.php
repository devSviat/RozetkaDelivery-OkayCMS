<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

require_once __DIR__ . '/../Support/DraftFixtures.php';

use Modules\Sviat\RozetkaDelivery\Support\DraftFixtures as F;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelView;
use PHPUnit\Framework\TestCase;

/** Точка у формі нової ЕН — як у кошику: адреса лише тоді, коли назва її ще не містить. */
class ParcelViewTest extends TestCase
{
    public function testAddressAlreadyInTitleIsNotRepeated(): void
    {
        self::assertSame('№10001, вул. Тестова, 1', ParcelView::pointLine((object)['title' => '№10001, вул. Тестова, 1', 'address' => 'вул. Тестова, 1']));
    }

    public function testAddressAddedWhenTitleLacksIt(): void
    {
        self::assertSame('Поштомат 7, вул. Вигадана, 3', ParcelView::pointLine((object)['title' => 'Поштомат 7', 'address' => 'вул. Вигадана, 3']));
    }

    public function testEmptyPartsSkipped(): void
    {
        self::assertSame('Поштомат 7', ParcelView::pointLine((object)['title' => ' Поштомат 7 ', 'address' => '']));
        self::assertSame('вул. Вигадана, 3', ParcelView::pointLine((object)['title' => '', 'address' => 'вул. Вигадана, 3']));
        self::assertSame('', ParcelView::pointLine(null));
    }

    public function testFormShowsPointOnce(): void
    {
        $draft = F::draft(['point' => F::point(['title' => '№10001, вул. Тестова, 1', 'address' => 'вул. Тестова, 1'])]);

        $view = ParcelView::build(77, null, true, static fn() => $draft, [], 1_800_000_000);

        self::assertSame('№10001, вул. Тестова, 1', $view['form']['point']);
    }
}
