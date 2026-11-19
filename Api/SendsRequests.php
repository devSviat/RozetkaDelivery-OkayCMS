<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Api;

use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\HttpRequest;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\HttpResponse;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\HttpTransport;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\TransportException;

trait SendsRequests
{
    /**
     * Відповідь 2xx або типізований виняток. Секрети маскуються до створення винятку:
     * виняток транспорту не ланцюжиться, лише його замаскований текст.
     *
     * @param list<string> $secrets
     */
    private function deliver(HttpTransport $http, HttpRequest $request, array $secrets): HttpResponse
    {
        $where = RozetkaApiException::mask("{$request->method} {$request->url}", $secrets);

        try {
            $response = $http->send($request);
        } catch (TransportException $e) {
            throw RozetkaApiException::of(ApiErrorKind::Unreachable, $where . ': ' . RozetkaApiException::mask($e->getMessage(), $secrets));
        }

        if ($response->status >= 200 && $response->status < 300) {
            return $response;
        }

        [$field, $apiMessage] = self::errorText($response->json() ?? []);
        $field = $field === null ? null : RozetkaApiException::mask($field, $secrets);
        $apiMessage = $apiMessage === null ? null : RozetkaApiException::mask($apiMessage, $secrets);
        $kind = match (true) {
            $response->status === 401 => ApiErrorKind::BadToken,
            $response->status === 404 => ApiErrorKind::NotFound,
            $response->status === 429 => ApiErrorKind::RateLimited,
            $response->status >= 500 => ApiErrorKind::ServerError,
            default => ApiErrorKind::Rejected,
        };

        throw RozetkaApiException::of($kind, "{$where}: HTTP {$response->status}" . ($apiMessage === null ? '' : " {$apiMessage}"), $field, $apiMessage);
    }

    /**
     * Помилка валідації приходить як details[]{property, constraints{правило: текст}}, хоча схема описує
     * лише message (рядок чи об'єкт); текст поля точніший за загальне «Помилка валідації даних».
     *
     * @return array{?string, ?string} поле й текст для людини
     */
    private static function errorText(array $body): array
    {
        $first = is_array($body['details'] ?? null) ? ($body['details'][0] ?? null) : null;
        if (is_array($first)) {
            $field = is_string($first['property'] ?? null) && $first['property'] !== '' ? $first['property'] : null;
            $texts = self::strings($first['constraints'] ?? null);
            if ($texts !== []) {
                return [$field, implode('; ', $texts)];
            }
        }
        $message = $body['message'] ?? null;
        $texts = is_string($message) ? [$message] : self::strings($message);
        $texts = array_values(array_filter($texts, static fn(string $text): bool => $text !== ''));

        return [$field ?? null, $texts === [] ? null : implode('; ', $texts)];
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $texts = [];
        array_walk_recursive($value, static function (mixed $item) use (&$texts): void {
            if (is_string($item) && $item !== '') {
                $texts[] = $item;
            }
        });

        return $texts;
    }
}
