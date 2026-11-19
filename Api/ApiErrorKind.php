<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Api;

enum ApiErrorKind: string
{
    case Unreachable = 'unreachable';
    /** Облікових даних у налаштуваннях немає: запит не надсилався, і Rozetka нічого не відмовляла. */
    case NotConfigured = 'not_configured';
    /** 401: токен партнера неправильний, відкликаний чи прострочений. */
    case BadToken = 'bad_token';
    case NotFound = 'not_found';
    /** Інше 4xx (зокрема 403) чи statusCode ≠ 0 у 2xx: Rozetka зрозуміла запит і відмовила, текст у apiMessage. */
    case Rejected = 'rejected';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case Malformed = 'malformed';
    case Empty = 'empty';
}
