<?php

namespace Tests\Feature\Core;

use Tests\ErpTestCase;

/**
 * Kabel SPA untuk kedua layar P-3d, dan KALIMAT yang tidak boleh disusun klien.
 *
 * Pelajaran 6 kampanye ini: "kalimat yang menjanjikan sesuatu yang tidak
 * terjadi adalah cacat paling sering fase ini". Kedua layar paket ini penuh
 * janji kepada sistem lain — "token ini tidak akan ditampilkan lagi", "kiriman
 * ini terkirim", "langganan ini dinonaktifkan karena…" — dan setiap satu di
 * antaranya hanya benar bila SERVER yang mengucapkannya.
 *
 * SAPUAN FRASA DIBATASI pada dua berkas paket ini (pelajaran 4), tak peka huruf
 * besar, dan komentar yang perlu menyebut frasa terlarang memakainya di dalam
 * «guillemet» supaya bisa dibaca manusia tanpa memerahkan uji.
 */
class ApiTokenAndWebhookSpaTest extends ErpTestCase
{
    private const WEBHOOK_VIEW = 'public/app/js/views/webhook.js';

    private const PROFIL_VIEW = 'public/app/js/views/profil.js';

    /**
     * Kalimat yang HARUS datang dari server, dan janji yang tidak boleh ada
     * sama sekali.
     *
     * @var list<string>
     */
    private const FORBIDDEN = [
        // Kebenarannya milik server: yang membuatnya benar adalah bahwa server
        // hanya menyimpan sidik jari tokennya.
        'tidak akan ditampilkan lagi',
        'tidak akan ditampilkan kembali',
        // Janji keamanan yang tidak dipegang kode mana pun.
        'tidak bisa disalahgunakan',
        'sepenuhnya aman',
        'terenkripsi ujung ke ujung',
        'dijamin sampai',
        'pasti diterima',
    ];

    private function source(string $path): string
    {
        $full = base_path($path);

        $this->assertFileExists($full);

        return (string) file_get_contents($full);
    }

    /** Isi berkas tanpa komentar — frasa di dalam komentar bukan janji kepada siapa pun. */
    private function withoutComments(string $source): string
    {
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

        return (string) preg_replace('#^\s*//.*$#m', '', $source);
    }

    public function test_the_webhook_screen_is_wired_into_the_shell(): void
    {
        $sw = $this->source('public/app/sw.js');
        $app = $this->source('public/app/js/app.js');
        $schema = $this->source('public/app/js/schema.js');

        // Setiap layar baru menambah satu baris di SHELL (CONVENTIONS §21):
        // kalau tidak, aplikasi tetap jalan daring dan setengah mati saat luring.
        $this->assertStringContainsString("'js/views/webhook.js',", $sw);

        // Versi cangkang naik pada rilis yang mengubah berkas cangkang —
        // tanpanya toast "Versi baru siap" tidak pernah muncul.
        $this->assertMatchesRegularExpression("/const SHELL_VERSION = '12';/", $sw);

        $this->assertStringContainsString("import { renderWebhook } from './views/webhook.js';", $app);
        $this->assertStringContainsString("route('webhook'", $app);
        $this->assertStringContainsString('renderWebhook(host)', $app);

        $this->assertStringContainsString("{ label: 'Webhook', route: 'webhook', perm: 'core.update' },", $schema);
    }

    public function test_the_two_screens_promise_only_what_the_server_said(): void
    {
        foreach ([self::WEBHOOK_VIEW, self::PROFIL_VIEW] as $path) {
            $code = mb_strtolower($this->withoutComments($this->source($path)));

            foreach (self::FORBIDDEN as $phrase) {
                $this->assertStringNotContainsString(
                    mb_strtolower($phrase),
                    $code,
                    "{$path} menuliskan sendiri kalimat «{$phrase}». Kalimat itu milik server "
                    .'(data.shown_once): yang membuatnya benar adalah apa yang server simpan, bukan apa yang layar tulis.',
                );
            }
        }
    }

    /** Kedua layar MEMBACA kalimat sekali-tampil dari muatan, bukan mengarangnya. */
    public function test_both_screens_read_the_shown_once_sentence_from_the_payload(): void
    {
        $this->assertStringContainsString('shown_once', $this->source(self::WEBHOOK_VIEW));
        $this->assertStringContainsString('shown_once', $this->source(self::PROFIL_VIEW));
    }

    /**
     * SEBAB KEGAGALAN DIGAMBAR APA ADANYA.
     *
     * Baris `failed` tanpa kalimat adalah baris yang menyuruh orang menebak;
     * baris `failed` dengan kalimat yang dikarang klien lebih buruk lagi.
     */
    public function test_the_delivery_log_draws_the_servers_reason(): void
    {
        $code = $this->source(self::WEBHOOK_VIEW);

        $this->assertStringContainsString('row.error', $code);
        $this->assertStringContainsString('webhook-error', $code);
        $this->assertStringContainsString('row.disabled_reason', $code);
    }

    /** Ability yang ditawarkan = izin pemanggil, dibaca dari server. */
    public function test_the_token_screen_offers_the_abilities_the_server_listed(): void
    {
        $code = $this->source(self::PROFIL_VIEW);

        $this->assertStringContainsString('available_abilities', $code);
        $this->assertStringContainsString('max_lifetime_days', $code);
        $this->assertStringContainsString('rate_limit_per_minute', $code);
        $this->assertStringContainsString('iam/me/api-tokens', $code);
    }

    /**
     * BATAS ABILITY DIKATAKAN DI LAYAR, bukan hanya di laporan.
     *
     * 218 dari 862 rute api tidak dijaga izin apa pun, dan token terbatas
     * menjangkau semuanya. Pemilik token yang tidak tahu itu percaya pada
     * pembatasan yang lebih ketat daripada yang ada.
     */
    public function test_the_token_screen_says_what_abilities_do_not_limit(): void
    {
        $code = $this->source(self::PROFIL_VIEW);

        $this->assertStringContainsString('token-scope-note', $code);
        $this->assertStringContainsString('SUBSET izin Anda', $code);
        $this->assertStringContainsString('tidak dijaga izin apa pun', $code);
    }

    /** Nama kelas yang dijanjikan kedua layar kepada harness. */
    public function test_the_selectors_the_harness_reads_are_exported(): void
    {
        $webhook = $this->source(self::WEBHOOK_VIEW);
        $profil = $this->source(self::PROFIL_VIEW);

        foreach (['webhook-subscription', 'webhook-secret', 'secret-note', 'webhook-delivery', 'webhook-error', 'webhook-signature'] as $selector) {
            $this->assertStringContainsString($selector, $webhook, "webhook.js harus menggambar .{$selector}");
        }

        foreach (['profil-tokens', 'token-row', 'token-secret', 'token-once', 'token-abilities'] as $selector) {
            $this->assertStringContainsString($selector, $profil, "profil.js harus menggambar .{$selector}");
        }

        $this->assertStringContainsString('export const WEBHOOK_SELECTORS', $webhook);
        $this->assertStringContainsString('export const PROFIL_SELECTORS', $profil);
    }
}
