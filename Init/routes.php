<?php

namespace Okay\Modules\Sviat\RozetkaDelivery;

return [
    'Sviat_RozetkaDelivery_cart_cities' => [
        'slug' => 'ajax/rozetka/cities',
        'to_front' => true,
        'params' => [
            'controller' => __NAMESPACE__ . '\Controllers\CartController',
            'method' => 'cities',
        ],
    ],
    'Sviat_RozetkaDelivery_cart_points' => [
        'slug' => 'ajax/rozetka/points',
        'to_front' => true,
        'params' => [
            'controller' => __NAMESPACE__ . '\Controllers\CartController',
            'method' => 'points',
        ],
    ],
    'Sviat_RozetkaDelivery_cart_date' => [
        'slug' => 'ajax/rozetka/date',
        'to_front' => true,
        'params' => [
            'controller' => __NAMESPACE__ . '\Controllers\CartController',
            'method' => 'date',
        ],
    ],
];
