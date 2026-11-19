<?php

namespace Modules\Sviat\RozetkaDelivery\Quote;

require_once __DIR__ . '/../Support/ApiWorld.php';
require_once __DIR__ . '/../Support/ArraySettings.php';

use Modules\Sviat\RozetkaDelivery\Support\ApiWorld;
use Modules\Sviat\RozetkaDelivery\Support\ArraySettings;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\TransportException;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\DeliveryDate;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\QuoteSession;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

class DeliveryDateTest extends TestCase
{
    private const string SENDER = 'aaaaaaaa-0000-4000-8000-0000000000ff';
    private const string POINT_A = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const string POINT_B = 'aaaaaaaa-0000-4000-8000-000000000002';

    private ApiWorld $world;
    private QuoteSession $session;

    protected function setUp(): void
    {
        $this->world = new ApiWorld();
        // 10:00 за Києвом, до години відправки.
        $this->world->now = new \DateTimeImmutable('2026-11-18 10:00', new \DateTimeZone('Europe/Kyiv'))->getTimestamp();
        $this->session = QuoteSession::inMemory();
    }

    private function date(array $settings = [ShippingConfig::SENDER_POINT_ID => self::SENDER], ?QuoteSession $session = null): DeliveryDate
    {
        return new DeliveryDate($this->world->client(), $this->world->state, new ArraySettings($settings), $session ?? $this->session, $this->world->clock());
    }

    private function answer(string $date = '2026-11-21 14:00:00'): void
    {
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['delivery_date' => $date, 'delivery_text' => null]]);
    }

    public function testAsksWithSenderPointAndSendingDate(): void
    {
        $this->answer();

        self::assertSame('2026-11-21', $this->date()->estimate(self::POINT_A));
        $request = $this->world->http->requests()[0];
        self::assertSame('GET', $request->method);
        parse_str((string)parse_url($request->url, PHP_URL_QUERY), $query);
        self::assertSame(['sender_department' => self::SENDER, 'recipient_department' => self::POINT_A, 'send_date' => '2026-11-18 16:00:00'], $query);
        self::assertStringStartsWith('https://rz-delivery.rozetka.ua/api/track/estimated-delivery-date?', $request->url);
        self::assertArrayNotHasKey('Authorization', $request->headers, 'a public method goes without the token');
    }

    public function testCachedPerPointAndDay(): void
    {
        $this->answer();
        self::assertSame('2026-11-21', $this->date()->estimate(self::POINT_A));

        $this->world->now += 60;
        self::assertSame('2026-11-21', $this->date(session: QuoteSession::inMemory())->estimate(self::POINT_A), 'another visitor gets the cached date');
        self::assertCount(1, $this->world->http->requests());

        $this->world->now += 3600;
        $this->answer('2026-11-22 14:00:00');
        self::assertSame('2026-11-22', $this->date(session: QuoteSession::inMemory())->estimate(self::POINT_A), 'an hour later the date is asked again');
        self::assertCount(2, $this->world->http->requests());
    }

    public function testSessionThrottle(): void
    {
        $this->answer();
        self::assertSame('2026-11-21', $this->date()->estimate(self::POINT_A));

        $this->world->now += 5;
        self::assertNull($this->date()->estimate(self::POINT_B), 'second request of the session within 10 s stays off the network');
        self::assertSame('2026-11-21', $this->date()->estimate(self::POINT_A), 'a cached date is still served');
        self::assertCount(1, $this->world->http->requests());

        $this->world->now += 5;
        $this->answer('2026-11-23 14:00:00');
        self::assertSame('2026-11-23', $this->date()->estimate(self::POINT_B));
        self::assertCount(2, $this->world->http->requests());
    }

    public function testApiFailureGivesNull(): void
    {
        $this->world->http->push(new TransportException('timeout'));
        self::assertNull($this->date()->estimate(self::POINT_A));

        $this->world->now += 11;
        $this->world->http->json(429, ['statusCode' => 429, 'message' => 'Too Many Requests']);
        self::assertNull($this->date()->estimate(self::POINT_A));

        $this->world->now += 11;
        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['delivery_date' => null, 'delivery_text' => 'щось']]);
        self::assertNull($this->date()->estimate(self::POINT_A), 'no date in the answer');

        self::assertNull($this->date([])->estimate(self::POINT_A), 'no sender point: nothing to ask');
        self::assertNull($this->date()->estimate('not-a-uuid'));
        self::assertCount(3, $this->world->http->requests());
    }

    public function testPastDateRetriedTomorrowOnce(): void
    {
        $past = ['statusCode' => 400, 'message' => 'Помилка валідації даних', 'details' => [['property' => 'send_date', 'constraints' => ['IsDateGreaterThanNow' => 'Дата відвантаження не може бути раніше поточної дати']]]];
        $this->world->http->json(400, $past);
        $this->answer('2026-11-22 14:00:00');

        self::assertSame('2026-11-22', $this->date()->estimate(self::POINT_A));
        $requests = $this->world->http->requests();
        self::assertCount(2, $requests);
        self::assertStringContainsString('send_date=2026-11-19', urldecode($requests[1]->url));

        $this->world->now += 11;
        $this->world->http->json(400, $past)->json(400, $past);
        self::assertNull($this->date(session: QuoteSession::inMemory())->estimate(self::POINT_B), 'retried once, not forever');
        self::assertCount(4, $this->world->http->requests());
    }

    /** Оформлення не чекає на Rozetka: бере лише дату, яку кошик уже отримав. */
    public function testCheckoutReadsCacheOnly(): void
    {
        self::assertNull($this->date()->cached(self::POINT_A));
        self::assertSame([], $this->world->http->requests());

        $this->answer();
        $this->date()->estimate(self::POINT_A);
        self::assertSame('2026-11-21', $this->date(session: QuoteSession::inMemory())->cached(self::POINT_A));
        self::assertCount(1, $this->world->http->requests());
    }

    /** Частину точок API стабільно не рахує (500): відмову пам'ятаємо годину, щоб кошик не питав щоразу. */
    public function testPointWithoutDateNotAskedAgainForAnHour(): void
    {
        $this->world->http->json(500, ['statusCode' => 500, 'message' => 'Internal server error']);
        self::assertNull($this->date()->estimate(self::POINT_A));

        $this->world->now += 60;
        self::assertNull($this->date(session: QuoteSession::inMemory())->estimate(self::POINT_A), 'another visitor gets the remembered refusal');
        self::assertCount(1, $this->world->http->requests());

        $this->world->http->json(200, ['statusCode' => 0, 'data' => ['delivery_date' => null, 'delivery_text' => 'щось']]);
        self::assertNull($this->date(session: QuoteSession::inMemory())->estimate(self::POINT_B));
        $this->world->now += 60;
        self::assertNull($this->date(session: QuoteSession::inMemory())->estimate(self::POINT_B), 'an answer without a date is remembered too');
        self::assertCount(2, $this->world->http->requests());

        $this->world->now += 3600;
        $this->answer();
        self::assertSame('2026-11-21', $this->date(session: QuoteSession::inMemory())->estimate(self::POINT_A), 'an hour later the point is asked again');
        self::assertCount(3, $this->world->http->requests());
    }

    /** Таймаут і 429 — тимчасові: наступний відвідувач питає знову. */
    public function testTransientFailureNotRemembered(): void
    {
        $this->world->http->push(new TransportException('timeout'));
        self::assertNull($this->date()->estimate(self::POINT_A));

        $this->world->now += 60;
        $this->answer();
        self::assertSame('2026-11-21', $this->date(session: QuoteSession::inMemory())->estimate(self::POINT_A));
        self::assertCount(2, $this->world->http->requests());
    }
}
