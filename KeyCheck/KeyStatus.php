<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\KeyCheck;

enum KeyStatus: string
{
    case Ok = 'ok';
    case BadToken = 'bad_token';
    /** 403 чи інша відмова: токен прийнято, але прав чи договору немає. */
    case Rejected = 'rejected';
    case Unreachable = 'unreachable';
    case NotConfigured = 'not_configured';
}
