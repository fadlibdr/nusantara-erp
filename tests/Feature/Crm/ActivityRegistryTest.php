<?php

namespace Tests\Feature\Crm;

use Modules\Crm\Enums\ActivityType;
use Modules\Crm\Support\ActivityDocuments;
use Tests\ErpTestCase;

/**
 * Daftar dokumen ber-aktivitas hidup DUA KALI: di PHP, tempatnya memutuskan apa
 * yang diterima API, dan di SPA, tempatnya memutuskan layar mana yang memasang
 * kartunya. Tidak ada langkah build yang membagikannya, jadi uji ini membaca
 * keduanya dan gagal saat berbeda — alasan yang sama persis dengan
 * AttachmentRegistryTest, dan penyimpangannya sama-sama senyap ke dua arah:
 * slug yang hanya ada di SPA adalah kartu yang setiap permintaannya 422, dan
 * slug yang hanya ada di PHP adalah dokumen yang diam-diam tidak bisa mencatat
 * satu pun aktivitas walau server menerimanya.
 */
class ActivityRegistryTest extends ErpTestCase
{
    public function test_the_php_and_javascript_registries_list_the_same_documents(): void
    {
        $php = ActivityDocuments::slugs();
        sort($php);

        $js = $this->javascriptSlugs();
        sort($js);

        $this->assertSame($php, $js,
            "public/app/js/views/activities.js ACTIVITY_DOCUMENTS menyimpang dari Modules\\Crm\\Support\\ActivityDocuments.\n"
            .'Hanya di PHP: '.implode(', ', array_diff($php, $js))."\n"
            .'Hanya di JS:  '.implode(', ', array_diff($js, $php)));
    }

    /** Jenis pendeknya juga harus sama — itulah yang menyeberangi kawat. */
    public function test_the_two_registries_agree_on_the_short_types(): void
    {
        $js = $this->javascriptMap();

        foreach (ActivityDocuments::types() as $type) {
            $this->assertContains($type, array_values($js),
                "jenis [{$type}] tidak dikenal kartu SPA — dokumennya tidak akan pernah memasang kartu aktivitas");
        }
    }

    /** Setiap slug ber-aktivitas benar-benar punya layar yang merendernya. */
    public function test_every_slug_is_rendered_by_some_screen(): void
    {
        $schema = (string) file_get_contents(public_path('app/js/schema.js'));

        foreach (ActivityDocuments::slugs() as $slug) {
            $this->assertStringContainsString("  '{$slug}': {", $schema,
                "slug [{$slug}] tidak punya layar; kartu aktivitasnya tidak akan pernah tergambar");
        }
    }

    /** Kartunya benar-benar dipasang layar dokumen generik. */
    public function test_the_card_is_wired_into_the_detail_screen(): void
    {
        $detail = (string) file_get_contents(public_path('app/js/views/detail.js'));

        $this->assertStringContainsString("import { activitiesCard } from './activities.js';", $detail);
        $this->assertStringContainsString('activitiesCard(key, record.id, def.module)', $detail);
    }

    /** Jenis aktivitas di SPA = enum-nya di server, tanpa yang mengarang. */
    public function test_the_activity_types_match_the_enum(): void
    {
        $source = (string) file_get_contents(public_path('app/js/views/activities.js'));

        preg_match('/const TYPES = \[(.*?)\];/s', $source, $match);
        preg_match_all("/value: '([a-z_]+)'/", $match[1] ?? '', $values);

        $js = $values[1];
        sort($js);
        $php = ActivityType::values();
        sort($php);

        $this->assertSame($php, $js, 'pilihan jenis aktivitas di SPA menyimpang dari ActivityType');
    }

    /** Kartu kosong MENGATAKAN dirinya kosong — bukan "0 aktivitas". */
    public function test_the_empty_card_says_so(): void
    {
        $source = (string) file_get_contents(public_path('app/js/views/activities.js'));

        $this->assertStringContainsString('Belum ada aktivitas dicatat', $source);

        // Kalimat "0 aktivitas" hanya boleh ada di docblock yang MELARANGNYA —
        // tidak pernah di badan kode yang merender kartunya.
        $body = substr($source, (int) strpos($source, 'import {'));
        $this->assertStringNotContainsString('0 aktivitas', $body);
    }

    /**
     * KARTUNYA MEMBACA AMPLOPNYA, dan mengaku bila memotong.
     *
     * `api.get` membuang amplop dan hanya memulangkan `data`, jadi kartu yang
     * memakainya menghitung ringkasannya dari baris yang KEBETULAN termuat.
     * Diukur 8 Sep 2026 pada satu prospek berisi 110 aktivitas terbuka: kartu
     * Aktivitas berbunyi "100 terbuka." dan menggambar 100 baris tanpa satu
     * kalimat pun yang mengakui pemotongan, sementara kartu papan untuk
     * prospek yang sama berbunyi "110 aktivitas terbuka" (server withCount).
     * Dua layar di satu paket, satu di antaranya berbohong.
     */
    public function test_the_card_reads_the_envelope_and_admits_truncation(): void
    {
        $source = (string) file_get_contents(public_path('app/js/views/activities.js'));
        $body = substr($source, (int) strpos($source, 'import {'));

        $this->assertStringContainsString("api.list('crm/activities'", $body,
            'kartu memakai api.get: amplopnya dibuang dan meta.total tidak pernah sampai ke ringkasannya');
        $this->assertStringContainsString('meta.total', $body,
            'jumlah yang dipajang tidak datang dari server');
        $this->assertStringContainsString('digambar', $body,
            'pemotongan tidak diakui satu kalimat pun — sebuah daftar yang berhenti diam-diam '
            .'adalah cara orang mengira ia sudah melihat semuanya');
    }

    /** @return list<string> */
    private function javascriptSlugs(): array
    {
        return array_keys($this->javascriptMap());
    }

    /** @return array<string, string> slug => jenis pendek */
    private function javascriptMap(): array
    {
        $source = (string) file_get_contents(public_path('app/js/views/activities.js'));

        if (preg_match('/export const ACTIVITY_DOCUMENTS = \{(.*?)\};/s', $source, $match) !== 1) {
            $this->fail('blok ACTIVITY_DOCUMENTS tidak terbaca di activities.js');
        }

        preg_match_all("/'([^']+)': '([^']+)'/", $match[1], $pairs, PREG_SET_ORDER);

        return array_combine(array_column($pairs, 1), array_column($pairs, 2));
    }
}
