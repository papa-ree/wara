<?php

/**
 * Menu panel guest milik `bale/wara`.
 *
 * Dibaca `MenuRegistry` saat boot dan digabung dengan menu-guest package lain.
 * Filter `service` dilakukan `RakacaGuestSidebar` memakai relasi layanan user,
 * bukan permission Spatie.
 */
return [
    'type' => 'guest',

    'groups' => [
        [
            'key' => 'wara',
            'label' => 'Wara',
            'icon' => 'message-circle',
            'items' => [
                [
                    'label' => 'Template',
                    'url' => 'guest/wara/template',
                    'icon' => 'file-text',
                    'permission' => null,
                    'service' => 'wara',
                ],
                [
                    'label' => 'Dokumentasi API',
                    'url' => 'guest/wara/docs',
                    'icon' => 'book-open',
                    'permission' => null,
                    'service' => 'wara',
                ],
            ],
        ],
    ],
];
