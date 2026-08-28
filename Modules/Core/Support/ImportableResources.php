<?php

namespace Modules\Core\Support;

use Modules\Core\Models\Location;
use Modules\Crm\Models\Customer;
use Modules\HrPayroll\Models\Employee;
use Modules\Inventory\Models\Item;
use Modules\Procurement\Models\Vendor;

/**
 * What can be loaded in bulk, and the shape each file must have.
 *
 * maatwebsite/excel was in composer.json from the first commit and called
 * nowhere. The only bulk parser in the system was the bank-statement importer,
 * which is specific to rekening koran. Meanwhile ProductionSeeder ships a chart
 * of accounts and two category trees and then stops: items 0, employees 0,
 * vendors 0, customers 0. Loading 2.000 items meant 2.000 forms.
 *
 * These four are the master tables a go-live actually has to carry, and the ones
 * every other document points at. They are also the four that are FLAT — one row
 * in the file is one row in the table — which is what makes a generic importer
 * honest. AHSP is deliberately absent: an analysis is a header plus N components,
 * and pretending that fits a flat sheet would silently drop components.
 *
 * Each column declares:
 *   header    the exact column heading in the file (and in the template)
 *   required  refuse the row without it
 *   rules     Laravel validation, applied per row
 *   cast      optional transform from the sheet's string to the stored value
 */
class ImportableResources
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'items' => [
                'label' => 'Item / Material',
                'model' => Item::class,
                'permission' => 'inv',
                'unique' => 'code',
                'columns' => [
                    ['header' => 'kode', 'field' => 'code', 'required' => true, 'cast' => 'text', 'rules' => ['string', 'max:40']],
                    ['header' => 'nama', 'field' => 'name', 'required' => true, 'rules' => ['string', 'max:200']],
                    ['header' => 'satuan', 'field' => 'unit', 'required' => true, 'rules' => ['string', 'max:20']],
                    ['header' => 'jenis', 'field' => 'item_type', 'rules' => ['in:material,sparepart,tool,merchandise'], 'default' => 'material'],
                    ['header' => 'kategori_kode', 'field' => 'category_id', 'required' => true, 'lookup' => ['inv_item_categories', 'code']],
                    ['header' => 'stok_minimum', 'field' => 'min_stock', 'cast' => 'decimal', 'rules' => ['numeric', 'min:0']],
                    ['header' => 'harga_beli_terakhir', 'field' => 'last_price', 'cast' => 'decimal', 'rules' => ['numeric', 'min:0']],
                    ['header' => 'barcode', 'field' => 'barcode', 'cast' => 'text', 'rules' => ['string', 'max:60']],
                    ['header' => 'aktif', 'field' => 'is_active', 'cast' => 'bool', 'default' => true],
                ],
            ],

            'vendors' => [
                'label' => 'Vendor & Subkontraktor',
                'model' => Vendor::class,
                'permission' => 'prc',
                'unique' => 'code',
                'columns' => [
                    ['header' => 'kode', 'field' => 'code', 'required' => true, 'cast' => 'text', 'rules' => ['string', 'max:40']],
                    ['header' => 'nama', 'field' => 'name', 'required' => true, 'rules' => ['string', 'max:200']],
                    ['header' => 'nama_badan_hukum', 'field' => 'legal_name', 'rules' => ['string', 'max:200']],
                    ['header' => 'npwp', 'field' => 'npwp', 'cast' => 'text', 'rules' => ['string', 'max:30']],
                    ['header' => 'pkp', 'field' => 'is_pkp', 'cast' => 'bool', 'default' => false],
                    ['header' => 'subkontraktor', 'field' => 'is_subcontractor', 'cast' => 'bool', 'default' => false],
                    ['header' => 'klasifikasi', 'field' => 'classification', 'rules' => ['in:material,jasa,ict,sipil,me'], 'default' => 'material'],
                    ['header' => 'alamat', 'field' => 'address', 'rules' => ['string', 'max:255']],
                    ['header' => 'kota', 'field' => 'city', 'rules' => ['string', 'max:80']],
                    ['header' => 'telepon', 'field' => 'phone', 'cast' => 'text', 'rules' => ['string', 'max:40']],
                    ['header' => 'email', 'field' => 'email', 'rules' => ['email', 'max:120']],
                    ['header' => 'pic', 'field' => 'pic_name', 'rules' => ['string', 'max:120']],
                    ['header' => 'bank', 'field' => 'bank_name', 'rules' => ['string', 'max:60']],
                    ['header' => 'no_rekening', 'field' => 'bank_account_no', 'cast' => 'text', 'rules' => ['string', 'max:40']],
                    ['header' => 'nama_rekening', 'field' => 'bank_account_name', 'rules' => ['string', 'max:120']],
                    ['header' => 'termin_bayar_hari', 'field' => 'payment_term_days', 'cast' => 'int', 'rules' => ['integer', 'min:0', 'max:365'], 'default' => 30],
                    ['header' => 'status', 'field' => 'status', 'rules' => ['in:active,inactive'], 'default' => 'active'],
                ],
            ],

            'customers' => [
                'label' => 'Pelanggan',
                'model' => Customer::class,
                'permission' => 'crm',
                'unique' => 'code',
                'columns' => [
                    ['header' => 'kode', 'field' => 'code', 'required' => true, 'cast' => 'text', 'rules' => ['string', 'max:40']],
                    ['header' => 'nama', 'field' => 'name', 'required' => true, 'rules' => ['string', 'max:200']],
                    ['header' => 'nama_badan_hukum', 'field' => 'legal_name', 'rules' => ['string', 'max:200']],
                    ['header' => 'npwp', 'field' => 'npwp', 'cast' => 'text', 'rules' => ['string', 'max:30']],
                    ['header' => 'pkp', 'field' => 'is_pkp', 'cast' => 'bool', 'default' => false],
                    ['header' => 'alamat_tagihan', 'field' => 'billing_address', 'rules' => ['string', 'max:255']],
                    ['header' => 'kota', 'field' => 'city', 'rules' => ['string', 'max:80']],
                    ['header' => 'provinsi', 'field' => 'province', 'rules' => ['string', 'max:80']],
                    ['header' => 'telepon', 'field' => 'phone', 'cast' => 'text', 'rules' => ['string', 'max:40']],
                    ['header' => 'email', 'field' => 'email', 'rules' => ['email', 'max:120']],
                    ['header' => 'pic', 'field' => 'pic_name', 'rules' => ['string', 'max:120']],
                    ['header' => 'pic_telepon', 'field' => 'pic_phone', 'cast' => 'text', 'rules' => ['string', 'max:40']],
                    ['header' => 'termin_bayar_hari', 'field' => 'payment_term_days', 'cast' => 'int', 'rules' => ['integer', 'min:0', 'max:365'], 'default' => 30],
                    ['header' => 'status', 'field' => 'status', 'rules' => ['in:active,inactive'], 'default' => 'active'],
                ],
            ],

            'employees' => [
                'label' => 'Karyawan',
                'model' => Employee::class,
                'permission' => 'hr',
                'unique' => 'code',
                'columns' => [
                    ['header' => 'kode', 'field' => 'code', 'required' => true, 'cast' => 'text', 'rules' => ['string', 'max:40']],
                    ['header' => 'nama', 'field' => 'name', 'required' => true, 'rules' => ['string', 'max:200']],
                    // NIK doubles as the tax id under PMK 112/2022, so a blank one
                    // costs the employee a 20% PPh 21 surcharge. Required.
                    ['header' => 'nik_ktp', 'field' => 'nik_ktp', 'required' => true, 'cast' => 'text', 'rules' => ['string', 'size:16']],
                    ['header' => 'npwp', 'field' => 'npwp', 'cast' => 'text', 'rules' => ['string', 'max:30']],
                    ['header' => 'jenis_kelamin', 'field' => 'gender', 'required' => true, 'rules' => ['in:male,female']],
                    ['header' => 'tanggal_lahir', 'field' => 'birth_date', 'required' => true, 'cast' => 'date', 'rules' => ['date']],
                    ['header' => 'status_ptkp', 'field' => 'ptkp_status', 'required' => true, 'rules' => ['in:TK/0,TK/1,TK/2,TK/3,K/0,K/1,K/2,K/3']],
                    ['header' => 'tanggal_masuk', 'field' => 'join_date', 'required' => true, 'cast' => 'date', 'rules' => ['date']],
                    ['header' => 'jenis_hubungan_kerja', 'field' => 'employment_type', 'required' => true, 'rules' => ['in:tetap,kontrak,harian']],
                    ['header' => 'jabatan', 'field' => 'position', 'required' => true, 'rules' => ['string', 'max:120']],
                    ['header' => 'departemen', 'field' => 'department', 'required' => true, 'rules' => ['in:proyek,engineering,keuangan,hrga,procurement,servis']],
                    ['header' => 'gaji_pokok', 'field' => 'base_salary', 'cast' => 'decimal', 'rules' => ['numeric', 'min:0'], 'default' => 0],
                    ['header' => 'bpjs_kesehatan_no', 'field' => 'bpjs_kesehatan_no', 'cast' => 'text', 'rules' => ['string', 'max:40']],
                    ['header' => 'bpjs_tk_no', 'field' => 'bpjs_tk_no', 'cast' => 'text', 'rules' => ['string', 'max:40']],
                    ['header' => 'bank', 'field' => 'bank_name', 'rules' => ['string', 'max:60']],
                    ['header' => 'no_rekening', 'field' => 'bank_account_no', 'cast' => 'text', 'rules' => ['string', 'max:40']],
                    ['header' => 'nama_rekening', 'field' => 'bank_account_name', 'rules' => ['string', 'max:120']],
                    ['header' => 'status', 'field' => 'status', 'rules' => ['in:active,resigned'], 'default' => 'active'],
                ],
            ],
            /*
             * P1-ENG. A tower of forty floors with four zones each is 160 rows
             * nobody should type into forms. Flat and honest: one row, one
             * location, matched on its globally unique code.
             *
             * The parent is looked up by CODE against rows already in the
             * database, and the importer validates the whole file BEFORE the
             * commit transaction writes any of it — so a child whose parent
             * arrives in the same file is reported on the first run and
             * resolves on the second (matching on code makes re-runs safe;
             * that is the importer's own documented workflow: import, read
             * the errors, import again). Order parents first or run the file
             * twice. The hierarchy invariants themselves (same project, no
             * cycle) are enforced by the Location model's saving hook, which
             * this importer's direct writes also pass through.
             */
            'locations' => [
                'label' => 'Lokasi Tapak (tower/lantai/zona)',
                'model' => Location::class,
                'permission' => 'prj',
                'unique' => 'code',
                'columns' => [
                    ['header' => 'kode', 'field' => 'code', 'required' => true, 'cast' => 'text', 'rules' => ['string', 'max:40']],
                    ['header' => 'nama', 'field' => 'name', 'required' => true, 'rules' => ['string', 'max:150']],
                    ['header' => 'proyek_kode', 'field' => 'project_id', 'required' => true, 'lookup' => ['prj_projects', 'code']],
                    ['header' => 'jenis', 'field' => 'kind', 'required' => true, 'rules' => ['in:tower,floor,zone,axis,room']],
                    ['header' => 'induk_kode', 'field' => 'parent_id', 'lookup' => ['core_locations', 'code']],
                    ['header' => 'urutan', 'field' => 'sort_order', 'cast' => 'int', 'rules' => ['integer', 'min:0'], 'default' => 0],
                ],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function definition(string $resource): array
    {
        $all = self::all();

        if (! isset($all[$resource])) {
            throw new \InvalidArgumentException("Jenis data master tidak dikenal: {$resource}.");
        }

        return $all[$resource] + ['key' => $resource];
    }
}
