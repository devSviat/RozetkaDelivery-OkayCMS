<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Api;

final readonly class Credentials
{
    public const string TOKEN = 'sviat__rozetka__token';
    public const string TOKEN_ID = 'sviat__rozetka__token_id';

    public const string BASE = 'https://rz-delivery.rozetka.ua/api/';

    /** @param ?int $tokenId номер статичного токена, якщо його випустив модуль; потрібен, щоб відкликати попередній */
    public function __construct(
        public string $token,
        public ?int $tokenId = null,
    ) {
    }

    /** @param callable(string): mixed $get */
    public static function fromSettings(callable $get): self
    {
        $token = $get(self::TOKEN);
        $id = $get(self::TOKEN_ID);
        $id = is_int($id) || (is_string($id) && ctype_digit(trim($id))) ? (int)$id : 0;

        return new self(is_string($token) ? trim($token) : '', $id > 0 ? $id : null);
    }

    public function configured(): bool
    {
        return $this->token !== '';
    }

    /** @return list<string> */
    public function secrets(): array
    {
        return $this->token === '' ? [] : [$this->token];
    }

    /**
     * Прибирає з країв те, що додає копіювання з кабінету, месенджера чи документа:
     * пробіли Unicode, невидимі символи форматування (BOM, zero-width) і лапки будь-якого стилю.
     */
    public static function normalizeToken(string $raw): string
    {
        $edge = '[\s\p{Z}\p{Cf}"\'«»“”„‘’]+';

        return preg_replace("/^{$edge}|{$edge}$/u", '', $raw) ?? trim($raw);
    }
}
