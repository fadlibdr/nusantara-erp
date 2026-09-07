<?php

namespace Tests\Feature\Core;

use Modules\Core\Support\ApprovalPolicy;
use Tests\ErpTestCase;

/**
 * SEL YANG DICABUT TIDAK MENINGGALKAN KUNCI YANG TIDAK ADA YANG MEMBACA
 * (verifikasi F-1 putaran 2, 7 Sep 2026).
 *
 * Putaran pertama mencabut sel "mode" dan "ambang tingkat ketiga" dari layar
 * untuk setiap jenis yang jalur persetujuannya tidak dapat berhenti di tengah,
 * tetapi meninggalkan kuncinya di config/erp.php. Akibatnya sebuah kalimat yang
 * salah kepada operatornya: karena config MENDEFINISIKAN kunci itu sementara
 * registri tidak lagi menggambarkannya, rejectUnknownKeys mengambil cabang
 * "tetapan saat instalasi" dan menjawab
 *
 *   PUT {approvals.ar_invoice.mode: 'extra_level'}
 *     → 422 "…ditetapkan saat instalasi di config/erp.php dan tidak dapat
 *        diubah dari layar ini; mengubahnya membutuhkan deploy."
 *
 * DIUKUR bahwa deploy itu tidak akan mengubah apa pun: dengan
 * config('erp.approvals.ar_invoice.mode') = 'extra_level',
 * ApprovalPolicy::forType('ar_invoice')->mode TETAP single_director —
 * forType() memaksanya untuk setiap jenis yang modenya terkunci atau yang
 * tidak menyatakan approvalLadderKey(). Diukur 7 Sep 2026: 13 dari 14 jenis
 * yang membawa kunci mode di config, yaitu 26 kunci mati.
 *
 * Dua hal dijaga di sini, dan yang kedua adalah yang menahannya tetap benar
 * pada jenis dokumen ke-29: kunci config ADA persis untuk jenis yang
 * resolvernya benar-benar membacanya, dan penolakannya menyebut sebab yang
 * sebenarnya alih-alih mengirim operatornya ke sebuah deploy yang tidak bisa
 * bekerja.
 */
class WithdrawnApprovalModeKeysTest extends ErpTestCase
{
    /**
     * KUNCI MODE ADA TEPAT DI JENIS YANG MEMBACANYA — diturunkan dari registri,
     * bukan dari daftar: sebuah jenis baru yang ikut jenjang besok MENDAPAT
     * kuncinya, dan sebuah jenis baru yang tidak, tidak.
     */
    public function test_the_config_declares_a_mode_key_exactly_where_the_resolver_reads_one(): void
    {
        $reads = [];
        $declares = [];

        foreach (ApprovalPolicy::documentTypes() as $type) {
            $owner = ApprovalPolicy::settingType($type);

            if (! ApprovalPolicy::modeIsLocked($type) && ApprovalPolicy::supportsExtraLevel($type)) {
                $reads[$owner] = true;
            }

            if (config()->has("erp.approvals.{$owner}.mode")) {
                $declares[$owner] = true;
            }
        }

        ksort($reads);
        ksort($declares);

        $this->assertSame(
            array_keys($reads),
            array_keys($declares),
            'config/erp.php mendeklarasikan kunci approvals.<jenis>.mode untuk jenis yang resolvernya '
                .'TIDAK PERNAH membaca (forType memaksanya single_director), atau tidak mendeklarasikannya '
                .'untuk jenis yang membacanya.',
        );

        foreach (array_keys($declares) as $owner) {
            $this->assertTrue(
                config()->has("erp.approvals.{$owner}.third_level_threshold"),
                "approvals.{$owner}.mode ada tetapi ambang tingkat ketiganya tidak.",
            );
        }

        // Dan kebalikannya: tidak ada ambang tingkat ketiga tanpa modenya.
        foreach (array_keys(config('erp.approvals')) as $type) {
            if (! is_array(config("erp.approvals.{$type}"))) {
                continue;
            }

            $this->assertSame(
                config()->has("erp.approvals.{$type}.mode"),
                config()->has("erp.approvals.{$type}.third_level_threshold"),
                "approvals.{$type}: mode dan ambang tingkat ketiga harus ada atau tidak ada BERSAMA — "
                    .'yang kedua hanya dibaca pada mode "tambahan tingkat".',
            );
        }
    }

    /**
     * DAN INILAH YANG DIUKUR: sebuah deploy yang mengubah kunci itu tidak
     * mengubah apa pun. Kalimat lama menyuruh operatornya melakukannya.
     */
    public function test_a_deploy_that_sets_the_mode_would_change_nothing(): void
    {
        config()->set('erp.approvals.ar_invoice.mode', ApprovalPolicy::MODE_EXTRA_LEVEL);
        config()->set('erp.approvals.ar_invoice.third_level_threshold', 1);

        $this->assertSame(ApprovalPolicy::MODE_SINGLE_DIRECTOR, ApprovalPolicy::forType('ar_invoice')->mode);
        $this->assertNull(ApprovalPolicy::forType('ar_invoice')->thirdLevelThreshold);
    }

    /**
     * PENOLAKANNYA MENYEBUT SEBAB YANG SEBENARNYA, dan tidak menyebut deploy.
     */
    public function test_the_endpoint_refuses_a_withdrawn_cell_without_promising_a_deploy(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->putJson('/api/core/settings', ['settings' => ['approvals.ar_invoice.mode' => 'extra_level']])
            ->assertStatus(422);

        // Diambil dari larik, bukan lewat jalur bertitik: data_get() Laravel
        // tidak mengenal titik yang di-escape, dan kunci galatnya SENDIRI
        // mengandung titik.
        $message = $this->messageFor($response->json('errors'), 'approvals.ar_invoice.mode');

        $this->assertNotSame('', $message, json_encode($response->json('errors')));
        // Kalimat lamanya, dan pintu yang tidak boleh dibuka lagi.
        $this->assertStringNotContainsString('membutuhkan deploy', $message);
        $this->assertStringNotContainsString('ditetapkan saat instalasi', $message);
        // Deploy DISEBUT — untuk menutupnya, bukan untuk menyuruh ke sana.
        $this->assertStringContainsString('maupun lewat deploy', $message);
        $this->assertStringContainsString('Invoice', $message);
        $this->assertStringContainsString('satu penyetuju', $message);
    }

    /**
     * Jenis yang modul menegakkan ambangnya sendiri mendapat sebabnya sendiri —
     * bukan "tidak dapat berhenti di tengah" melainkan "penegaknya tidak
     * mengenal mode kedua".
     */
    public function test_a_module_gated_type_is_refused_with_its_own_reason(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->putJson('/api/core/settings', ['settings' => ['approvals.purchase_order.mode' => 'extra_level']])
            ->assertStatus(422);

        $message = $this->messageFor($response->json('errors'), 'approvals.purchase_order.mode');

        $this->assertStringNotContainsString('membutuhkan deploy', $message);
        $this->assertStringContainsString('needs_director_approval', $message);
    }

    /**
     * DAN KUNCI YANG MEMANG TIDAK DIKENAL TETAP DIJAWAB "tidak dikenal" —
     * cabang barunya tidak boleh menelan salah ketik.
     */
    public function test_a_genuinely_unknown_key_still_says_so(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->putJson('/api/core/settings', ['settings' => ['approvals.tidak_ada_jenis_ini.mode' => 'extra_level']])
            ->assertStatus(422);

        $this->assertStringContainsString(
            'tidak dikenal',
            $this->messageFor($response->json('errors'), 'approvals.tidak_ada_jenis_ini.mode'),
        );
    }

    /** @param  array<string, list<string>>|null  $errors */
    private function messageFor(?array $errors, string $key): string
    {
        return (string) (($errors['settings.'.$key] ?? [])[0] ?? '');
    }
}
