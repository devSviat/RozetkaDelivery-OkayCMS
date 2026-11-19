<?php

namespace Okay\Modules\Sviat\RozetkaDelivery\Cart;

enum FitProblem: string
{
    case TooHeavy = 'too_heavy';
    case TooLong = 'too_long';
    case TooBulky = 'too_bulky';
    case OverCost = 'over_cost';
    case PrepayOnly = 'prepay_only';
    case CodOverLimit = 'cod_over_limit';
}
