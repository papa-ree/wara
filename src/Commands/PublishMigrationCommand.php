<?php

namespace Bale\Wara\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Publish migration stub `.php.stub` ke `database/migrations` aplikasi.
 *
 * Stub sudah membawa prefix timestamp sendiri (`Y_m_d_NNNNNN_`, konvensi yang
 * sama dengan `packages/loker/database/migrations`), jadi urutan eksekusi
 * migration deterministik — ditentukan di stub, bukan waktu publish. File yang
 * ditulis = nama stub dengan ekstensi `.stub` dibuang.
 *
 * "Sudah ada" dideteksi lewat **nama migration logis** (nama file tanpa prefix
 * timestamp dan tanpa `.stub`) — bukan lewat nama file. Jadi command ini aman
 * dijalankan berulang kali, dan juga mengenali file yang dipublish oleh versi
 * lama yang memakai prefix `Y_m_d_His` dari `make:migration`.
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

            $target = self::existingTarget(self::logicalName($filename));

            if ($target !== null) {
                $skipped++;
                $this->line(sprintf('  - Lewati (sudah ada): %s', basename($target)));

                continue;
            }

            $new = database_path('migrations/'.basename($filename, '.php.stub').'.php');
            File::copy($file->getRealPath(), $new);
            $published++;
            $this->line(sprintf('  + Dipublish: %s', basename($new)));
        }

        $this->newLine();
        $this->info(sprintf('Selesai. Dipublish: %d, dilewati: %d.', $published, $skipped));

        return self::SUCCESS;
    }

    /**
     * Nama migration logis: basename tanpa `.php.stub` dan tanpa prefix
     * timestamp `Y_m_d_NNNNNN_`. Contoh: `2026_09_29_000001_create_wara_logs_table`
     * → `create_wara_logs_table`.
     */
    public static function logicalName(string $filename): string
    {
        $name = basename($filename, '.php.stub');

        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name) ?? $name;
    }

    /**
     * Cari file di aplikasi yang sudah berisi migration dengan nama logis yang
     * sama, mengabaikan prefix timestamp. Mengenali juga file tanpa timestamp
     * (dipublish oleh versi paling lama).
     */
    public static function existingTarget(string $logical): ?string
    {
        $pattern = database_path('migrations/*_'.$logical.'.php');

        $matches = glob($pattern);

        if (is_array($matches) && $matches !== []) {
            return $matches[0];
        }

        $legacy = database_path('migrations/'.$logical.'.php');

        return file_exists($legacy) ? $legacy : null;
    }
}
