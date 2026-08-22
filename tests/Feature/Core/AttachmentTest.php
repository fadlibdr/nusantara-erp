<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Modules\Core\Models\Attachment;
use Modules\Core\Services\AttachmentService;
use Modules\Core\Support\AttachableDocuments;
use Modules\Finance\Models\ApBill;
use Modules\Iam\Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\ErpTestCase;
use Tests\Unit\Finance\FinanceFixtures;

/**
 * Attaching files to documents.
 *
 * A file upload is the largest attack surface in an ERP: it is the one place a
 * user hands the server content of their choosing and asks it to give that
 * content back to other users' browsers later. Most of what follows is about
 * that, not about happy-path storage.
 */
class AttachmentTest extends ErpTestCase
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
            'description' => 'Material',
            'dpp' => 10_000_000,
            'bill_date' => '2026-03-10',
            'vendor_invoice_no' => 'INV-1',
        ]);
    }

    /** A one-pixel PNG — real bytes, so finfo agrees with the extension. */
    private function png(): string
    {
        return base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
    }

    private function pdf(): string
    {
        return base64_encode("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
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

    // ------------------------------------------------------------ happy path

    public function test_a_pdf_is_stored_and_linked_to_its_document(): void
    {
        $attachment = $this->attachments->store($this->bill, 'faktur-pajak.pdf', $this->pdf(), 'Faktur pajak');

        $this->assertSame(ApBill::class, $attachment->attachable_type);
        $this->assertSame($this->bill->id, (int) $attachment->attachable_id);
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertSame('faktur-pajak.pdf', $attachment->original_name);
        $this->assertSame('Faktur pajak', $attachment->caption);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_a_data_uri_from_the_browser_is_accepted(): void
    {
        $attachment = $this->attachments->store($this->bill, 'foto.png', 'data:image/png;base64,'.$this->png());

        $this->assertSame('image/png', $attachment->mime);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_the_stored_bytes_are_exactly_what_was_uploaded(): void
    {
        $attachment = $this->attachments->store($this->bill, 'faktur.pdf', $this->pdf());

        $this->assertSame(base64_decode($this->pdf()), $this->attachments->contents($attachment));
        $this->assertSame(hash('sha256', base64_decode($this->pdf())), $attachment->sha256);
    }

    // -------------------------------------------------------------- security

    /**
     * The stored path is generated. A filename that is a path must not be able
     * to place bytes outside the attachments directory, or overwrite anything.
     */
    public function test_a_path_traversal_filename_cannot_escape_the_storage_directory(): void
    {
        $attachment = $this->attachments->store($this->bill, '../../../../.env.png', $this->png());

        $this->assertStringStartsWith('attachments/', $attachment->path);
        $this->assertStringNotContainsString('..', $attachment->path);
        $this->assertStringNotContainsString('/', $attachment->original_name);
        Storage::disk('local')->assertExists($attachment->path);
    }

    /**
     * The extension decides nothing on its own — the bytes have to agree. A
     * .pdf that is really HTML is how an upload becomes stored XSS.
     */
    public function test_content_that_disagrees_with_the_extension_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak cocok dengan ekstensi/');

        $this->attachments->store($this->bill, 'invoice.pdf', base64_encode('<html><script>alert(1)</script>'));
    }

    public function test_an_executable_extension_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak diizinkan/');

        $this->attachments->store($this->bill, 'shell.php', base64_encode('<?php system($_GET["c"]);'));
    }

    /** SVG is the one image format that executes script. It is not on the list. */
    public function test_svg_is_not_an_allowed_image_type(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak diizinkan/');

        $this->attachments->store(
            $this->bill,
            'logo.svg',
            base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        );
    }

    public function test_a_file_without_an_extension_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak memiliki ekstensi/');

        $this->attachments->store($this->bill, 'lampiran', $this->pdf());
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/melebihi batas/');

        $this->attachments->store($this->bill, 'besar.txt', base64_encode(str_repeat('a', AttachmentService::MAX_BYTES + 1)));
    }

    public function test_content_that_is_not_base64_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/base64 tidak valid/');

        $this->attachments->store($this->bill, 'rusak.pdf', '!!!! not base64 !!!!');
    }

    public function test_an_unknown_document_type_cannot_be_attached_to(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tidak dapat dilampiri/');

        $this->attachments->resolveDocument('app/users', 1);
    }

    // ------------------------------------------------------------- endpoints

    public function test_uploading_requires_the_update_permission_of_the_documents_module(): void
    {
        $viewer = $this->userWith(['fin.view']);

        $this->actingAs($viewer)->postJson('/api/core/attachments', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'filename' => 'faktur.pdf',
            'content' => $this->pdf(),
        ])->assertStatus(403)->assertJsonPath('message', 'Anda tidak memiliki izin fin.update.');
    }

    public function test_listing_requires_the_view_permission_of_the_documents_module(): void
    {
        $outsider = $this->userWith(['prc.view']);

        $this->actingAs($outsider)->getJson(
            '/api/core/attachments?document_type=finance/ap-bills&document_id='.$this->bill->id
        )->assertStatus(403);
    }

    /**
     * Downloading needs the parent document's view permission — and a refusal
     * is indistinguishable from a missing row, so the pair cannot be used to
     * enumerate attachments or learn which module each belongs to.
     */
    public function test_downloading_requires_the_view_permission_of_the_documents_module(): void
    {
        $attachment = $this->attachments->store($this->bill, 'faktur.pdf', $this->pdf());
        $outsider = $this->userWith(['prc.view', 'prc.update']);

        $forbidden = $this->actingAs($outsider)->getJson("/api/core/attachments/{$attachment->id}/download");
        $missing = $this->actingAs($outsider)->getJson('/api/core/attachments/987654/download');

        $forbidden->assertStatus(404);
        $missing->assertStatus(404);
        $this->assertSame($forbidden->json('message'), $missing->json('message'));
    }

    /**
     * A file must not outlive the document it belongs to. Deleting the parent
     * leaves rows whose permission check still passes for anyone holding the
     * module's view right, on a record nobody can see any more.
     */
    public function test_an_attachment_whose_document_is_gone_is_not_downloadable(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);
        $attachment = $this->attachments->store($this->bill, 'faktur.pdf', $this->pdf());

        // Soft delete: still reachable, because the document still exists.
        $this->bill->delete();
        $this->actingAs($clerk)->getJson("/api/core/attachments/{$attachment->id}/download")->assertOk();

        // Force delete: gone, and so is the file's reachability.
        $this->bill->forceDelete();
        $this->actingAs($clerk)->getJson("/api/core/attachments/{$attachment->id}/download")->assertStatus(404);
    }

    /**
     * An unauthorised caller must not be able to tell an existing document from
     * a missing one: the two replies differ, and that difference enumerates
     * every document in the system.
     */
    public function test_an_unauthorised_caller_cannot_tell_whether_a_document_exists(): void
    {
        $outsider = $this->userWith(['prc.view']);

        $real = $this->actingAs($outsider)->getJson(
            '/api/core/attachments?document_type=finance/ap-bills&document_id='.$this->bill->id
        );
        $imaginary = $this->actingAs($outsider)->getJson(
            '/api/core/attachments?document_type=finance/ap-bills&document_id=987654'
        );

        $real->assertStatus(403);
        $imaginary->assertStatus(403);
        $this->assertSame($real->json('message'), $imaginary->json('message'));
    }

    public function test_an_authorised_user_can_upload_list_and_download(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);

        $created = $this->actingAs($clerk)->postJson('/api/core/attachments', [
            'document_type' => 'finance/ap-bills',
            'document_id' => $this->bill->id,
            'filename' => 'faktur.pdf',
            'content' => $this->pdf(),
        ])->assertStatus(201)->json('data');

        $this->actingAs($clerk)->getJson(
            '/api/core/attachments?document_type=finance/ap-bills&document_id='.$this->bill->id
        )->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($clerk)
            ->get("/api/core/attachments/{$created['id']}/download")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /**
     * A stored file is served with a disposition the browser cannot argue with.
     * Anything that is not an image or a PDF downloads rather than rendering, so
     * a file that turns out to be markup cannot execute in this origin.
     */
    public function test_a_non_image_downloads_rather_than_rendering(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);
        $attachment = $this->attachments->store($this->bill, 'catatan.txt', base64_encode('halo'));

        $response = $this->actingAs($clerk)->get("/api/core/attachments/{$attachment->id}/download");

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }

    public function test_deleting_removes_the_row_and_the_file(): void
    {
        $clerk = $this->userWith(['fin.view', 'fin.update']);
        $attachment = $this->attachments->store($this->bill, 'faktur.pdf', $this->pdf());
        $path = $attachment->path;

        $this->actingAs($clerk)->deleteJson("/api/core/attachments/{$attachment->id}")->assertOk();

        $this->assertSame(0, Attachment::query()->count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_every_registered_document_class_exists_and_maps_to_a_real_permission(): void
    {
        $permissions = Permission::query()->pluck('name')->all();

        foreach (AttachableDocuments::all() as $slug => $entry) {
            $this->assertTrue(class_exists($entry['class']), "{$slug} maps to a class that does not exist.");

            foreach (['view', 'update'] as $action) {
                $this->assertContains(
                    $entry['prefix'].'.'.$action,
                    $permissions,
                    "{$slug} needs permission {$entry['prefix']}.{$action}, which is not seeded.",
                );
            }
        }
    }
}
