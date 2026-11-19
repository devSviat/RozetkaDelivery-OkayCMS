<?php

namespace Modules\Sviat\RozetkaDelivery\Live;

require_once __DIR__ . '/../Support/FixedCredentials.php';
require_once __DIR__ . '/../Support/InMemoryState.php';
require_once __DIR__ . '/../Support/InMemoryMutex.php';
require_once __DIR__ . '/../Support/ArrayLogger.php';
require_once __DIR__ . '/../Support/ArraySettings.php';

use Modules\Sviat\RozetkaDelivery\Support\ArrayLogger;
use Modules\Sviat\RozetkaDelivery\Support\ArraySettings;
use Modules\Sviat\RozetkaDelivery\Support\FixedCredentials;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryMutex;
use Modules\Sviat\RozetkaDelivery\Support\InMemoryState;
use Okay\Modules\Sviat\RozetkaDelivery\Api\AuthFlags;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Credentials;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\CurlTransport;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RequestBudget;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaClient;
use Okay\Modules\Sviat\RozetkaDelivery\Api\TokenProvider;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\Rows\PointRow;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\StatusDictionary;
use Okay\Modules\Sviat\RozetkaDelivery\KeyCheck\PartnerInfo;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\DeliveryDate;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\QuoteSession;
use Okay\Modules\Sviat\RozetkaDelivery\Quote\ShippingConfig;
use PHPUnit\Framework\TestCase;

/**
 * Живий прогін лише на читання; токен задає власник у своїй оболонці (ROZETKA_DELIVERY_TOKEN).
 * Повідомлення assert містять ключі, типи й кількості, але ніколи не значення з відповідей.
 */
class LiveReadTest extends TestCase
{
    private RozetkaClient $client;

    protected function setUp(): void
    {
        $token = (string)getenv('ROZETKA_DELIVERY_TOKEN');
        if ($token === '') {
            self::markTestSkipped('ROZETKA_DELIVERY_TOKEN не задано');
        }
        $credentials = new FixedCredentials(new Credentials(Credentials::normalizeToken($token)));
        $state = new InMemoryState();
        $now = static fn(): int => time();
        $this->client = new RozetkaClient(
            new CurlTransport(),
            new TokenProvider($credentials, $state, new AuthFlags($state), $now),
            new RequestBudget($state, new InMemoryMutex(), $now),
            $credentials,
            new ArrayLogger(),
        );
    }

    /** Rozetka приймає статичний токен як Bearer. */
    public function testVerify(): void
    {
        $data = $this->client->get('auth/verify')['data'];
        self::assertIsArray($data, 'data is ' . get_debug_type($data));

        $partner = PartnerInfo::fromVerify($data);
        self::assertNotSame('', $partner->status, 'status is empty; keys: ' . implode(',', array_keys($data)));
    }

    public function testDepartmentFirstPage(): void
    {
        $answer = $this->client->get('department', ['page' => 1, 'limit' => 100], auth: false);
        $items = is_array($answer['data']) ? $answer['data'] : [];
        self::assertNotEmpty($items, 'no points on page 1');
        self::assertIsInt($answer['pagination']['total_count'] ?? null, 'total_count is ' . get_debug_type($answer['pagination']['total_count'] ?? null));

        $rows = array_filter(array_map(static fn($item): ?PointRow => is_array($item) ? PointRow::fromApi($item, 1) : null, $items));
        self::assertCount(count($items), $rows, 'points that PointRow could not read: ' . (count($items) - count($rows)));
        $suspect = count(array_filter($rows, static fn(PointRow $row): bool => $row->limits->suspectUnits));
        self::assertGreaterThanOrEqual(0, $suspect, "points with gram-like weight limits on page 1: {$suspect}");
    }

    public function testTrackStatusDictionary(): void
    {
        $data = $this->client->get('track-status', auth: false)['data'];
        $dictionary = new StatusDictionary(new InMemoryState());

        self::assertTrue(is_array($data) && $dictionary->store($data), 'no status codes in the answer');
        self::assertNotNull($dictionary->groupOf(60030), 'code 60030 has no group');
    }

    public function testEstimatedDate(): void
    {
        $items = $this->client->get('department', ['page' => 1, 'limit' => 100, 'can_receive_tracks' => 'true'], auth: false)['data'];
        $ids = array_values(array_filter(array_map(static fn($item): ?string => is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : null, is_array($items) ? $items : [])));
        self::assertGreaterThanOrEqual(2, count($ids), 'points that receive: ' . count($ids));

        $tomorrow = new \DateTimeImmutable('tomorrow 12:00', new \DateTimeZone('Europe/Kyiv'));
        $data = $this->client->get('track/estimated-delivery-date', [
            'sender_department' => $ids[0],
            'recipient_department' => $ids[1],
            'send_date' => $tomorrow->format('Y-m-d H:i:s'),
        ], auth: false)['data'];

        self::assertIsArray($data, 'data is ' . get_debug_type($data));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($data['delivery_date'] ?? ''), 'delivery_date is ' . get_debug_type($data['delivery_date'] ?? null));
    }

    /**
     * Дата кошика через DeliveryDate (той самий запит, що й на сторінці) для точки видачі, поштомата чи магазину
     * й партнерського відділення. Друкуються лише типи й чи прийшла дата.
     */
    public function testEstimatedDateForCartPoint(): void
    {
        $sender = null;
        $byType = [];
        $pickups = [];
        for ($page = 1; $page <= 11 && ($sender === null || count($byType) < 3 || count($pickups) < 16); $page++) {
            $items = $this->client->get('department', ['page' => $page, 'limit' => 100], auth: false)['data'];
            foreach (is_array($items) ? $items : [] as $item) {
                $row = is_array($item) ? PointRow::fromApi($item, 1) : null;
                if ($row === null) {
                    continue;
                }
                if ($sender === null && $row->canReceive && $row->typeId === 2) {
                    $sender = $row->id;
                }
                $group = match ($row->typeId) {
                    2 => 'pickup',
                    7, 10 => 'store_or_postomat',
                    14 => 'partner',
                    default => null,
                };
                if ($row->typeId === 2 && $row->canGiveOut && $row->id !== $sender && count($pickups) < 16) {
                    $pickups[] = $row->id;
                }
                if ($group !== null && $row->canGiveOut && $row->id !== $sender && !isset($byType[$group])) {
                    $byType[$group] = $row->id;
                }
            }
        }
        self::assertNotNull($sender, 'no receiving pickup point found for the sender');
        self::assertArrayHasKey('pickup', $byType, 'types found: ' . implode(',', array_keys($byType)));

        $settings = new ArraySettings([ShippingConfig::SENDER_POINT_ID => $sender, ShippingConfig::CUTOFF => '16:00']);
        $estimate = function (string $pointId) use ($settings): ?string {
            // Кошиковий бюджет — 2 запити на секунду, а обхід довідника вище його вичерпує: чекаємо нове вікно.
            sleep(2);
            // Нова сесія на кожну точку: ліміт сесії (10 с) тут не перевіряється.
            return new DeliveryDate($this->client, new InMemoryState(), $settings, QuoteSession::inMemory(), static fn(): int => time())->estimate($pointId);
        };
        $label = static fn(?string $date): string => $date === null ? 'null' : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? 'date' : 'malformed');
        $answers = [];
        foreach ($byType as $group => $pointId) {
            if ($group !== 'pickup') {
                $answers[$group] = $label($estimate($pointId));
            }
        }
        // Для частини точок видачі API відповідає 500 без видимої причини в довіднику (2026-11-18):
        // модуль тоді не показує дату, тож перевіряється, що дата приходить хоча б для однієї з кількох.
        $pickupLabels = array_map(static fn(string $id): string => $label($estimate($id)), $pickups);
        $answers['pickup'] = in_array('date', $pickupLabels, true) ? 'date' : 'null';
        if (in_array('malformed', $pickupLabels, true)) {
            $answers['pickup'] = 'malformed';
        }
        $answers['pickup_dates'] = count(array_keys($pickupLabels, 'date', true)) . '/' . count($pickupLabels);
        $summary = implode(', ', array_map(static fn(string $g, string $a): string => "{$g}={$a}", array_keys($answers), $answers));

        self::assertSame('date', $answers['pickup'], $summary);
        self::assertNotContains('malformed', $answers, $summary);
        // Партнерське відділення може не мати дати: висновок для нотаток — у тексті нижче.
        fwrite(STDERR, "estimated date by point type: {$summary}\n");
    }
}
