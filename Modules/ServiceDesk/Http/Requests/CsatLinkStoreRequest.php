<?php

namespace Modules\ServiceDesk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ServiceDesk\Services\CsatService;

/**
 * Menerbitkan undangan CSAT.
 *
 * recipient_email OPSIONAL dan bukan alamat kirim: sistem ini tidak mengirim
 * satu surel pun (MAIL_MAILER=log di kedua .env). Ia arsip untuk siapa
 * undangan diterbitkan — persis peran kolom `email` pada persetujuan eksternal.
 */
class CsatLinkStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:120'],
            'recipient_email' => ['nullable', 'email', 'max:150'],
            // LANTAI DAN PLAFON. `after:now` sendirian membiarkan penerbit
            // meminta tautan yang berlaku sampai tahun 9999 — sebuah tautan
            // "sekali pakai yang kedaluwarsa" yang tidak pernah kedaluwarsa
            // (terukur 10 Sep 2026: HTTP 201, kartu tiket "berlaku s/d
            // 31 Des 9999"). Plafonnya hidup di CsatService bersama bawaannya,
            // bukan di sini, supaya layar bisa membacanya lewat meta.
            'expires_at' => ['nullable', 'date', 'after:now',
                'before:'.now()->addDays(CsatService::MAX_VALIDITY_DAYS)->toDateTimeString()],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_name.required' => 'Tulis nama orang di pihak pelanggan yang diminta menilai.',
            'expires_at.after' => 'Masa berlaku tautan harus di masa depan.',
            'expires_at.before' => sprintf(
                'Masa berlaku tautan penilaian paling lama %d hari — undangan yang berlaku lebih lama '
                .'dari itu bukan lagi tautan yang kedaluwarsa. Kosongkan untuk %d hari.',
                CsatService::MAX_VALIDITY_DAYS,
                CsatService::DEFAULT_VALIDITY_DAYS,
            ),
        ];
    }
}
