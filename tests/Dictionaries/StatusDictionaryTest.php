<?php

namespace Modules\Sviat\RozetkaDelivery\Dictionaries;

require_once __DIR__ . '/../Support/InMemoryState.php';

use Modules\Sviat\RozetkaDelivery\Support\InMemoryState;
use Okay\Modules\Sviat\RozetkaDelivery\Dictionaries\StatusDictionary;
use PHPUnit\Framework\TestCase;

class StatusDictionaryTest extends TestCase
{
    private function loaded(InMemoryState $state = new InMemoryState()): StatusDictionary
    {
        $body = json_decode((string)file_get_contents(__DIR__ . '/../Support/fixtures/track-status.json'), true);
        $dictionary = new StatusDictionary($state);
        $dictionary->store($body['data']);

        return $dictionary;
    }

    public function testCodeToGroup(): void
    {
        $state = new InMemoryState();
        $this->loaded($state);
        $dictionary = new StatusDictionary($state);

        self::assertSame(500, $dictionary->groupOf(40040));
        self::assertSame('Готово до видачі', $dictionary->name(40040));
        self::assertSame(1200, $dictionary->groupOf(60025));
        self::assertSame(200, $dictionary->groupOf(10029));
        self::assertSame(400, $dictionary->groupOf(60010));
        self::assertSame('Видано', $dictionary->groupName(1200));
        self::assertTrue($dictionary->mainFlow(1200));
        self::assertFalse($dictionary->mainFlow(1300));
        self::assertSame(34, $dictionary->count());
    }

    public function testUnknownCodeNull(): void
    {
        $dictionary = $this->loaded();

        self::assertNull($dictionary->groupOf(99999));
        self::assertNull($dictionary->name(99999));
        self::assertNull(new StatusDictionary(new InMemoryState())->groupOf(40040));
    }

    public function testGarbageIsSkippedAndEmptyAnswerKeepsStored(): void
    {
        $state = new InMemoryState();
        $dictionary = $this->loaded($state);

        self::assertFalse($dictionary->store([]));
        self::assertSame(500, $dictionary->groupOf(40040));
        self::assertTrue($dictionary->store([['id' => 'x'], ['id' => 100, 'group_name' => '<b>', 'statuses' => [['id' => 10010, 'name' => 'Заплановано'], 'junk']]]));
        self::assertSame(100, $dictionary->groupOf(10010));
        self::assertNull($dictionary->groupOf(40040));
    }
}
