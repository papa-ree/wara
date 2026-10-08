<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Client\Section;

use Bale\Core\Traits\HasDeleteOption;
use Bale\Wara\Models\WaraClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * Tabel client: peta `purpose -> device`, kesiapan, dan hapus.
 *
 * 🔴 `HasDeleteOption` menempel di sini, bukan di `Index`.
 *
 * Trait itu memasang listener `deleteItem`. Kalau listener-nya di komponen
 * lain, tombol hapus di tabel ini tidak akan mengubah apa pun - dan UI akan
 * menampilkan toast "berhasil" untuk penghapusan yang tidak terjadi. Itu
 * kegagalan yang paling mahal jenisnya: admin mengira client sudah hilang,
 * padahal masih melayani traffic.
 */
class ClientTable extends Component
{
    use AuthorizesRequests, HasDeleteOption;

    /**
     * Dipakai `HasDeleteOption` untuk tahu model mana yang dihapus.
     */
    protected string $modelClass = WaraClient::class;

    public function mount(): void
    {
        $this->authorize('wara.client.read');
    }

    /**
     * Listener hapus untuk baris client.
     *
     * 🔴 Nama event sengaja BUKAN `deleteItem`. `data-table` yang merender baris
     * ini juga memasang `#[On('deleteItem')]` dengan penghapusan generik — tanpa
     * authorization `wara.client.delete`, tanpa cascade route/endpoint, tanpa
     * transaksi. Event Livewire dikirim ke SEMUA komponen yang mendengarnya, jadi
     * kalau baris memakai `deleteItem` bawaan ItemActions, data-table akan
     * menghapus client mentah-mentah lebih dulu dan override `performDelete()` di
     * bawah tidak pernah menangani apa-apa.
     *
     * Karena itu item-actions di `client-row`/`client-card` memakai
     * `deleteEvent="deleteWaraClient"` — event ini hanya didengar komponen ini.
     */
    #[On('deleteWaraClient')]
    public function deleteWaraClient($id): void
    {
        $this->deleteId = $id;
        $this->performDelete();
    }

    /**
     * 🔴 Override `performDelete()` milik trait, karena bawaan trait memakai
     * toast teks Inggris dan tidak melibatkan route/endpoint.
     *
     * Dua hal bisa menggagalkan penghapusan di sini, dan keduanya berujung
     * sama: UI bilang "berhasil" padahal client masih ada.
     *
     * 1. `api_tokens` yang menunjuk client ini lewat `api_token_id`. Kalau
     *    ada, penghapusan gagal di level DB dengan pesan yang tidak akan pernah
     *    dibaca admin - dan trait bawaan justru akan melaporkannya sebagai
     *    "Item not found".
     * 2. Penghapusan berantai. `wara_routes` dan `wara_webhook_endpoints`
     *    milik client ini ikut hilang. Itu memang perilaku yang diinginkan,
     *    tapi harus terjadi dalam satu transaksi - kalau tidak, delete route
     *    berhasil lalu delete client gagal, dan tertinggal client tanpa route
     *    yang tidak bisa dilacak.
     */
    public function performDelete(string $message = 'Client Wara dihapus.')
    {
        $this->authorize('wara.client.delete');

        try {
            /** @var WaraClient $client */
            $client = WaraClient::query()->findOrFail($this->deleteId);

            DB::transaction(function () use ($client) {
                $client->routes()->delete();
                $client->webhookEndpoints()->delete();
                $client->delete();
            });
        } catch (ModelNotFoundException) {
            $this->dispatch('toast', message: __('Client tidak ditemukan.'), type: 'error');

            return;
        } catch (Throwable $e) {
            // 🔴 Jangan ditelan jadi "Item not found". Admin harus tahu ini
            // masalah integritas yang perlu dibereskan, bukan data hilang.
            report($e);

            $this->dispatch(
                'toast',
                message: __('Client tidak bisa dihapus, mungkin masih ada token yang memakainya. :message', [
                    'message' => $e->getMessage(),
                ]),
                type: 'error'
            );

            return;
        }

        $this->dispatch('toast', message: $message, type: 'success');
    }

    public function render()
    {
        return view('wara::livewire.pages.landlord.client.section.client-table');
    }
}
