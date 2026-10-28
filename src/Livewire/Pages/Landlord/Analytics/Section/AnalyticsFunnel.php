<?php

namespace Bale\Wara\Livewire\Pages\Landlord\Analytics\Section;

use Bale\Wara\Analytics\WaraAnalytics;
use Bale\Wara\Analytics\WaraFilter;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Grafik funnel pengiriman (PRD §14.2).
 *
 * 🔴 Lebar dan jumlah bucket dihitung di `WaraAnalytics`, bukan di sini.
 * Kalau dihitung ulang di view, angka sumbu-x dan query SQL bisa menyimpang
 * tanpa ada yang gagal — dan grafik yang salah sumbu-x lebih berbahaya dari
 * halaman yang error, karena terlihat meyakinkan.
 */
class AnalyticsFunnel extends Component
{
    public WaraFilter $filter;

    public function render()
    {
        $funnel = (new WaraAnalytics)->filteredFunnel($this->filter);

        return view('wara::livewire.pages.landlord.analytics.section.analytics-funnel', [
            'funnel' => $funnel,
            'hasFunnelData' => $this->hasData($funnel),
            'rangeLabel' => $this->filter->label(),
            // 🔴 Format label mengikuti panjang rentang efektif.
            'chartLabels' => array_map(
                fn (array $bucket) => $this->labelFor($bucket['at'], $this->filter->effectiveHours()),
                $funnel
            ),
            'chartOptions' => $this->chartOptions(),
            'chartDatasets' => [
                [
                    'label' => __('Diterima gateway'),
                    'data' => array_column($funnel, 'accepted'),
                    'borderColor' => '#14B8A6',
                    'backgroundColor' => 'rgba(20, 184, 166, 0.12)',
                    'borderWidth' => 2,
                    'tension' => 0.35,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'fill' => false,
                ],
                [
                    'label' => __('Ditolak gateway'),
                    'data' => array_column($funnel, 'rejected'),
                    'borderColor' => '#F43F5E',
                    'backgroundColor' => 'rgba(244, 63, 94, 0.12)',
                    'borderWidth' => 2,
                    'tension' => 0.35,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'fill' => false,
                ],
                [
                    'label' => __('Masuk'),
                    'data' => array_column($funnel, 'received'),
                    'borderColor' => '#0EA5E9',
                    'backgroundColor' => 'rgba(14, 165, 233, 0.12)',
                    'borderWidth' => 2,
                    'tension' => 0.35,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'fill' => false,
                ],
                [
                    'label' => __('Terkirim device'),
                    'data' => array_column($funnel, 'delivered'),
                    'borderColor' => '#8B5CF6',
                    'backgroundColor' => 'rgba(139, 92, 246, 0.12)',
                    'borderWidth' => 2,
                    'tension' => 0.35,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'fill' => false,
                ],
                [
                    'label' => __('Dibaca'),
                    'data' => array_column($funnel, 'read'),
                    'borderColor' => '#F59E0B',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.12)',
                    'borderWidth' => 2,
                    'tension' => 0.35,
                    'pointRadius' => 0,
                    'pointHoverRadius' => 4,
                    'fill' => false,
                ],
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $funnel
     */
    protected function hasData(array $funnel): bool
    {
        foreach ($funnel as $bucket) {
            if (($bucket['accepted'] + $bucket['rejected'] + $bucket['received'] + $bucket['delivered'] + $bucket['read']) > 0) {
                return true;
            }
        }

        return false;
    }

    protected function labelFor(Carbon $at, int $hours): string
    {
        return match (true) {
            $hours <= 24 => $at->translatedFormat('H:i'),
            $hours <= 168 => $at->translatedFormat('D H:i'),
            default => $at->translatedFormat('d M'),
        };
    }

    protected function chartOptions(): array
    {
        $muted = '#94a3b8';
        $grid = 'rgba(148, 163, 184, 0.18)';

        return [
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                    'labels' => [
                        'color' => $muted,
                        'usePointStyle' => true,
                        'boxWidth' => 10,
                        'padding' => 16,
                        'font' => ['size' => 11],
                    ],
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'grid' => ['display' => false],
                    'ticks' => [
                        'color' => $muted,
                        'maxRotation' => 0,
                        'autoSkip' => true,
                        'maxTicksLimit' => 10,
                        'font' => ['size' => 10],
                    ],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'grid' => ['color' => $grid],
                    'ticks' => [
                        'color' => $muted,
                        'precision' => 0,
                        'font' => ['size' => 10],
                    ],
                ],
            ],
        ];
    }
}
