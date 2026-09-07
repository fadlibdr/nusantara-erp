<?php

namespace Modules\Iam\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Support\ApprovableDocuments;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Module prefixes = table prefix without underscore (see CONVENTIONS.md §6).
     */
    public const PREFIXES = [
        'core', 'iam', 'crm', 'inv', 'ast', 'est',
        'prj', 'prc', 'scm', 'hr', 'fin', 'svc',
        'eng', // P1-ENG: Engineering (eng_ tables, api/engineering)
        'qc',  // P1-QC: Quality (qc_ tables, api/quality)
    ];

    public const ACTIONS = ['view', 'create', 'update', 'delete', 'approve', 'post'];

    /**
     * Persetujuan level direktur, di atas izin approve biasa.
     *
     * SAMPAI F-1 hanya dua: prc dan scm, dua dokumen yang mencap
     * needs_director_approval (PO dari Rp 100 juta, SPK dari Rp 200 juta).
     * Alasan menahan sisanya masih benar dan masih dipegang — "sebuah izin
     * yang tidak diperiksa apa pun terbaca sebagai kendali yang ada" — tetapi
     * yang berubah adalah apa yang memeriksanya: matriks persetujuan
     * memberikan pemilik sebuah ambang untuk SETIAP jenis dokumen, dan sebuah
     * ambang yang menuntut izin yang tidak pernah dicetak adalah dokumen yang
     * tidak bisa disetujui siapa pun.
     *
     * MAKA DAFTARNYA DITURUNKAN, bukan diketik: satu izin per AWALAN yang
     * memiliki setidaknya satu dokumen di ApprovableDocuments. Diukur dari
     * registri 7 Sep 2026: sepuluh awalan (crm, est, prj, eng, qc, prc, inv,
     * scm, fin, hr) atas 28 jenis dokumen — jadi DELAPAN izin baru, bukan
     * sembilan seperti taksiran ROADMAP. Empat awalan lain (core, iam, ast,
     * svc) TIDAK mendapatkannya: tidak satu pun memiliki dokumen ber-submit →
     * approve, jadi tidak ada baris matriks yang bisa menuntutnya.
     *
     * Diturunkan berarti tidak bisa menyimpang: jenis dokumen ke-29 dari modul
     * baru membawa izin direkturnya sendiri, dan erp:permission-check
     * menghitung yang sama karena ia membaca fungsi ini.
     *
     * @return list<string>
     */
    public static function directorApprovals(): array
    {
        $names = [];

        foreach (ApprovableDocuments::all() as $entry) {
            $names["{$entry['prefix']}.approve-director"] = true;
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /*
     * NAMED SEAM (kas kecil): a dedicated fin.cashier permission for drawer
     * custodians is a real future need — today a custodian is provisioned
     * with a custom role holding fin.view/create/update, and fin.create also
     * lets them DRAFT journals/bills/payments (inert under maker-checker:
     * posting/approving needs fin.approve|fin.post they do not hold), a
     * widening that belongs in the release note. It is deliberately NOT
     * minted here yet, for the directorApprovals() reason above: nothing
     * checks it, and an unchecked permission on the roles screen reads as a
     * control that exists. Mint it together with the route-gate change that
     * enforces it; the in-service custodian-identity guard
     * (PettyCashVoucherService::assertCustodian) stays either way — the
     * permission only narrows the route gate.
     */

    /**
     * Every permission name this seeder mints — PREFIXES × ACTIONS plus
     * directorApprovals() — derived, never counted by hand.
     *
     * The one list run() and erp:permission-check both read. Production admin
     * held 74 of these on 4 Sep 2026 (HASIL-UJI §6.2 P-1): eng.* and qc.*
     * were added to PREFIXES for P1-ENG/P1-QC and nothing re-ran this seeder
     * against the live database, so two shipped packages were unreachable by
     * anyone. A check that carried its own "86" would have drifted the same
     * way the next time a prefix is added; reading the constants cannot.
     *
     * @return list<string>
     */
    public static function expected(): array
    {
        $names = [];

        foreach (self::PREFIXES as $prefix) {
            foreach (self::ACTIONS as $action) {
                $names[] = "{$prefix}.{$action}";
            }
        }

        return array_merge($names, self::directorApprovals());
    }

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::expected() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }
}
