<?php

namespace Bale\Wara\Support;

/**
 * Bentuk payload keluar untuk consumer fan-out (PRD §12.4).
 *
 * DoD §12.6 menuntut: "body yang diterima consumer identik byte-wise dengan
 * body GOWA + hanya field `delivered_via` yang bertambah".
 *
 * Ini bukan sesuatu yang bisa didapat dengan decode → encode ulang. GOWA
 * menulis JSON dari Go, yang secara default meng-escape `<`, `>`, `&` menjadi
 * `\u003c` dan seterusnya, dan meng-escape `/` menjadi `\/`. Setelah
 * `json_decode` + `json_encode` di PHP, bentuk byte-nya berubah — dan karena
 * signature HMAC dihitung terhadap byte body, perubahan sekecil apa pun
 * membuat signature consumer gagal diverifikasi.
 *
 * Jadi raw body tidak boleh di-decode. Field `delivered_via` disisipkan dengan
 * menyisipkan string tepat sebelum penutup objek terluar, sehingga setiap byte
 * asli GOWA tetap utuh.
 */
final class OutgoingPayload
{
    public const VIA_FIELD = 'delivered_via';

    public const VIA_VALUE = 'bale/wara';

    /**
     * Sisipkan `delivered_via` ke raw body GOWA tanpa mengubah byte lain.
     *
     * Kalau body ternyata bukan objek JSON (mis. array atau tidak valid),
     * jatuh ke decode/encode karena sisipkan string tidak mungkin dilakukan —
     * dan pada kasus itu tidak ada consumer aktif yang boleh dilayani diam-diam,
     * jadi hasilnya dikembalikan apa adanya agar job mencatat kegagalan.
     */
    public static function withDeliveredVia(string $rawBody): string
    {
        $trimmed = rtrim($rawBody);

        if ($trimmed === '' || ! str_ends_with($trimmed, '}')) {
            return $rawBody;
        }

        $head = rtrim(substr($trimmed, 0, -1));

        // Objek kosong `{}` tidak butuh koma; sisip apa pun perlu koma.
        $separator = str_ends_with($head, '{') ? '' : ',';

        // `JSON_UNESCAPED_SLASHES` dipakai supaya nilainya terbaca `"bale/wara"`,
        // bukan `"bale\/wara"`. Keduanya JSON-valid, tapi yang kedua menyusahkan
        // client yang mencocokkan string secara literal.
        $encoded = json_encode(
            [self::VIA_FIELD => self::VIA_VALUE],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        // Buang kurung kurawal dari hasil encode, sisipkan isinya saja.
        $pair = substr((string) $encoded, 1, -1);

        return $head.$separator.$pair.'}';
    }
}
