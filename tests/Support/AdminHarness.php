<?php

namespace Modules\Sviat\RozetkaDelivery\Support;

require_once __DIR__ . '/SettingsPage.php';

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\Design;
use Okay\Core\Request;
use Okay\Core\Response;

/**
 * Контролер адмінки без ядра: запит, відповідь і Design підмінено, а Design рендерить справжній шаблон
 * модуля з тими змінними, які призначив контролер.
 */
final class AdminHarness
{
    public ?int $status = null;
    public string $content = '';
    /** @var array<string, mixed> */
    public array $assigned = [];

    /**
     * @param class-string<IndexAdmin> $controllerClass
     * @param array<class-string, object> $services аргументи методу контролера за типом
     * @param array<string, mixed> $post
     */
    public function visit(string $controllerClass, array $services, string $method, array $post = [], string $action = 'fetch'): void
    {
        $_POST = $post;
        $this->assigned = [];
        $this->status = null;
        $this->content = '';
        $request = new class ($method) extends Request {
            public function __construct(private string $verb)
            {
            }

            public function method($method = null)
            {
                return $method === null ? $this->verb : strtolower((string)$method) === strtolower($this->verb);
            }
        };
        $status = &$this->status;
        $content = &$this->content;
        $response = new class ($status, $content) extends Response {
            public function __construct(private ?int &$status, private string &$content)
            {
            }

            public function setStatusCode($statusCode): Response
            {
                $this->status = $statusCode;

                return $this;
            }

            public function setContent($content, $type = null): Response
            {
                $this->content = (string)$content;

                return $this;
            }
        };
        $assigned = &$this->assigned;
        $design = new class ($assigned) extends Design {
            public function __construct(private array &$assigned)
            {
            }

            public function assign($var, $value, $dynamicJs = false)
            {
                $this->assigned[$var] = $value;
            }

            public function fetch($template, $forceMinify = false)
            {
                return SettingsPage::render($this->assigned, 'ua', $template);
            }
        };

        $controller = new \ReflectionClass($controllerClass)->newInstanceWithoutConstructor();
        $parent = new \ReflectionClass(IndexAdmin::class);
        $parent->getProperty('request')->setValue($controller, $request);
        $parent->getProperty('response')->setValue($controller, $response);
        $parent->getProperty('design')->setValue($controller, $design);

        $arguments = [];
        foreach (new \ReflectionMethod($controller, $action)->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $arguments[] = $services[$name] ?? throw new \LogicException("unexpected dependency {$name}");
        }
        $controller->$action(...$arguments);
    }
}
