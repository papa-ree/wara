<?php

namespace Bale\Wara\Exceptions;

/**
 * Pelanggaran alur template (PRD §16.4).
 */
class TemplateWorkflowException extends WaraException
{
    public static function notEditable(string $status): self
    {
        return new self(sprintf(
            'Template berstatus %s tidak boleh diubah. Hanya DRAFT dan REJECTED yang bisa diedit.',
            $status
        ));
    }

    public static function notPending(string $status): self
    {
        return new self(sprintf(
            'Template berstatus %s tidak bisa direview. Hanya PENDING yang menunggu keputusan admin.',
            $status
        ));
    }

    public static function notApproved(string $status): self
    {
        return new self(sprintf(
            'Template berstatus %s tidak bisa dipakai mengirim. Hanya template APPROVED yang diizinkan.',
            $status
        ));
    }

    public static function missingParameter(string $variable): self
    {
        return new self(sprintf(
            'Parameter template "{{%s}}" belum diisi.',
            $variable
        ));
    }

    public static function mediaRequired(string $format): self
    {
        return new self(sprintf(
            'Template memakai header %s, jadi URL media wajib dikirim.',
            $format
        ));
    }
}
