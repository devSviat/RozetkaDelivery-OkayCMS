<?php

namespace Modules\Sviat\RozetkaDelivery\Payments;

require_once __DIR__ . '/../Support/ArraySettings.php';

use Modules\Sviat\RozetkaDelivery\Support\ArraySettings;
use Okay\Core\EntityFactory;
use Okay\Entities\PaymentsEntity;
use Okay\Modules\Sviat\RozetkaDelivery\Backend\CodSettings;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodConfig;
use Okay\Modules\Sviat\RozetkaDelivery\Payments\CodPayments;
use PHPUnit\Framework\TestCase;

class CodPaymentsTest extends TestCase
{
    /** @var array<int, object> */
    private array $methods = [];

    protected function setUp(): void
    {
        $this->methods = [
            1 => (object)['id' => 1, 'module' => 'null', CodPayments::FIELD => 1],
            2 => (object)['id' => 2, 'module' => 'Sviat/Monobank', CodPayments::FIELD => 1],
            3 => (object)['id' => 3, 'module' => '', CodPayments::FIELD => 0],
        ];
    }

    private function payments(bool $enabled): CodPayments
    {
        $entity = $this->createStub(PaymentsEntity::class);
        $entity->method('get')->willReturnCallback(fn($id) => $this->methods[(int)$id] ?? false);
        $entity->method('noLimit')->willReturnSelf();
        $entity->method('find')->willReturnCallback(fn() => array_values($this->methods));
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($entity);

        return new CodPayments($factory, new CodSettings(new ArraySettings([CodConfig::ENABLED => $enabled ? '1' : '0'])));
    }

    public function testFlagOffMeansNoCod(): void
    {
        self::assertFalse($this->payments(false)->isCod(1));
        self::assertSame([], $this->payments(false)->flagged());
        self::assertTrue($this->payments(true)->isCod(1));
    }

    public function testOnlinePaymentNeverCod(): void
    {
        self::assertFalse($this->payments(true)->isCod(2));
        self::assertFalse($this->payments(true)->isCod(3));
        self::assertFalse($this->payments(true)->isCod(0));
        self::assertSame([1], array_map(static fn(object $m): int => $m->id, $this->payments(true)->flagged()));
    }
}
