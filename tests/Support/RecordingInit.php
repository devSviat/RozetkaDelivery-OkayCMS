<?php

namespace Modules\Sviat\RozetkaDelivery\Support;

use Okay\Core\Scheduler\Schedule;
use Okay\Modules\Sviat\RozetkaDelivery\Init\Init;

/**
 * Init без ядра: записує, що init() реєструє, замість звертатися до ServiceLocator.
 * Створюється через ReflectionClass::newInstanceWithoutConstructor().
 */
final class RecordingInit extends Init
{
    /** @var list<array{0:string, 1:mixed, 2:mixed}> */
    public array $calls = [];

    protected function registerChainExtension($expandable, $extension)
    {
        $this->calls[] = ['chain', $expandable, $extension];
    }

    protected function registerQueueExtension($expandable, $extension)
    {
        $this->calls[] = ['queue', $expandable, $extension];
    }

    protected function addBackendBlock($blockName, $blockTplFile, $callback = null)
    {
        $this->calls[] = ['backend_block', $blockName, $blockTplFile];
    }

    protected function addFrontBlock($blockName, $blockTplFile, $callback = null)
    {
        $this->calls[] = ['front_block', $blockName, $blockTplFile];
    }

    protected function registerBackendController($controllerClass)
    {
        $this->calls[] = ['controller', $controllerClass, null];
    }

    protected function addBackendControllerPermission($controllerClass, $permission)
    {
        $this->calls[] = ['permission', $controllerClass, $permission];
    }

    protected function registerEntityFilter($entityClassName, $filterName, $filterClassName, $methodName)
    {
        $this->calls[] = ['entity_filter', [$entityClassName, $filterName], [$filterClassName, $methodName]];
    }

    protected function registerEntityField($entityClassName, $fieldName, $isLang = false)
    {
        $this->calls[] = ['entity_field', $entityClassName, $fieldName];
    }

    protected function extendBackendMenu($firstLevelName, array $menuItemsByControllers, $icon = null)
    {
        $this->calls[] = ['menu', $firstLevelName, $menuItemsByControllers];
    }

    public function registerSchedule(Schedule $schedule): void
    {
        $this->calls[] = ['schedule', $schedule, null];
    }

    /** @return list<array{0:string, 1:mixed, 2:mixed}> */
    public static function record(): array
    {
        $init = new \ReflectionClass(self::class)->newInstanceWithoutConstructor();
        $init->init();

        return $init->calls;
    }
}
