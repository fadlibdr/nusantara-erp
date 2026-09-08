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

        $this->assertMatchesRegularExpression(
            "/import \{[^}]*\bactivitiesCard\b[^}]*\} from '\.\/activities\.js';/", $detail,
            'kartu aktivitas tidak diimpor layar dokumen generik');
        $this->assertStringContainsString('activitiesCard(key, record.id, def.module)', $detail);

        /* Registrinya dipakai SEKALI LAGI di layar itu, dibalik, untuk
           menautkan baris "Dokumen" sebuah aktivitas ke induknya — satu daftar,
           bukan dua yang bisa hanyut. */
        $this->assertStringContainsString('ACTIVITY_DOCUMENTS', $detail,
            'peta jenis→layar dirakit di luar registri: sebuah daftar kedua yang akan hanyut');
    }

    /**
     * LAYAR DETAIL AKTIVITAS MENYEBUT INDUKNYA — dan menautkannya.
     *
     * Dua mekanisme detail.js saling meniadakan sampai 8 Sep 2026:
     * `NAME_SHADOWED.document_id = 'document_label'` menyembunyikan baris id
     * mentahnya, dan penyaring panel Informasi membuang setiap kunci yang
     * berakhiran `_label` — jadi KEDUANYA lenyap. Diukur di peramban pada
     * #/d/crm/activities/8: seluruh pasangan panel Informasi tidak memuat satu
     * baris "Dokumen" pun, dan satu-satunya tautan ke prospeknya di halaman itu
     * berasal dari daftar "Terakhir dibuka" di bilah samping. Antrean kerja
     * yang barisnya tidak bisa dibuka sampai ke pekerjaannya adalah antrean
     * buntu — padahal ActivityController::withDocumentLabels dibuat justru
     * supaya namanya ada.
     */
    public function test_the_activity_detail_screen_names_and_links_its_parent(): void
    {
        $detail = (string) file_get_contents(public_path('app/js/views/detail.js'));

        if (preg_match('/const NAME_SHADOWED = \{(.*?)\n\};/s', $detail, $match) !== 1) {
            $this->fail('blok NAME_SHADOWED tidak terbaca di detail.js');
        }

        $this->assertStringNotContainsString('document_id:', $match[1],
            'document_id dibayangi document_label, yang sendirinya dibuang penyaring _label — baris "Dokumen" hilang seluruhnya');
        $this->assertStringContainsString("document_id: 'Dokumen'", $detail,
            'baris induknya tidak punya label Indonesia; titleize() akan menuliskan "Document Id"');
        $this->assertStringContainsString('#/d/${slug}/${value}', $detail,
            'nama induknya tergambar tanpa jalan menuju dokumennya');
        $this->assertStringContainsString("key === 'document_id' && record.document_label", $detail,
            'barisnya membaca `${key}_label` (document_id_label) yang tidak pernah ada — layarnya memajang id mentah');
    }

    /**
     * "Diselesaikan oleh" DUA KALI, sekali sebagai id mentah.
     *
     * LABELS memberi label yang sama kepada done_by_id dan done_by_name tanpa
     * memasangkan keduanya, jadi panel Informasi sebuah aktivitas selesai
     * memajang "Diselesaikan oleh = 1" tepat di atas "Diselesaikan oleh =
     * Administrator Sistem" (diukur di peramban 8 Sep 2026). Id mentah di layar
     * adalah persis yang NAME_SHADOWED ada untuk mencegahnya.
     */
    public function test_the_person_who_finished_it_is_named_once(): void
    {
        $detail = (string) file_get_contents(public_path('app/js/views/detail.js'));

        if (preg_match('/const NAME_SHADOWED = \{(.*?)\n\};/s', $detail, $match) !== 1) {
            $this->fail('blok NAME_SHADOWED tidak terbaca di detail.js');
        }

        $this->assertStringContainsString("done_by_id: 'done_by_name'", $match[1],
            'id penyelesai tidak dibayangi namanya: panel Informasi menuliskan label yang sama dua kali, '
            .'sekali sebagai id pengguna mentah');
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
