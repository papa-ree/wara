<?php

namespace Bale\Wara\Http\Controllers\Api;

use Bale\Wara\Models\WaraClient;

/**
 * Purpose yang dipakai sebuah request.
 *
 * 🔴 Kalau client punya lebih dari satu route, purpose WAJIB disebut eksplisit.
 *
 * Alasannya bukan sekadar kenyamanan - memilih device yang salah berarti pesan
 * terkirim dari nomor yang tidak diminta. `notifikasi` yang salah device lebih
 * sulit dideteksi daripada pesan yang gagal terkirim, karena penerima tidak akan
 * pernah tahu itu seharusnya datang dari nomor lain.
 */
final class ResolvePurpose
{
    public static function for(WaraClient $client, ?string $requested): string
    {
        if (filled($requested)) {
            return $requested;
        }

        $purposes = $client->routes()->pluck('purpose')->all();

        if (count($purposes) === 1) {
            return (string) $purposes[0];
        }

        abort(422, $purposes === []
            ? 'Client ini belum punya route. Petakan purpose ke device di menu Wara > Client.'
            : sprintf(
                'Client ini punya %d route (%s). Sebutkan purpose-nya.',
                count($purposes),
                implode(', ', $purposes)
            ));
    }
}
