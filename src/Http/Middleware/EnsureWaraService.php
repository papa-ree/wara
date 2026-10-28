<?php

namespace Bale\Wara\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang panel guest Wara (PRD §16.5).
 *
 * Guest hanya boleh masuk kalau organisasinya memang berlangganan layanan
 * `wara`. Pemeriksaan ini TIDAK memakai permission Spatie karena panel guest
 * bukan panel admin: yang menentukan akses adalah relasi layanan, bukan role.
 */
class EnsureWaraService
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null, 403);

        $hasService = method_exists($user, 'hasService')
            && $user->hasService('wara');

        abort_unless(
            $hasService,
            403,
            __('Layanan Wara tidak aktif untuk organisasi Anda.')
        );

        return $next($request);
    }
}
