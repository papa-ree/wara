<?php

namespace Bale\Wara\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Seed permission dashboard device (PRD §10.1).
 */
class InstallCommand extends Command
{
    protected $signature = 'wara:install';

    protected $description = 'Install Wara: seed permission dashboard device dan layanan guest';

    public function handle(): int
    {
        $permissions = [
            'wara.device.read' => 'Membaca katalog device WhatsApp.',
            'wara.device.update' => 'Menguji koneksi dan menyegarkan status device WhatsApp.',
            'wara.device.delete' => 'Menghapus device ghost (offline) beserta route yang menunjuknya.',
            'wara.device.manage' => 'Menambah slot device, pairing (QR/kode), reconnect, logout, hapus slot, dan mengatur webhook per-device.',
            'wara.client.read' => 'Membaca daftar client Wara dan peta purpose ke device.',
            'wara.client.update' => 'Membuat client, memetakan purpose ke device, dan mengelola endpoint webhook.',
            'wara.client.delete' => 'Menghapus client beserta route dan endpoint webhook-nya.',
            'wara.log.read' => 'Membaca riwayat pesan WhatsApp. Halaman ini read-only.',
            'wara.status.read' => 'Membaca halaman status bale/wara. Halaman ini read-only.',
            'wara.template.read' => 'Membaca antrean template pesan dan statusnya.',
            'wara.template.review' => 'Menyetujui atau menolak template pesan guest.',
        ];

        $model = config('permission.models.permission', Permission::class);

        foreach ($permissions as $name => $label) {
            $permission = $model::firstOrCreate(['name' => $name], ['guard_name' => 'web']);

            $this->line(sprintf('  + Permission: %s', $name));
        }

        $this->newLine();

        // Layanan guest `wara` dibuat kalau tabel rakaca tersedia. Command ini
        // tidak boleh gagal hanya karena aplikasi belum memasang bale/rakaca.
        if (Schema::hasTable('rakaca_services')) {
            $exists = DB::table('rakaca_services')->where('slug', 'wara')->exists();

            if (! $exists) {
                DB::table('rakaca_services')->insert([
                    'id' => (string) Str::uuid(),
                    'name' => 'Wara',
                    'slug' => 'wara',
                    'icon' => 'message-circle',
                    'description' => 'Gateway WhatsApp dan katalog template pesan.',
                    'actived' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->line('  + Service: wara');
            }
        }

        $this->info('Wara terinstall. Permission device siap dipakai.');

        return self::SUCCESS;
    }
}
