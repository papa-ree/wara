<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Device\Section;

use Bale\Core\Traits\HasDeleteOption;
use Bale\Wara\Models\WaraSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * Tabel device yang menghiasi `data-table` (+ hapus manual untuk ghost).
 *
 * 🔴 `HasDeleteOption` menempel di sini, bukan di `Index` — lihat `ClientTable`
 * untuk alasan yang sama: trait itu memasang listener `deleteItem`, dan
 * `data-table` yang merender baris juga mendengar `deleteItem` dengan
 * penghapusan generik tanpa authorization/cascade.
 */
class DeviceTable extends Component
{
    use AuthorizesRequests, HasDeleteOption;

    /**
     * Dipakai `HasDeleteOption` untuk tahu model mana yang dihapus.
     */
    protected string $modelClass = WaraSession::class;

    public function mount(): void
    {
        $this->authorize('wara.device.read');
    }

    /**
     * Listener hapus untuk baris device.
     *
     * 🔴 Bukan `deleteItem`: baris device dipakai `item-actions`, dan kalau
     * event generik dipakai, `data-table` akan menjalankan penghapusan mentah
     * lebih dulu tanpa authorization `wara.device.delete` dan tanpa membuang
     * `wara_routes` dalam satu transaksi.
     */
    #[On('deleteWaraDevice')]
    public function deleteWaraDevice($id): void
    {
        $this->deleteId = $id;
        $this->performDelete();
    }

    /**
     * 🔴 Override `performDelete()` milik trait.
     *
     * Dua perbedaan dari bawaan trait:
     *
     * 1. Hanya device yang SUDAH offline (`is_present = false`) yang boleh
     *    dihapus manual. Device yang masih ada di gateway dikelola sinkron —
     *    menghapusnya di sini hanya membuat baris muncul lagi saat sinkron
     *    berikutnya, jadi lebih baik tolak dengan jelas.
     * 2. Route milik device ikut dihapus, dalam satu transaksi. Ghost device
     *    sengaja dipertahankan selama masih dipakai route; begitu admin
     *    memutuskan menghapusnya, route itu harus dibersihkan BERSAMA device —
     *    kalau tidak, tertinggal route yang menunjuk device yang tak ada.
     */
    public function performDelete(string $message = 'Device dihapus.')
    {
        $this->authorize('wara.device.delete');

        try {
            /** @var WaraSession $session */
            $session = WaraSession::query()->findOrFail($this->deleteId);

            if ($session->is_present) {
                $this->dispatch('toast', message: __('Device masih ada di gateway. Hapus dari GOWA lalu sinkronkan.'), type: 'error');

                return;
            }

            DB::transaction(function () use ($session) {
                $session->routes()->delete();
                $session->delete();
            });
        } catch (ModelNotFoundException) {
            $this->dispatch('toast', message: __('Device tidak ditemukan.'), type: 'error');

            return;
        } catch (Throwable $e) {
            // 🔴 Jangan ditelan jadi "Item not found".
            report($e);

            $this->dispatch('toast', message: __('Device tidak bisa dihapus. :message', ['message' => $e->getMessage()]), type: 'error');

            return;
        }

        $this->dispatch('toast', message: $message, type: 'success');
        $this->dispatch('refresh-device-table');
    }

    #[On('refresh-device-table')]
    public function refreshDeviceTable(): void
    {
        // `data-table` tadi mendengar `refresh` bawaan untuk memuat ulang
        // barisnya; parent hanya perlu me-render ulang setelah event.
        $this->dispatch('refresh');
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.device.section.device-table');
    }
}
