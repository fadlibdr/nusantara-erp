<?php

namespace Modules\ServiceDesk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_name.required' => 'Tulis nama orang di pihak pelanggan yang diminta menilai.',
            'expires_at.after' => 'Masa berlaku tautan harus di masa depan.',
        ];
    }
}
