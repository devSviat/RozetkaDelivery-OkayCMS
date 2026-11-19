<?php

namespace Modules\Sviat\RozetkaDelivery\Api;

require_once __DIR__ . '/../Support/FakeTransport.php';

use Modules\Sviat\RozetkaDelivery\Support\FakeTransport;
use Okay\Modules\Sviat\RozetkaDelivery\Api\ApiErrorKind;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\HttpRequest;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\HttpResponse;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\HttpTransport;
use Okay\Modules\Sviat\RozetkaDelivery\Api\Http\TransportException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\RozetkaApiException;
use Okay\Modules\Sviat\RozetkaDelivery\Api\SendsRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SendsRequestsTest extends TestCase
{
    private function client(): object
    {
        return new class {
            use SendsRequests;

            /** @param list<string> $secrets */
            public function call(HttpTransport $http, array $secrets = ['tok-123']): HttpResponse
            {
                return $this->deliver($http, new HttpRequest('GET', 'https://rz-delivery.rozetka.ua/api/auth/verify', ['Authorization' => 'Bearer tok-123']), $secrets);
            }
        };
    }

    public function testTransportTextIsMasked(): void
    {
        $http = new FakeTransport()->push(new TransportException('cURL: refused, Bearer tok-123 sent'));

        try {
            $this->client()->call($http);
            self::fail('expected an exception');
        } catch (RozetkaApiException $e) {
            self::assertSame(ApiErrorKind::Unreachable, $e->kind);
            self::assertStringNotContainsString('tok-123', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testMaskCoversUrlEncodedForms(): void
    {
        self::assertSame('a *** b *** c ***', RozetkaApiException::mask('a p@ss w b p%40ss+w c p%40ss%20w', ['p@ss w']));
        self::assertSame('text', RozetkaApiException::mask('text', ['']));
    }

    public function testSuccessIsReturned(): void
    {
        $response = $this->client()->call(new FakeTransport()->json(201, ['statusCode' => 0, 'data' => []]));

        self::assertSame(201, $response->status);
    }

    /** @return iterable<string, array{int, ApiErrorKind}> */
    public static function statuses(): iterable
    {
        yield '401' => [401, ApiErrorKind::BadToken];
        yield '403' => [403, ApiErrorKind::Rejected];
        yield '404' => [404, ApiErrorKind::NotFound];
        yield '429' => [429, ApiErrorKind::RateLimited];
        yield '500' => [500, ApiErrorKind::ServerError];
        yield '400' => [400, ApiErrorKind::Rejected];
    }

    #[DataProvider('statuses')]
    public function testStatusMapsToKind(int $status, ApiErrorKind $kind): void
    {
        $http = new FakeTransport()->json($status, [
            'statusCode' => $status,
            'message' => 'Помилка валідації даних',
            'details' => [['property' => 'receiver_phone', 'children' => [], 'constraints' => ['isPhone' => 'bad tok-123']]],
            'error' => 'Bad Request',
        ]);

        try {
            $this->client()->call($http);
            self::fail('expected an exception');
        } catch (RozetkaApiException $e) {
            self::assertSame($kind, $e->kind);
            self::assertStringNotContainsString('tok-123', $e->getMessage());
            self::assertStringNotContainsString('tok-123', (string) $e->apiMessage);
            self::assertSame('receiver_phone', $e->field);
        }
    }

    public function testTraceCarriesNoArguments(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $this->client()->call(new FakeTransport()->push(new TransportException('x')));
            self::fail('expected an exception');
        } catch (RozetkaApiException $e) {
            self::assertNotSame([], $e->getTrace());
            foreach ($e->getTrace() as $frame) {
                self::assertArrayNotHasKey('args', $frame);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    public function testHtmlErrorBodyIsServerErrorWithoutWarning(): void
    {
        $http = new FakeTransport()->push(new HttpResponse(502, '<html><body>Bad Gateway</body></html>'));

        try {
            $this->client()->call($http);
            self::fail('expected an exception');
        } catch (RozetkaApiException $e) {
            self::assertSame(ApiErrorKind::ServerError, $e->kind);
            self::assertNull($e->apiMessage);
            self::assertNull($e->field);
        }
    }
}
