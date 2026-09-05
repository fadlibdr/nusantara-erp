<?php

namespace Tests\Feature\Iam;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Modules\Iam\Support\OnboardingGuide;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;

/**
 * Onboarding in the app — the owner's request of 5 Sep 2026: "on boarding is
 * not working, make it pop-up every user is logged in and create a button to
 * skip the onboarding process also make the choice is remembered".
 *
 * "Not working" was literal: the guides lived only in docs/ONBOARDING and
 * the SPA never showed them. What is pinned here:
 *
 *  - GET iam/me/onboarding serves the caller's role guide as seven HTML
 *    sections — and EVERY shipped guide splits into exactly those seven, in
 *    the README's order, because the modal's "1 dari 7" is built on it;
 *  - raw HTML in a guide is stripped and javascript: links are refused (the
 *    SPA injects the result with innerHTML), proven on a scratch guide so the
 *    real ones are never touched;
 *  - a role without a guide, an account without a role, and a role name
 *    that is not a plain slug all get an Indonesian 404 — the last one before
 *    any filesystem call;
 *  - PUT skipped / completed / null round-trips into auth/me, which is where
 *    the SPA reads the decision at login, so it follows the person across
 *    devices; anything else is refused in Indonesian and changes nothing.
 */
class OnboardingTest extends ErpTestCase
{
    private const SEVEN_HEADINGS = [
        'Siapa Anda di sistem',
        'Hari pertama',
        'Pekerjaan Anda',
        'Yang akan menolak Anda',
        'Formulir yang Anda cetak',
        'Daftar periksa minggu pertama',
        'Bila tersangkut',
    ];

    private ?string $scratchDir = null;

    protected function tearDown(): void
    {
        if ($this->scratchDir !== null) {
            File::deleteDirectory($this->scratchDir);
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------- fixtures

    /** Same shape as DeadlineWatchTest::userWith(), keyed on a role name instead of permissions. */
    private function userWithRole(?string $role, string $name = 'Rina Kartika'): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => $name,
            'email' => substr(md5($name.'|'.($role ?? '-')), 0, 10).'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);

        if ($role !== null) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }

        return $user;
    }

    private function asUser(User $user): static
    {
        return $this->withHeader('X-Api-Token', $user->createToken('test')->plainTextToken);
    }

    /**
     * A guide directory of our own, so the probe never edits a real guide.
     * OnboardingGuide::directory() reads the override the same way the real
     * path is read — this is the seam the test is allowed to use.
     */
    private function scratchGuide(string $role, string $markdown): void
    {
        $this->scratchDir = storage_path('framework/testing/onboarding-'.substr(md5((string) microtime(true)), 0, 8));
        File::ensureDirectoryExists($this->scratchDir);
        File::put($this->scratchDir.'/'.$role.'.md', $markdown);
        config(['iam.onboarding_guides' => $this->scratchDir]);
    }

    // ------------------------------------------------------------- the guide

    public function test_a_role_with_a_guide_gets_its_seven_sections_as_html(): void
    {
        $user = $this->userWithRole('finance');

        $response = $this->asUser($user)->getJson('/api/iam/me/onboarding')->assertOk();

        $response
            ->assertJsonPath('data.role', 'finance')
            ->assertJsonPath('data.title', 'Petugas Keuangan')
            ->assertJsonPath('data.status', null)
            ->assertJsonPath('data.seen_at', null)
            ->assertJsonPath('data.guide_path', 'docs/ONBOARDING/finance.md')
            ->assertJsonPath('data.guide_url', OnboardingGuide::DOCS_URL.'/docs/ONBOARDING/finance.md');

        $sections = $response->json('data.sections');
        $this->assertCount(7, $sections);

        foreach (self::SEVEN_HEADINGS as $index => $heading) {
            $this->assertSame(($index + 1).'. '.$heading, $sections[$index]['heading']);
            $this->assertNotSame('', $sections[$index]['id']);
            $this->assertStringContainsString('<', $sections[$index]['html'], "Section [{$heading}] did not render to HTML.");
        }

        // The checklist renders as GFM task items: that is what the modal
        // turns into session-only checkboxes.
        $this->assertStringContainsString('type="checkbox"', $sections[5]['html']);
        // The H1 and the cover-sheet preamble are not a section.
        $this->assertStringNotContainsString('Onboarding minggu pertama', $sections[0]['html']);
    }

    public function test_every_shipped_guide_splits_into_the_readme_seven_in_order(): void
    {
        $guides = glob(base_path(OnboardingGuide::DIRECTORY.'/*.md')) ?: [];
        $roles = array_values(array_filter(
            array_map(static fn (string $path): string => basename($path, '.md'), $guides),
            static fn (string $name): bool => $name !== 'README',
        ));

        // A glob that found nothing would make the loop below a silent pass.
        $this->assertGreaterThanOrEqual(12, count($roles), 'Fewer than twelve role guides found in '.OnboardingGuide::DIRECTORY.'.');

        foreach ($roles as $role) {
            $guide = OnboardingGuide::load($role);
            $this->assertNotNull($guide, "Guide for [{$role}] did not load.");
            $this->assertNotSame($role, $guide['title'], "Guide for [{$role}] has no readable H1 title.");

            $headings = array_column($guide['sections'], 'heading');
            $this->assertCount(7, $headings, "Guide for [{$role}] has ".count($headings).' sections, not 7: '.implode(' | ', $headings));

            foreach (self::SEVEN_HEADINGS as $index => $heading) {
                $this->assertStringContainsString($heading, $headings[$index], "Guide for [{$role}], section ".($index + 1).' is not "'.$heading.'".');
            }
        }
    }

    public function test_raw_html_and_unsafe_links_in_a_guide_are_stripped(): void
    {
        $this->scratchGuide('uji-coba', implode("\n", [
            '# Onboarding minggu pertama — Peran Uji (`uji-coba`)',
            '',
            'Pembuka yang bukan bagian. <script>alert("pembuka")</script>',
            '',
            '---',
            '',
            '## 1. Siapa Anda di sistem',
            '',
            'Teks **tebal** dan <script>alert("bagian")</script> serta <img src=x onerror="alert(1)">.',
            '',
            '[tautan berbahaya](javascript:alert(1)) dan [tautan biasa](https://contoh.test/halaman).',
            '',
            '## 2. Hari pertama',
            '',
            '- [ ] Centang saya',
            '',
            '| Kolom | Nilai |',
            '|---|---|',
            '| a | 1 |',
        ]));
        $user = $this->userWithRole('uji-coba');

        $response = $this->asUser($user)->getJson('/api/iam/me/onboarding')->assertOk();

        $response->assertJsonPath('data.title', 'Peran Uji');
        $sections = $response->json('data.sections');
        $this->assertCount(2, $sections);

        $first = $sections[0]['html'];
        $this->assertStringContainsString('<strong>tebal</strong>', $first);
        $this->assertStringNotContainsString('<script', $first);
        $this->assertStringNotContainsString('onerror', $first);
        $this->assertStringNotContainsString('javascript:', $first);
        $this->assertStringContainsString('href="https://contoh.test/halaman"', $first);
        $this->assertStringNotContainsString('pembuka', $first);

        $second = $sections[1]['html'];
        $this->assertStringContainsString('type="checkbox"', $second);
        $this->assertStringContainsString('<table>', $second);
    }

    public function test_a_role_without_a_guide_is_refused_in_indonesian(): void
    {
        $user = $this->userWithRole('peran-khusus');

        $this->asUser($user)
            ->getJson('/api/iam/me/onboarding')
            ->assertNotFound()
            ->assertJsonPath('message', 'Belum ada panduan onboarding untuk peran peran-khusus. Panduan yang ada mengikuti kedua belas peran bawaan (docs/ONBOARDING).');
    }

    public function test_an_account_without_a_role_is_refused_in_indonesian(): void
    {
        $user = $this->userWithRole(null);

        $this->asUser($user)
            ->getJson('/api/iam/me/onboarding')
            ->assertNotFound()
            ->assertJsonPath('message', 'Akun Anda belum memegang peran, jadi belum ada panduan onboarding untuk ditampilkan. Minta administrator menetapkan peran Anda.');
    }

    public function test_a_role_name_that_is_not_a_slug_never_reaches_the_filesystem(): void
    {
        // Spatie accepts any string as a role name; the README index and the
        // parent folder both exist, and neither may be served for a role.
        foreach (['../README', 'README', '../../.env', 'finance.md', 'readme'] as $name) {
            $this->assertNull(OnboardingGuide::pathFor($name), "[{$name}] resolved to a guide file.");
        }

        $user = $this->userWithRole('../README');

        $this->asUser($user)->getJson('/api/iam/me/onboarding')->assertNotFound();
    }

    public function test_the_guide_needs_a_session(): void
    {
        $this->getJson('/api/iam/me/onboarding')->assertUnauthorized();
        $this->putJson('/api/iam/me/onboarding', ['status' => 'skipped'])->assertUnauthorized();
    }

    // ---------------------------------------------------------- the decision

    public function test_skipped_completed_and_null_round_trip_into_auth_me(): void
    {
        $user = $this->userWithRole('finance');
        $client = $this->asUser($user);

        // A fresh account: never decided, so the SPA will pop the guide.
        $client->getJson('/api/iam/auth/me')
            ->assertOk()
            ->assertJsonPath('data.onboarding_status', null)
            ->assertJsonPath('data.onboarding_seen_at', null);

        $client->putJson('/api/iam/me/onboarding', ['status' => 'skipped'])
            ->assertOk()
            ->assertJsonPath('message', 'Panduan onboarding dilewati — bisa dibuka lagi dari menu akun.')
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.onboarding_status', 'skipped');

        $client->getJson('/api/iam/auth/me')->assertJsonPath('data.onboarding_status', 'skipped');
        $this->assertNotNull($user->fresh()->onboarding_seen_at);

        // The guide endpoint reports the same standing (the modal swaps
        // Lewati for Tutup on it).
        $client->getJson('/api/iam/me/onboarding')
            ->assertOk()
            ->assertJsonPath('data.status', 'skipped');

        $client->putJson('/api/iam/me/onboarding', ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('message', 'Panduan onboarding selesai.')
            ->assertJsonPath('data.onboarding_status', 'completed');
        $client->getJson('/api/iam/auth/me')->assertJsonPath('data.onboarding_status', 'completed');

        // "Buka lagi": a reset clears both columns, so the next login pops it.
        $client->putJson('/api/iam/me/onboarding', ['status' => null])
            ->assertOk()
            ->assertJsonPath('message', 'Panduan onboarding akan tampil lagi saat Anda masuk berikutnya.')
            ->assertJsonPath('data.onboarding_status', null)
            ->assertJsonPath('data.onboarding_seen_at', null);
        $client->getJson('/api/iam/auth/me')->assertJsonPath('data.onboarding_status', null);
        $this->assertNull($user->fresh()->onboarding_seen_at);
    }

    public function test_the_decision_belongs_to_the_caller_only(): void
    {
        $first = $this->userWithRole('finance', 'Rina Kartika');
        $second = $this->userWithRole('sales', 'Dedi Pratama');

        $this->asUser($first)->putJson('/api/iam/me/onboarding', ['status' => 'completed'])->assertOk();

        $this->assertSame('completed', $first->fresh()->onboarding_status);
        $this->assertNull($second->fresh()->onboarding_status);
    }

    public function test_an_unknown_status_is_refused_in_indonesian_and_nothing_changes(): void
    {
        $user = $this->userWithRole('finance');
        $message = 'Status onboarding hanya boleh skipped atau completed, atau kosong untuk menampilkan panduan lagi.';

        $this->asUser($user)
            ->putJson('/api/iam/me/onboarding', ['status' => 'nanti'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', $message);

        // A body without the key is not a reset.
        $this->asUser($user)
            ->putJson('/api/iam/me/onboarding', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Status onboarding harus dikirim: skipped atau completed, atau kosong untuk menampilkan panduan lagi.');

        $this->assertNull($user->fresh()->onboarding_status);
        $this->assertNull($user->fresh()->onboarding_seen_at);
    }
}
