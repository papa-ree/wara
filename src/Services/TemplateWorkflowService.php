<?php

namespace Bale\Wara\Services;

use Bale\Wara\Enums\TemplateStatus;
use Bale\Wara\Exceptions\TemplateWorkflowException;
use Bale\Wara\Models\WaraTemplate;

/**
 * Transisi status template (PRD §16.4).
 *
 * Semua transisi lewat sini supaya aturan "APPROVED immutable" tidak bisa
 * dilewati dari komponen Livewire mana pun.
 */
class TemplateWorkflowService
{
    public function submit(WaraTemplate $template): WaraTemplate
    {
        if (! $template->status->isEditable()) {
            throw TemplateWorkflowException::notEditable($template->status->value);
        }

        $template->forceFill([
            'status' => TemplateStatus::Pending,
            'submitted_at' => now(),
            'rejection_reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ])->save();

        return $template;
    }

    public function approve(WaraTemplate $template, string $reviewerUuid): WaraTemplate
    {
        if ($template->status !== TemplateStatus::Pending) {
            throw TemplateWorkflowException::notPending($template->status->value);
        }

        $template->forceFill([
            'status' => TemplateStatus::Approved,
            'reviewed_by' => $reviewerUuid,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ])->save();

        return $template;
    }

    public function reject(WaraTemplate $template, string $reviewerUuid, string $reason): WaraTemplate
    {
        if ($template->status !== TemplateStatus::Pending) {
            throw TemplateWorkflowException::notPending($template->status->value);
        }

        $template->forceFill([
            'status' => TemplateStatus::Rejected,
            'reviewed_by' => $reviewerUuid,
            'reviewed_at' => now(),
            'rejection_reason' => trim($reason),
        ])->save();

        return $template;
    }

    /**
     * Kembalikan template yang sudah disetujui ke DRAFT agar bisa direvisi.
     *
     * Tidak dipakai di UI saat ini; disediakan supaya "revisi = ajukan ulang"
     * punya jalur eksplisit dan tidak dilakukan dengan `fill()` langsung.
     */
    public function reopen(WaraTemplate $template): WaraTemplate
    {
        $template->forceFill([
            'status' => TemplateStatus::Draft,
            'submitted_at' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
        ])->save();

        return $template;
    }
}
