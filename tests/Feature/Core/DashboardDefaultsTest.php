<?php

namespace Tests\Feature\Core;

use Modules\Core\Support\SpaWidgets;
use Modules\Iam\Database\Seeders\RoleSeeder;
use Tests\ErpTestCase;

/**
 * Susunan dasbor bawaan per peran (Fase 1 / P1-D, T4.1) — dan metrik "0 peran
 * tanpa ubin", dijadikan uji.
 *
 * Target metrik Fase 1 menuntut tidak ada peran yang mendarat di dasbor kosong.
 * P1-C mencapainya untuk launcher dengan cara MENGUKUR: masuk sebagai 12 akun
 * demo satu per satu dan menghitung ubinnya (2 peran tanpa ubin → 0). Ukuran
 * seperti itu benar pada hari ia diambil dan basi pada sunting berikutnya —
 * seseorang yang menaikkan gerbang izin sebuah widget dari `prj.view` ke
 * `prj.approve` mengosongkan dasbor site-manager tanpa satu uji pun merah.
 *
 * Di sini pertanyaannya dijawab dari DUA sumber yang tidak saling menyalin:
 * daftar peran dan izinnya dari `RoleSeeder::intended()` (yang benar-benar
 * diseed), susunan bawaan dan gerbang izin widget dari `registry.js` (yang
 * benar-benar digambar SPA). Tidak ada daftar ketiga yang dipelihara tangan di
 * berkas ini.
 */
class DashboardDefaultsTest extends ErpTestCase
{
    /** @return array<string, list<string>> peran => izin yang dipegangnya. */
    private function roles(): array
    {
        return RoleSeeder::intended();
    }

    /** Apakah peran ini boleh melihat widget itu? */
    private function sees(string $widgetId, array $permissions): bool
    {
        $needed = SpaWidgets::permissionsOf($widgetId);

        // Tanpa gerbang: siapa pun yang punya sesi (server yang menyaring isinya).
        if ($needed === []) {
            return true;
        }

        foreach ($needed as $permission) {
            if ($permission === '*.approve') {
                // Cermin ANY_APPROVE: pemegang `<prefix>.approve` mana pun.
                foreach ($permissions as $held) {
                    if (str_ends_with($held, '.approve')) {
                        return true;
                    }
                }

                continue;
            }

            // Daftar berarti "salah satu saja cukup" (session.can atas array).
            if (in_array($permission, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    public function test_every_seeded_role_has_a_default_layout(): void
    {
        $roles = array_keys($this->roles());
        $defaults = array_keys(SpaWidgets::defaults());

        sort($roles);
        sort($defaults);

        $this->assertNotSame([], $defaults, 'Tidak satu pun susunan bawaan terbaca dari registry.js.');
        $this->assertSame($roles, $defaults,
            'Daftar peran di RoleSeeder dan susunan bawaan di registry.js berbeda. Peran tanpa baris DEFAULTS '
            .'mendapat susunan cadangan dari izinnya — yang bekerja, tetapi bukan susunan yang dipikirkan '
            .'siapa pun untuk pekerjaannya.');
    }

    public function test_every_default_entry_names_a_real_widget_and_size(): void
    {
        $ids = SpaWidgets::ids();
        $this->assertNotSame([], $ids, 'Katalog widget tidak terbaca — uji ini tidak membuktikan apa pun.');

        foreach (SpaWidgets::defaults() as $role => $entries) {
            $this->assertNotSame([], $entries, "Susunan bawaan peran [{$role}] kosong.");

            $seen = [];
            foreach ($entries as $entry) {
                [$id, $size] = explode(':', $entry);

                $this->assertContains($id, $ids, "Susunan bawaan [{$role}] menyebut widget [{$id}] yang tidak ada di katalog.");
                $this->assertContains($size, SpaWidgets::SIZES, "Susunan bawaan [{$role}] memakai ukuran [{$size}] yang tidak dikenal.");

                $this->assertArrayNotHasKey($id, $seen, "Susunan bawaan [{$role}] menyebut widget [{$id}] dua kali; hanya yang pertama digambar.");
                $seen[$id] = true;
            }
        }
    }

    /**
     * METRIK FASE 1: 0 peran tanpa ubin. Setiap peran, dengan izin peran itu
     * sendiri, mendapat sedikitnya satu widget.
     */
    public function test_no_seeded_role_lands_on_an_empty_dashboard(): void
    {
        $defaults = SpaWidgets::defaults();

        foreach ($this->roles() as $role => $permissions) {
            $entries = $defaults[$role] ?? [];
            $visible = array_values(array_filter(
                $entries,
                fn (string $entry): bool => $this->sees(explode(':', $entry)[0], $permissions),
            ));

            $this->assertNotSame([], $visible,
                "Peran [{$role}] tidak dapat melihat satu pun widget dalam susunan bawaannya — dasbornya kosong "
                .'pada hari pertama, yang persis dilarang target metrik Fase 1.');
        }
    }

    /**
     * …dan tidak ada entri bawaan yang MATI: sebuah widget yang perannya tidak
     * boleh lihat akan disaring resolveLayout tanpa suara, sehingga susunan
     * yang tertulis delapan kartu tergambar lima dan tidak ada yang tahu
     * kenapa.
     */
    public function test_no_default_entry_is_invisible_to_the_role_it_was_written_for(): void
    {
        $defaults = SpaWidgets::defaults();

        foreach ($this->roles() as $role => $permissions) {
            foreach ($defaults[$role] ?? [] as $entry) {
                $id = explode(':', $entry)[0];
                $this->assertTrue($this->sees($id, $permissions),
                    "Susunan bawaan [{$role}] memuat widget [{$id}] yang izinnya tidak dipegang peran itu — "
                    .'baris mati yang tidak akan pernah digambar.');
            }
        }
    }

    /**
     * Bagian yang menolak: buktikan sees() bisa berkata TIDAK. Tanpa ini kedua
     * uji di atas lolos untuk susunan apa pun.
     */
    public function test_the_visibility_rule_can_refuse(): void
    {
        // 'payroll' bergerbang hr.view; sebuah peran tanpa izin apa pun tidak
        // melihatnya, sementara 'tenggat' (tanpa gerbang) tetap terlihat.
        $this->assertFalse($this->sees('payroll', []));
        $this->assertTrue($this->sees('payroll', ['hr.view']));
        $this->assertTrue($this->sees('tenggat', []));

        // '*.approve' adalah predikat, bukan nama izin.
        $this->assertFalse($this->sees('inbox', ['fin.view']));
        $this->assertTrue($this->sees('inbox', ['fin.approve']));

        // Daftar izin: salah satu saja cukup.
        $this->assertTrue($this->sees('ringkasan-uang', ['fin.view']));
        $this->assertTrue($this->sees('ringkasan-uang', ['prj.view']));
        $this->assertFalse($this->sees('ringkasan-uang', ['svc.view']));
    }

    /**
     * Setiap widget katalog dipakai sedikitnya satu susunan bawaan.
     *
     * Sebuah widget yang tidak pernah muncul pada hari pertama peran mana pun
     * hanya bisa ditemukan orang yang membuka laci "Atur dasbor" dan membaca 19
     * baris — yaitu hampir tidak pernah. Itu bukan salah, tetapi harus menjadi
     * KEPUTUSAN: uji ini yang memaksanya ditulis di sini bila kelak ada.
     */
    public function test_every_catalogued_widget_appears_in_some_default_layout(): void
    {
        $used = [];
        foreach (SpaWidgets::defaults() as $entries) {
            foreach ($entries as $entry) {
                $used[explode(':', $entry)[0]] = true;
            }
        }

        $orphans = array_values(array_diff(SpaWidgets::ids(), array_keys($used)));

        $this->assertSame([], $orphans,
            'Widget berikut tidak ada di susunan bawaan peran mana pun, jadi hampir tidak ada yang akan '
            .'menemukannya: '.implode(', ', $orphans));
    }
}
