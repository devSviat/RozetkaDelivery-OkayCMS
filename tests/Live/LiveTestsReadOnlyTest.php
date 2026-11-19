<?php

namespace Modules\Sviat\RozetkaDelivery\Live;

use PHPUnit\Framework\TestCase;

/**
 * Живі тести ходять у справжній ROZETKA Delivery з токеном власника. Вони не повинні містити запису,
 * входу в кабінет, токенів, реєстрів, білінгу чи друку: один такий рядок створить ЕН або токен за договором.
 */
class LiveTestsReadOnlyTest extends TestCase
{
    private const array FORBIDDEN = ['->post(', '->patch(', '->delete(', 'withBearer', 'auth/login', 'static-token', 'reception/', 'billing/', 'track/label'];

    public function testLiveTestsOnlyRead(): void
    {
        $files = array_filter(glob(__DIR__ . '/*.php') ?: [], static fn(string $file): bool => $file !== __FILE__);
        self::assertNotEmpty($files, 'there is at least one live test');

        $offenders = [];
        foreach ($files as $file) {
            foreach (self::offences((string)file_get_contents($file)) as $offence) {
                $offenders[] = basename($file) . ': ' . $offence;
            }
        }

        self::assertSame([], $offenders);
    }

    public function testDetectorSeesForbiddenCalls(): void
    {
        self::assertSame(['->post(', 'auth/login'], self::offences('<?php $c->post(\'auth/login\', []);'));
        self::assertSame(['->delete(', 'static-token'], self::offences('<?php $c->delete("partner/static-token/1");'));
        self::assertSame([], self::offences('<?php $c->get(\'department\', [], auth: false);'));
    }

    /** @return list<string> */
    private static function offences(string $source): array
    {
        return array_values(array_filter(self::FORBIDDEN, static fn(string $needle): bool => stripos($source, $needle) !== false));
    }
}
