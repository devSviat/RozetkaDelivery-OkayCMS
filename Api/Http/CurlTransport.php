<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Api\Http;

final class CurlTransport implements HttpTransport
{
    public const int MAX_BODY_BYTES = 20 * 1024 * 1024;

    private ?\CurlHandle $handle = null;

    public static function tooLarge(int $received): bool
    {
        return $received > self::MAX_BODY_BYTES;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        // Один handle на процес тримає TLS-з'єднання з Rozetka відкритим між викликами.
        $this->handle ??= curl_init();
        curl_reset($this->handle);

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        $body = '';
        curl_setopt_array($this->handle, [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $request->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            // Повернений 0 байтів перериває передачу, тож завеликий довідник не з'їсть пам'ять процесу.
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                $body .= $chunk;

                return self::tooLarge(strlen($body)) ? 0 : strlen($chunk);
            },
        ]);
        if ($request->body !== null) {
            curl_setopt($this->handle, CURLOPT_POSTFIELDS, $request->body);
        }

        if (curl_exec($this->handle) === false) {
            if (self::tooLarge(strlen($body))) {
                throw new TransportException('Rozetka response is larger than ' . self::MAX_BODY_BYTES . ' bytes');
            }
            // Текст curl_error() містить лише хост, ніколи не рядок запиту з токеном.
            throw new TransportException(curl_error($this->handle) ?: 'Rozetka did not answer');
        }

        $contentType = curl_getinfo($this->handle, CURLINFO_CONTENT_TYPE);

        return new HttpResponse(curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE), $body, is_string($contentType) ? $contentType : '');
    }
}
