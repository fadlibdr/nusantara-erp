<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Modules\Core\Models\Attachment;
use Modules\Core\Services\AttachmentService;
use Modules\Finance\Models\ApBill;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * P0-D: engineering drawings (dwg/dxf), schedules (mpp/xml) and presentations
 * (pptx/ppt) become attachable, with per-extension size limits and a multipart
 * route for the sizes base64-inside-JSON cannot carry.
 *
 * Every accepted MIME here was pinned by asking finfo about a real minimal
 * binary of the type — the committed fixtures in tests/fixtures/attachments/,
 * regenerated and re-verified by generate.php in that directory. If this test
 * starts failing after a PHP upgrade, run that script and re-pin ALLOWED to
 * what finfo now says.
 */
class AttachmentPolicyTest extends ErpTestCase
{
    use FinanceFixtures;

    private AttachmentService $attachments;

    private ApBill $bill;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedLedger(2026);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->attachments = app(AttachmentService::class);
        $this->bill = $this->apBills()->create([
            'vendor_id' => $this->makeVendor()->id,
            'description' => 'Gambar dan jadwal',
            'dpp' => 10_000_000,
            'bill_date' => '2026-03-10',
            'vendor_invoice_no' => 'INV-2',
        ]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/attachments/'.$name));
    }

    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('r-'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);

        $user = User::query()->create([
            'name' => 'Pengguna',
            'email' => str()->random(8).'@nusantara.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    // ------------------------------------------- the six types, real fixtures

    public static function engineeringFixtures(): array
    {
        return [
            'dwg' => ['sample.dwg', 'image/vnd.dwg'],
            'dxf' => ['sample.dxf', 'text/plain'],
            'mpp' => ['sample.mpp', 'application/vnd.ms-office'],
            'xml' => ['sample.xml', 'text/xml'],
            'pptx' => ['sample.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'ppt' => ['sample.ppt', 'application/vnd.ms-powerpoint'],
        ];
    }

    #[DataProvider('engineeringFixtures')]
    public function test_each_engineering_type_is_accepted_from_its_real_bytes(string $file, string $mime): void
    {
        $attachment = $this->attachments->store($this->bill, $file, base64_encode($this->fixture($file)));

        $this->assertSame($mime, $attachment->mime);
        Storage::disk('local')->assertExists($attachment->path);
    }

    // --------------------------------------------------- fakes and disguises

    /** A renamed file is not the type its extension claims. */
    public function test_png_bytes_named_dwg_are_refused(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak cocok dengan ekstensi/');

        $this->attachments->store($this->bill, 'denah.dwg', base64_encode($png));
    }

    public function test_html_named_xml_is_refused_naming_what_it_looked_like(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/terlihat seperti dokumen HTML/');

        $this->attachments->store($this->bill, 'jadwal.xml', base64_encode(
            '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'
        ));
    }

    /**
     * finfo answers text/xml for HTML hidden behind an XML prolog, so the MIME
     * check alone would wave it through — the dedicated sniff must not.
     */
    public function test_html_behind_an_xml_prolog_is_still_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/terlihat seperti dokumen HTML/');

        $this->attachments->store($this->bill, 'jadwal.xml', base64_encode(
            "<?xml version=\"1.0\"?>\n<!-- laporan -->\n<html><script>alert(1)</script></html>"
        ));
    }

    /** SVG sniffs as image/svg+xml, which no extension accepts — including .xml. */
    public function test_svg_content_named_xml_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak cocok dengan ekstensi/');

        $this->attachments->store($this->bill, 'gambar.xml', base64_encode(
            '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        ));
    }

    // ------------------------------------------------ per-extension size caps

    public function test_a_drawing_between_5_and_25_mb_is_accepted_on_the_multipart_route(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);
        $sixMb = $this->fixture('sample.dwg').str_repeat("\x00", 6 * 1024 * 1024);

        $this->actingAs($clerk)->post('/api/core/attachments/upload', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'file' => UploadedFile::fake()->createWithContent('denah-lantai-8.dwg', $sixMb),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $this->assertSame('image/vnd.dwg', Attachment::query()->sole()->mime);
    }

    public function test_a_drawing_over_25_mb_is_refused_on_the_multipart_route(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);
        $tooBig = $this->fixture('sample.dwg').str_repeat("\x00", 25 * 1024 * 1024);

        $this->actingAs($clerk)->post('/api/core/attachments/upload', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'file' => UploadedFile::fake()->createWithContent('denah.dwg', $tooBig),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'melebihi batas 25 MB'));

        $this->assertSame(0, Attachment::query()->count());
    }

    /** The 5 MB default still binds every type without its own limit. */
    public function test_a_default_type_over_5_mb_is_refused_on_the_json_route(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);

        $this->actingAs($clerk)->postJson('/api/core/attachments', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'filename' => 'catatan.txt',
            'content' => base64_encode(str_repeat('a', AttachmentService::MAX_BYTES + 1)),
        ])->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'melebihi batas 5 MB'));
    }

    /**
     * The JSON route cannot carry what the multipart route exists for: 25 MB
     * becomes ~33 MB of base64, over the deployed post_max_size (26M) — so the
     * request rule stops oversized base64 long before php-fpm would.
     */
    public function test_the_json_route_refuses_base64_beyond_its_ceiling(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);
        $sixMb = $this->fixture('sample.dwg').str_repeat("\x00", 6 * 1024 * 1024);

        $this->actingAs($clerk)->postJson('/api/core/attachments', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'filename' => 'denah.dwg',
            'content' => base64_encode($sixMb),
        ])->assertStatus(422)->assertJsonValidationErrors(['content']);
    }

    // ------------------------------------------------------- multipart route

    public function test_multipart_upload_stores_and_answers_the_same_shape_as_json(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);

        $multipart = $this->actingAs($clerk)->post('/api/core/attachments/upload', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'file' => UploadedFile::fake()->createWithContent('paparan-kickoff.pptx', $this->fixture('sample.pptx')),
            'caption' => 'Paparan kickoff',
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $json = $this->actingAs($clerk)->postJson('/api/core/attachments', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'filename' => 'jadwal.xml',
            'content' => base64_encode($this->fixture('sample.xml')),
        ])->assertStatus(201);

        $this->assertSame(array_keys($json->json('data')), array_keys($multipart->json('data')));
        $this->assertSame('paparan-kickoff.pptx', $multipart->json('data.original_name'));
        $this->assertSame('Paparan kickoff', $multipart->json('data.caption'));

        $stored = Attachment::query()->where('extension', 'pptx')->sole();
        $this->assertSame(hash('sha256', $this->fixture('sample.pptx')), $stored->sha256);
        Storage::disk('local')->assertExists($stored->path);
    }

    /** Both routes run the same service checks — a disguise fails multipart too. */
    public function test_multipart_upload_refuses_a_disguised_file_with_the_same_message(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);

        $this->actingAs($clerk)->post('/api/core/attachments/upload', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'file' => UploadedFile::fake()->createWithContent('jadwal.xml', '<html><script>alert(1)</script></html>'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'terlihat seperti dokumen HTML'));

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_multipart_upload_requires_the_update_permission_of_the_documents_module(): void
    {
        $viewer = $this->userWith(['fin.view']);

        $this->actingAs($viewer)->post('/api/core/attachments/upload', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'file' => UploadedFile::fake()->createWithContent('denah.dwg', $this->fixture('sample.dwg')),
        ], ['Accept' => 'application/json'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Anda tidak memiliki izin fin.update.');

        $this->assertSame(0, Attachment::query()->count());
    }

    // ------------------------------------------------------------ regression

    /** The base64 JSON route is unchanged for the types it always carried. */
    public function test_the_json_route_still_accepts_an_ordinary_pdf(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);

        $this->actingAs($clerk)->postJson('/api/core/attachments', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'filename' => 'faktur.pdf',
            'content' => base64_encode("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"),
        ])->assertStatus(201)->assertJsonPath('data.mime', 'application/pdf');
    }

    /**
     * XHTML berprefiks namespace adalah HTML berganti baju XML.
     *
     * '<x:html xmlns:x="...xhtml">' lolos sniff (finfo menjawab text/xml) dan
     * lolos regex tag tanpa prefiks — temuan verifikasi P0-D. Bukan XSS
     * tersimpan (unduhan attachment + nosniff + CSP), tetapi penjaga
     * masquerade ada justru untuk menolak pengakuan diri seperti ini.
     */
    public function test_a_namespace_prefixed_xhtml_masquerading_as_xml_is_refused(): void
    {
        $payload = '<?xml version="1.0"?>'
            .'<x:html xmlns:x="http://www.w3.org/1999/xhtml">'
            .'<x:script>alert(1)</x:script></x:html>';

        $clerk = $this->userWith(['fin.view', 'fin.update']);

        $this->actingAs($clerk)->postJson('api/core/attachments', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'filename' => 'jadwal.xml',
            'content' => base64_encode($payload),
        ])->assertStatus(422);
    }
}
