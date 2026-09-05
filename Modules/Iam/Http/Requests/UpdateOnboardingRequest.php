<?php

namespace Modules\Iam\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT iam/me/onboarding { status: skipped | completed | null }.
 *
 * null is a legitimate value, not a missing one: "Buka lagi" in the account
 * menu resets the decision so the guide pops up again at the next login
 * (owner's request, 5 Sep 2026: the choice is remembered — and therefore has
 * to be un-rememberable by the person it belongs to). `present` keeps an
 * empty body from being read as a reset by accident.
 */
class UpdateOnboardingRequest extends FormRequest
{
    public const STATUSES = ['skipped', 'completed'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['present', 'nullable', 'string', Rule::in(self::STATUSES)],
        ];
    }

    public function messages(): array
    {
        $choices = implode(' atau ', self::STATUSES);

        return [
            'status.present' => 'Status onboarding harus dikirim: '.$choices.', atau kosong untuk menampilkan panduan lagi.',
            'status.in' => 'Status onboarding hanya boleh '.$choices.', atau kosong untuk menampilkan panduan lagi.',
            'status.string' => 'Status onboarding hanya boleh '.$choices.', atau kosong untuk menampilkan panduan lagi.',
        ];
    }
}
