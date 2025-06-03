<?php
/*
 * This file is sample code for Polidog\Shield.
 *  php -S localhost:8080 ./sample.php
 */
require 'vendor/autoload.php';

use Polidog\Shield\Handler;

new Handler()->handle([
    'GET /' => [
        'query' => [
            'page' => [
                'type' => 'int',
                'required' => false,
                'default' => 1,
            ]
        ],
        'response' => [
            200 => [
                'page' => [
                    'type' => 'int',
                    'required' => true,
                    'default' => 1,
                ],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'int'],
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                        ]
                    ]
                ],
                'totalItems' => ['type' => 'int', 'required' => true],
            ],
        ],
        'callback' => static function (array $request) {
            // Mock response for testing
            return [
                200,
                [
                    'page' => $request['query']['page'],
                    'items' => [
                        ['id' => 1, 'name' => 'Item 1', 'description' => 'Description 1'],
                        ['id' => 2, 'name' => 'Item 2', 'description' => 'Description 2'],
                    ],
                    'totalItems' => 2,
                ],
            ];
        }
    ]
]);