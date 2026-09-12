<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\ApiController;
use Modules\Finance\Services\BankInboxService;

/**
 * Ledger folder terpantau (P-3c). GET = fin.view; POST run = fin.create (sama
 * dengan izin impor rekening koran — tombol "Periksa sekarang" menjalankan
 * impor yang sama). Muatan tidak pernah memuat jalur absolut server.
 */
class BankInboxController extends ApiController
{
    public function __construct(private readonly BankInboxService $inbox) {}

    public function show(): JsonResponse
    {
        return $this->ok($this->inbox->status());
    }

    public function run(): JsonResponse
    {
        $summary = $this->inbox->scan();
        $c = $summary['counts'];

        if ($summary['locked']) {
            return $this->ok(['summary' => $summary] + $this->inbox->status(), BankInboxService::LOCKED_NOTE);
        }

        return $this->ok(
            ['summary' => $summary] + $this->inbox->status(),
            $summary['folder_exists']
                ? sprintf('%d berkas diperiksa: %d diimpor, %d gagal, %d salinan, %d diabaikan.', $c['seen'], $c['imported'], $c['failed'], $c['duplicate'], $c['ignored'])
                : BankInboxService::FOLDER_MISSING_NOTE,
        );
    }
}
