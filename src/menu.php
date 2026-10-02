<?php

return [
    'type' => 'landlord',

    'groups' => [
        [
            'key' => 'wara',
            'label' => 'Wara',
            'icon' => 'message-circle',
            'items' => [
                [
                    'label' => 'Device',
                    'url' => 'wara/device',
                    'icon' => 'smartphone',
                    'permission' => 'wara.device.read',
                    'table' => 'wara_sessions',
                ],
                [
                    'label' => 'Log Pesan',
                    'url' => 'wara/log',
                    'icon' => 'message-square',
                    'permission' => 'wara.log.read',
                    'table' => 'wara_logs',
                ],
                [
                    'label' => 'Status',
                    'url' => 'wara/status',
                    'icon' => 'list-checks',
                    'permission' => 'wara.status.read',
                ],
            ],
        ],
    ],
];
