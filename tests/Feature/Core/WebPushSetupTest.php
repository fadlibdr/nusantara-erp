<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Artisan;
use Modules\Core\Support\ProviderErrorScrubber;
use Modules\Core\Support\WebPushSetup;
use ReflectionMethod;
use Tests\ErpTestCase;

/**
 * KUNCI PRIVAT VAPID TIDAK PUNYA JALAN KELUAR SELAIN PENGIRIMNYA (P-3e, T3e.1).
 *
 * Kanal ini berbeda dari WhatsApp dalam satu hal yang mudah salah: ia punya
 * kunci PUBLIK yang memang harus dikirim ke setiap peramban dan kunci PRIVAT
 * yang tidak boleh keluar ke mana pun. Dua nilai bersebelahan di satu blok
 * konfigurasi, namanya beda satu kata, dan yang salah dikirim membocorkan hak
 * mengirim pemberitahuan atas nama pemasangan ini kepada siapa pun yang
 * membaca layar.
 *
 * Maka yang dipaku di sini bukan sebuah kalimat, melainkan BENTUK kelasnya:
 * dari seluruh metode publiknya, hanya `auth()` — yang tujuannya adalah
 * pustaka penanda tangan, bukan layar — yang boleh memuat kunci privat.
 * Sebuah `publicKey()` yang salah ketik menjadi kunci privat memerahkan uji
 * ini tanpa ada yang perlu menebak namanya lebih dulu.
 */
class WebPushSetupTest extends ErpTestCase
{
    private const PUBLIC_KEY = 'BAQ7Lq3vXk8hHhVrBqEfkS1rXkKq9gYQm2b0sCk5nJd0Uu3rHqDSdwZ9zZKqk1s2OaL0f7d9XyOo2n0F3q9d6bE';

    private const PRIVATE_KEY = 'uL2f6bQ0K9Z3cVn8sYxTq1rWmE7dP4gHjK5nR0tXcVs';

    private function configure(?string $public = self::PUBLIC_KEY, ?string $private = self::PRIVATE_KEY, ?string $subject = 'mailto:pemilik@nusantara.test'): void
    {
        config([
            'erp.push.vapid_public_key' => $public,
            'erp.push.vapid_private_key' => $private,
            'erp.push.vapid_subject' => $subject,
        ]);
    }

    public function test_an_empty_env_is_not_configured_and_says_which_variables_are_missing(): void
    {
        $this->configure(null, null, null);

        $this->assertFalse(WebPushSetup::configured());
        $this->assertSame(WebPushSetup::SKIP_UNCONFIGURED, WebPushSetup::skipReason());
        $this->assertStringContainsString('VAPID_PUBLIC_KEY', (string) WebPushSetup::skipReason());
        $this->assertStringContainsString('VAPID_PRIVATE_KEY', (string) WebPushSetup::skipReason());
        $this->assertStringContainsString('core:vapid-keys', (string) WebPushSetup::skipReason());
    }

    public function test_two_of_three_is_still_not_configured(): void
    {
        $this->configure(self::PUBLIC_KEY, self::PRIVATE_KEY, null);
        $this->assertFalse(WebPushSetup::configured(), 'Tanpa VAPID_SUBJECT header VAPID tidak sah; kanal tidak boleh mengaku siap.');

        $this->configure(self::PUBLIC_KEY, null);
        $this->assertFalse(WebPushSetup::configured());

        $this->configure(null);
        $this->assertFalse(WebPushSetup::configured());
    }

    public function test_a_subject_that_is_neither_mailto_nor_https_is_refused_before_anything_leaves_the_machine(): void
    {
        $this->configure(subject: 'admin@nusantara.test');

        $reason = WebPushSetup::skipReason();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('mailto:', (string) $reason);
        $this->assertStringContainsString('RFC 8292', (string) $reason);
    }

    public function test_a_complete_vapid_set_has_nothing_to_skip(): void
    {
        $this->configure();

        $this->assertTrue(WebPushSetup::configured());
        $this->assertNull(WebPushSetup::skipReason());
        $this->assertSame(self::PUBLIC_KEY, WebPushSetup::publicKey());
    }

    /**
     * Bentuk, bukan ejaan: setiap metode publik tanpa argumen dipanggil, dan
     * keluarannya dicari kunci privatnya. Hanya auth() yang boleh memuatnya.
     */
    public function test_only_auth_may_carry_the_private_key_out_of_the_class(): void
    {
        $this->configure();

        $carriers = [];

        foreach ((new \ReflectionClass(WebPushSetup::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $value = json_encode($method->invoke(null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (str_contains((string) $value, self::PRIVATE_KEY)) {
                $carriers[] = $method->getName();
            }
        }

        sort($carriers);

        $this->assertSame(
            ['auth', 'secrets'],
            $carriers,
            'Ada metode publik WebPushSetup selain auth()/secrets() yang memulangkan kunci privat VAPID. auth() menyerahkannya '
            .'kepada pustaka penanda tangan dan secrets() kepada penyaring galat; setiap jalan keluar lain berakhir di layar, '
            .'log, atau jawaban API.',
        );
    }

    public function test_the_private_key_is_scrubbed_out_of_provider_messages_and_the_public_key_is_not(): void
    {
        $this->configure();

        $scrubbed = ProviderErrorScrubber::webPush('Layanan push menolak: kunci '.self::PRIVATE_KEY.' dengan applicationServerKey '.self::PUBLIC_KEY);

        $this->assertStringNotContainsString(self::PRIVATE_KEY, $scrubbed);
        $this->assertStringContainsString('[rahasia]', $scrubbed);
        $this->assertStringContainsString(
            substr(self::PUBLIC_KEY, 0, 20),
            $scrubbed,
            'Kunci PUBLIK ikut disamarkan — ia dikirim ke setiap peramban, dan menyamarkannya membuat galat '
            .'"applicationServerKey tidak cocok" mustahil dibaca.',
        );
    }

    public function test_the_command_prints_a_new_pair_and_the_sentence_that_it_invalidates_every_subscription(): void
    {
        $this->configure();

        $this->artisan('core:vapid-keys')
            ->expectsOutputToContain('VAPID_PUBLIC_KEY=')
            ->expectsOutputToContain('VAPID_PRIVATE_KEY=')
            ->expectsOutputToContain('VAPID_SUBJECT=')
            ->expectsOutputToContain('MENGGANTI KUNCI VAPID MEMBATALKAN SELURUH LANGGANAN YANG ADA.')
            ->assertSuccessful();
    }

    /**
     * Perintah itu MENCETAK pasangan baru — ia tidak pernah membocorkan kunci
     * privat yang sedang terpasang, dan tidak menulis .env.
     */
    public function test_the_command_never_prints_the_installed_private_key_and_writes_nothing(): void
    {
        $this->configure();

        $env = base_path('.env');
        $before = file_exists($env) ? file_get_contents($env) : null;

        $this->artisan('core:vapid-keys')->assertSuccessful();

        $output = Artisan::output();

        $this->assertStringNotContainsString(
            self::PRIVATE_KEY,
            $output,
            'Perintah mencetak kunci privat yang SEDANG terpasang. Ia hanya boleh membangkitkan pasangan baru.',
        );

        $this->assertSame(
            $before,
            file_exists($env) ? file_get_contents($env) : null,
            'Perintah menulis .env. Satu-satunya salinan kunci privat yang dipercaya aplikasi ini adalah baris di .env '
            .'milik pemilik, dan perintah yang bisa menuliskannya juga bisa menimpanya.',
        );
    }
}
