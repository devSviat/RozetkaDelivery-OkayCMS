<?php

namespace Modules\Sviat\RozetkaDelivery\Controllers;

use PHPUnit\Framework\TestCase;

/** Фронтові контролери не дістаються до замовлень через адмінські хелпери. */
class NoAdminImportsTest extends TestCase
{
    public function testControllersDoNotImportAdmin(): void
    {
        $files = glob(dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Controllers/*.php') ?: [];
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('/\bOkay\\\\Admin\\\\/', (string)file_get_contents($file), basename($file));
        }
    }

    public function testRoutesPointAtExistingCartMethods(): void
    {
        $routes = require dirname(__DIR__, 5) . '/Okay/Modules/Sviat/RozetkaDelivery/Init/routes.php';
        $slugs = [];
        foreach ($routes as $route) {
            self::assertTrue($route['to_front']);
            self::assertTrue(method_exists($route['params']['controller'], $route['params']['method']), $route['slug']);
            $slugs[$route['slug']] = $route['params']['method'];
        }

        self::assertSame(['ajax/rozetka/cities' => 'cities', 'ajax/rozetka/points' => 'points', 'ajax/rozetka/date' => 'date'], $slugs);
    }
}
