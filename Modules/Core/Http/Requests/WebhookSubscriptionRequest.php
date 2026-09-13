<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Core\Support\AttachableDocuments;
use Modules\Core\Support\WebhookPayload;
use Modules\Core\Support\WebhookUrl;

/**
 * POST/PUT core/webhooks — bentuk langganan, dan pintu pertama kebijakan SSRF.
 *
 * URL diperiksa DI SINI (bentuk + alamat internal literal, tanpa DNS) supaya
 * orang yang mengetiknya mendapat kalimat 422 seketika, dan DIPERIKSA LAGI di
 * dalam job tepat sebelum mengirim (dengan DNS) — karena nama yang hari ini
 * publik bisa besok menunjuk ke 127.0.0.1. Dua pemeriksaan, satu kelas:
 * `WebhookUrl`.
 */
class WebhookSubscriptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'url' => ['required', 'string', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in(WebhookPayload::EVENTS)],
            // null / tidak dikirim = SETIAP jenis dokumen.
            'document_types' => ['nullable', 'array'],
            'document_types.*' => ['required', 'string', Rule::in(AttachableDocuments::slugs())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'Nama langganan',
            'url' => 'URL penerima',
            'events' => 'Peristiwa',
            'document_types' => 'Jenis dokumen',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $url = $this->input('url');

            if (! is_string($url) || $url === '') {
                return;
            }

            try {
                WebhookUrl::assertShape(trim($url));
            } catch (LogicException $e) {
                $validator->errors()->add('url', $e->getMessage());
            }
        });
    }
}
