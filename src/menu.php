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
                    // 🔴 Client placed BEFORE Device, deliberately.
                    //
                    // urutan ini bukan soal tampilan. `wara_sessions` tidak
                    // punya `purpose` lagi, jadi "device ini untuk siapa" hanya
                    // bisa dijawab lewat peta `(client, purpose)`. Admin yang
                    // membuka Device duluan akan mencari kolom purpose yang
                    // memang tidak ada di sana.
                    'label' => 'Client',
                    'url' => 'wara/client',
                    'icon' => 'users',
                    'permission' => 'wara.client.read',
                    'table' => 'wara_clients',
                ],
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
                    // 🔴 `table: 'wara_logs'` dipakai supaya item ini hilang
                    // otomatis di instalasi yang belum menjalankan migration
                    // analytics — persis seperti Log Pesan. Tanpa itu, menu
                    // menampilkan tautan ke halaman yang 500.
                    //
                    // Diletakkan SETELAH Log Pesan: Analitik menjawab
                    // "apakah gateway sehat", dan itu pertanyaan lanjutan.
                    // Menaruhnya lebih dulu membuat orang mengira ini
                    // dashboard utama saat sebenarnya baris pertamanya nol.
                    'label' => 'Analitik',
                    'url' => 'wara/analitik',
                    'icon' => 'chart-line',
                    'permission' => 'wara.log.read',
                    'table' => 'wara_logs',
                ],
                [
                    'label' => 'Dokumentasi API',
                    'url' => 'wara/docs',
                    'icon' => 'book-open',
                    'permission' => 'wara.client.read',
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
