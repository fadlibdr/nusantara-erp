<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LogicException;
use Modules\Core\Http\ApiController;
use Modules\Finance\Models\ArRetention;
use Modules\Finance\Services\ArRetentionService;

/**
 * Piutang retensi — withheld from termin invoices, collectible after the
 * warranty period.
 *
 * Both endpoints are new. The table has been written to since the first AR
 * invoice was approved and read by nothing: there was no way to see what was
 * outstanding, and no way to clear it.
 */
class ArRetentionController extends ApiController
{
    public function __construct(private readonly ArRetentionService $retentions) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:prj_projects,id'],
        ]);

        return $this->ok($this->retentions->outstanding($data['project_id'] ?? null));
    }

    public function release(Request $request, ArRetention $arRetention): JsonResponse
    {
        $data = $request->validate([
            'received_on' => ['required', 'date'],
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('fin_bank_accounts', 'id')->whereNull('deleted_at'),
            ],
        ]);

        try {
            return $this->ok(
                $this->retentions->release(
                    $arRetention,
                    $data['received_on'],
                    (int) $data['bank_account_id'],
                    $request->user()?->id,
                ),
                'Retensi dicairkan.',
            );
        } catch (LogicException $e) {
            return $this->error($e->getMessage());
        }
    }
}
