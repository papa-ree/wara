<?php

namespace Bale\Wara\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;

/**
 * Seed permission dashboard device (PRD §10.1).
 */
class InstallCommand extends Command
{
    protected $signature = 'wara:install';

    protected $description = 'Install Wara: seed permission dashboard device';

    public function handle(): int
    {
        $permissions = [
            'wara.device.read' => 'Membaca katalog device WhatsApp.',
            'wara.device.update' => 'Menugaskan purpose device WhatsApp.',
            'wara.log.read' => 'Membaca riwayat pesan WhatsApp. Halaman ini read-only.',
            'wara.status.read' => 'Membaca halaman status bale/wara. Halaman ini read-only.',
        ];

        $model = config('permission.models.permission', Permission::class);

        foreach ($permissions as $name => $label) {
            $permission = $model::firstOrCreate(['name' => $name], ['guard_name' => 'web']);

            $this->line(sprintf('  + Permission: %s', $name));
        }

        $this->newLine();
        $this->info('Wara terinstall. Permission device siap dipakai.');

        return self::SUCCESS;
    }
}
