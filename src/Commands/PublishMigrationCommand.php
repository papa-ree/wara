<?php

namespace Bale\Wara\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Publish migration stub `.php.stub` ke `database/migrations` aplikasi.
 *
 * File yang ditulis diberi prefix timestamp (`Y_m_d_His_`) supaya urutannya
 * mengikuti waktu publish, sama seperti `make:migration` bawaan Laravel. Tanpa
 * prefix itu, urutan eksekusi migration jadi tidak deterministik.
 *
 * Karena prefix timestamp membuat nama file berbeda tiap publish, "sudah ada"
 * dideteksi lewat **nama migration tanpa timestamp** — bukan lewat nama file.
 * Jadi command ini tetap aman dijalankan berulang kali.
 */
class PublishMigrationCommand extends Command
{
    protected $signature = 'wara:publish-migration';

    protected $description = 'Publish migration stub bale/wara ke database/migrations aplikasi';

    public function handle(): int
    {
        $sourceDir = __DIR__.'/../../database/migrations';

        if (! is_dir($sourceDir)) {
            $this->error('Direktori migration package tidak ditemukan: '.$sourceDir);

            return self::FAILURE;
        }

        $published = $skipped = 0;

        foreach (File::files($sourceDir) as $file) {
            $filename = $file->getFilename();

            if (! str_ends_with($filename, '.php.stub')) {
                continue;
            }

            // `basename(..., '.stub')` menyisakan satu ekstensi: hasilnya
            // `create_wara_sessions_table.php`, bukan tanpa `.php`.
            $name = basename($filename, '.php.stub');
            $target = $this->findExisting($name);

            if ($target !== null) {
                $skipped++;
                $this->line(sprintf('  - Lewati (sudah ada): %s', basename($target)));

                continue;
            }

            $new = database_path(sprintf('migrations/%s_%s.php', date('Y_m_d_His'), $name));

            File::copy($file->getRealPath(), $new);
            $published++;
            $this->line(sprintf('  + Dipublish: %s', basename($new)));
        }

        $this->newLine();
        $this->info(sprintf('Selesai. Dipublish: %d, dilewati: %d.', $published, $skipped));

        return self::SUCCESS;
    }

    /**
     * Cari file yang sudah contiene migration dengan nama yang sama,
     * mengabaikan prefix timestamp.
     */
    protected function findExisting(string $name): ?string
    {
        $pattern = database_path('migrations/*_'.$name.'.php');

        $matches = glob($pattern);

        if (is_array($matches) && $matches !== []) {
            return $matches[0];
        }

        // Jugota file tanpa timestamp (dipublish oleh versi lama).
        $legacy = database_path('migrations/'.$name.'.php');

        return file_exists($legacy) ? $legacy : null;
    }
}
