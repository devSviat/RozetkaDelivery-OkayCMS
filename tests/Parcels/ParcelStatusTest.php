<?php

namespace Modules\Sviat\RozetkaDelivery\Parcels;

require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\RozetkaDelivery\Support\InMemoryState;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\StatusDictionary;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelStatus;
use PHPUnit\Framework\TestCase;

class ParcelStatusTest extends TestCase
{
    /** Група Rozetka → група модуля; 10029 — окремо. */
    private const array EXPECTED = [
        100 => 'created', 200 => 'accepted', 300 => 'in_transit', 400 => 'cancelled', 500 => 'arrived',
        600 => 'refused', 700 => 'refused', 800 => 'refused', 900 => 'returning', 1000 => 'returning', 1100 => 'returning',
        1200 => 'received', 1300 => 'returned', 1400 => 'problem',
    ];

    public static function dictionary(array $extra = []): StatusDictionary
    {
        $groups = json_decode((string)file_get_contents(__DIR__ . '/../Support/fixtures/track-status.json'), true)['data'];
        foreach ($extra as $groupId => $code) {
            foreach ($groups as &$group) {
                if ($group['id'] === $groupId) {
                    $group['statuses'][] = ['id' => $code, 'name' => 'Новий статус'];
                }
            }
            unset($group);
        }
        $dictionary = new StatusDictionary(new InMemoryState());
        $dictionary->store($groups);

        return $dictionary;
    }

    public function testEveryDictionaryCode(): void
    {
        $groups = json_decode((string)file_get_contents(__DIR__ . '/../Support/fixtures/track-status.json'), true)['data'];
        $dictionary = self::dictionary();
        $checked = 0;
        foreach ($groups as $group) {
            foreach ($group['statuses'] as $status) {
                $expected = $status['id'] === 10029 ? 'problem' : self::EXPECTED[$group['id']];
                self::assertSame($expected, ParcelStatus::group($status['id'], $dictionary), (string)$status['id']);
                self::assertSame($expected, ParcelStatus::group((string)$status['id'], $dictionary), 'numeric string');
                self::assertSame($expected, ParcelStatus::group($status['id']), "{$status['id']} without a dictionary");
                $checked++;
            }
        }
        self::assertSame(34, $checked);
    }

    public function testCamelCaseStrings(): void
    {
        $dictionary = self::dictionary();
        self::assertSame('arrived', ParcelStatus::group('readyToIssueOctopus', $dictionary));
        self::assertSame(40040, ParcelStatus::code('readyToIssueOctopus'));
        self::assertSame('created', ParcelStatus::group('planned', $dictionary));
        self::assertSame('received', ParcelStatus::group('gaveOut', $dictionary));
        self::assertSame('problem', ParcelStatus::group('notAcceptedFromReception', $dictionary));
        self::assertSame('refused', ParcelStatus::group('storageDateExpired', $dictionary));
        self::assertSame('returning', ParcelStatus::group('senderPending', $dictionary));
        // Рядки без коду — одразу група.
        self::assertSame('in_transit', ParcelStatus::group('inTheWay', $dictionary));
        self::assertNull(ParcelStatus::code('inTheWay'));
        self::assertSame('arrived', ParcelStatus::group('placedInStore', $dictionary));
        self::assertSame('problem', ParcelStatus::group('wrongReturnLocation', $dictionary));
        self::assertSame('returning', ParcelStatus::group('packingReturn', $dictionary));
        self::assertSame('returning', ParcelStatus::group('readyToReturn', $dictionary));
        self::assertSame('received', ParcelStatus::group('gaveOutForShowcase', $dictionary));
        self::assertSame('cancelled', ParcelStatus::group('toCancel', $dictionary));
    }

    public function testUnknownStringKeepsGroup(): void
    {
        self::assertNull(ParcelStatus::group('somethingNew', self::dictionary()));
        self::assertNull(ParcelStatus::group(99999, self::dictionary()));
        self::assertNull(ParcelStatus::group('', self::dictionary()));
    }

    public function testPartialFlag(): void
    {
        self::assertTrue(ParcelStatus::partial(60025));
        self::assertTrue(ParcelStatus::partial('gaveOutPartially'));
        self::assertFalse(ParcelStatus::partial(60030));
        self::assertSame('received', ParcelStatus::group('gaveOutPartially', self::dictionary()));
    }

    public function testGroupFromRozetkaGroupForNewCode(): void
    {
        self::assertSame('arrived', ParcelStatus::group(40099, self::dictionary([500 => 40099])));
        self::assertNull(ParcelStatus::group(40099), 'without the dictionary a new code is unknown');
    }

    public function testFinalAndFilterGroups(): void
    {
        self::assertSame(['received', 'returned', 'cancelled'], ParcelStatus::FINAL);
        self::assertTrue(ParcelStatus::isFinal('returned'));
        self::assertFalse(ParcelStatus::isFinal('arrived'));
        self::assertSame([...ParcelStatus::GROUPS, 'none'], ParcelStatus::FILTER_GROUPS);
        $groups = ParcelStatus::GROUPS;
        $expected = array_values(array_unique(self::EXPECTED));
        sort($groups);
        sort($expected);
        self::assertSame($expected, $groups);
    }
}
