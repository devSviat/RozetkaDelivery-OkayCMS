<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Api;

/**
 * Повідомлення й стек потрапляють у логи. Текст сюди приходить уже замаскованим (див. mask()), а стек
 * зберігається без аргументів викликів: з zend.exception_ignore_args=Off у них лежали б заголовок Authorization
 * і масив секретів.
 */
final class RozetkaApiException extends \RuntimeException
{
    private function __construct(
        public readonly ApiErrorKind $kind,
        string $message,
        public readonly ?string $field = null,
        public readonly ?string $apiMessage = null,
    ) {
        parent::__construct($message);
        new \ReflectionProperty(\Exception::class, 'trace')->setValue(
            $this,
            array_map(static fn(array $frame): array => array_diff_key($frame, ['args' => true]), $this->getTrace()),
        );
    }

    /** Тексти вже пропущені через mask(): секрети сюди не передаються, бо аргументи потрапили б у стек. */
    public static function of(ApiErrorKind $kind, string $message, ?string $field = null, ?string $apiMessage = null): self
    {
        return new self($kind, $message, $field, $apiMessage);
    }

    /**
     * Єдине місце маскування: значення секретів і їхні закодовані для URL форми, бо Rozetka може повторити
     * їх у тексті помилки. Без урахування регістру: API може повторити значення в іншому регістрі.
     *
     * @param list<string> $secrets
     */
    public static function mask(string $text, array $secrets = []): string
    {
        $forms = [];
        foreach ($secrets as $secret) {
            array_push($forms, $secret, rawurlencode($secret), urlencode($secret));
        }
        $forms = array_values(array_filter($forms, static fn(string $s): bool => $s !== ''));

        return $forms === [] ? $text : str_ireplace($forms, '***', $text);
    }
}
