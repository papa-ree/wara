<?php

use App\Models\User;
use Paparee\Rakaca\Models\PersonHasService;
use Paparee\Rakaca\Models\RakacaService;

/**
 * Operator organisasi penyewa. Akses halaman guest ditentukan relasi layanan,
 * bukan permission Spatie.
 */
function guestDocsUser(bool $withService): User
{
    $user = User::factory()->create();

    if ($withService) {
        $service = RakacaService::query()->create([
            'name' => 'Wara',
            'slug' => 'wara',
            'icon' => 'message-circle',
            'actived' => true,
        ]);

        PersonHasService::query()->create([
            'user_uuid' => $user->uuid,
            'rakaca_service_id' => $service->id,
            'actived' => true,
        ]);
    }

    return $user;
}

it('menampilkan endpoint dan payload untuk operator yang berlangganan', function () {
    $this->actingAs(guestDocsUser(true))
        ->get('/guest/wara/docs')
        ->assertOk()
        ->assertSee('/api/v1/wara/send')
        ->assertSee('/api/v1/wara/templates')
        ->assertSee('header_media_url')
        ->assertSee('template')
        ->assertSee('{{1}}');
});

it('menolak operator yang organisasinya tidak berlangganan Wara', function () {
    $this->actingAs(guestDocsUser(false))
        ->get('/guest/wara/docs')
        ->assertForbidden();
});

it('tidak membocorkan detail gateway maupun scope token', function () {
    $this->actingAs(guestDocsUser(true))
        ->get('/guest/wara/docs')
        ->assertOk()
        ->assertDontSee('GOWA')
        ->assertDontSee('wara.send')
        ->assertDontSee('wara.templates.read')
        ->assertDontSee('scope');
});
