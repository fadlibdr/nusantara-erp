<?php

namespace Modules\Crm\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Crm\Models\Customer;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Quotation;

/**
 * Dokumen mana yang boleh memikul aktivitas, dialamatkan dengan JENIS PENDEK.
 *
 * Selera dan alasannya persis AttachableDocuments: yang menyeberangi kawat
 * adalah 'lead' / 'quotation' / 'customer', tidak pernah nama kelas. Sebuah
 * endpoint yang menerima nama kelas mengizinkan penelepon menyebut kelas apa
 * pun sebagai induk sebuah baris, dan selisih antara daftar-izin tervalidasi
 * dan string bebas di sini adalah selisih antara sebuah fitur dan sebuah
 * permukaan injeksi objek.
 *
 * `slug` adalah kosakata rute SPA ('crm/leads') — dipakai kartu aktivitas di
 * layar dokumen untuk menerjemahkan layar menjadi jenis. Cerminnya di klien
 * (public/app/js/views/activities.js) dijaga ActivityRegistryTest, yang membaca
 * kedua sisi dan gagal bila berbeda: sebuah slug yang hanya ada di satu sisi
 * adalah kartu yang selalu 422, atau dokumen yang diam-diam tidak bisa
 * mencatat satu pun aktivitas.
 */
class ActivityDocuments
{
    /**
     * @var array<string, array{model: class-string<Model>, slug: string, label: string}>
     */
    private const DOCUMENTS = [
        'lead' => ['model' => Lead::class, 'slug' => 'crm/leads', 'label' => 'Prospek'],
        'quotation' => ['model' => Quotation::class, 'slug' => 'crm/quotations', 'label' => 'Penawaran'],
        'customer' => ['model' => Customer::class, 'slug' => 'crm/customers', 'label' => 'Pelanggan'],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::DOCUMENTS);
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_column(self::DOCUMENTS, 'slug');
    }

    public static function label(string $type): ?string
    {
        return self::DOCUMENTS[$type]['label'] ?? null;
    }

    /** @return class-string<Model>|null */
    public static function model(string $type): ?string
    {
        return self::DOCUMENTS[$type]['model'] ?? null;
    }

    /**
     * Baris induknya, atau null bila jenisnya tidak dikenal / barisnya tidak
     * ada. Baris terhapus (soft delete) TIDAK dianggap ada: menggantungkan
     * aktivitas baru pada prospek yang sudah dihapus adalah pekerjaan yang
     * tidak akan pernah muncul di layar mana pun.
     */
    public static function find(string $type, int|string $id): ?Model
    {
        $model = self::model($type);

        return $model === null ? null : $model::query()->find($id);
    }
}
