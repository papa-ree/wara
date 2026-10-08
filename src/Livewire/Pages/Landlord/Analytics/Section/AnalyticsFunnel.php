<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Analytics\Section;

use Bale\Wara\Analytics\WaraAnalytics;
use Livewire\Component;

/**
 * Grafik funnel pengiriman (PRD §14.2).
 *
 * 🔴 Lebar bucket dihitung di `WaraAnalytics::funnel()`, bukan di sini.
 * Kalau dihitung ulang di view, angka sumbu-x dan query SQL bisa menyimpang
 * tanpa ada yang gagal — dan grafik yang salah sumbu-x lebih berbahaya dari
 * halaman yang error, karena terlihat meyakinkan.
 */
class AnalyticsFunnel extends Component
{
    public int $range = WaraAnalytics::DEFAULT_RANGE_HOURS;

    public function render()
    {
        $funnel = (new WaraAnalytics)->funnel($this->range);

        return view('wara::livewire.pages.landlord.analytics.section.analytics-funnel', [
            // 🔴 Label memakai `d M H:i`, bukan `H:i` saja. Untuk rentang 24 jam ke
            // atas, `H:i` yang sama muncul dua kali di sumbu-x dan admin tidak
            // bisa tahu itu kemarin atau hari ini.
            'funnel' => $funnel,
            'chartLabels' => array_map(
                fn (array $bucket) => $bucket['at']->format('d M H:i'),
                $funnel
            ),
            // 🔴 Kelima status throughput digambar, bukan hanya tiga. Versi awal
            // hanya plotting `accepted`/`rejected`/`received`, padahal
            // `WaraAnalytics::funnel()` juga mengembalikan `delivered` dan
            // `read`, dan `summary()` menjumlahkannya jadi "masuk". Akibatnya
            // angka di kartu KPI tidak pernah bisa dicocokkan dengan grafik di
            // atasnya, dan tidak ada yang gagal.
            'chartDatasets' => [
                [
                    'label' => __('Diterima gateway'),
                    'data' => array_column($funnel, 'accepted'),
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.15)',
                    'borderWidth' => 2,
                    'tension' => 0.35,
                    'pointRadius' => 2,
                ],
                [
                    'label' => __('Ditolak gateway'),
                    'data' => array_column($funnel, 'rejected'),
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.15)',
                    'borderWidth' => 2,
                    'tension' => 0.35,
                    'pointRadius' => 2,
                ],
                [
                    'label' => __('Masuk'),
                    'data' => array_column($funnel, 'received'),
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.15)',
                    'borderWidth' => 2,
                    'tension' => 0.35,
                    'pointRadius' => 2,
                ],
                [
                    'label' => __('Terkirim device'),
                    'data' => array_column($funnel, 'delivered'),
                    'borderColor' => '#8b5cf6',
                    'backgroundColor' => 'rgba(139, 92, 246, 0.15)',
                    'borderWidth' => 2,
                    'tension' => 0.35,
                    'pointRadius' => 2,
                ],
                [
                    'label' => __('Dibaca'),
                    'data' => array_column($funnel, 'read'),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'borderWidth' => 2,
                    'tension' => 0.35,
                    'pointRadius' => 2,
                ],
            ],
        ]);
    }
}
