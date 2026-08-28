<?php

namespace Modules\Projects\Enums;

/**
 * Dua belas jabatan tabel JUMLAH ORANG pada pad FM-10-12 milik pemilik —
 * persis, urut, dan tidak lebih.
 *
 * Enum ini MENGGANTIKAN konstanta LaporanFormService::MANPOWER_ROLES: satu
 * sumber, dua pemakai. Validasi (DailyReportStore/UpdateRequest) menerima
 * hanya value di sini; pad cetak (LaporanFormService::harian) meng-iterasi
 * cases() dengan label() sebagai teks barisnya dan value sebagai kunci
 * pencocokan ke prj_daily_report_manpower.role_key. Aturan kejujuran lama
 * tetap berlaku lewat pintu ini: baris yang mengisi dirinya di cetakan harus
 * lolos lewat daftar ini, dan sebuah role_key yang tidak dikenal tidak pernah
 * sampai ke basis data.
 *
 * value adalah kunci simpanan (string 40, stabil — mengganti value adalah
 * migrasi data); label() adalah teks cetak, huruf demi huruf dari pad.
 */
enum DailyReportRole: string
{
    case ProjectManager = 'project_manager';
    case DeputyProjectManager = 'deputy_project_manager';
    case Engineering = 'engineering';
    case Komersial = 'komersial';
    case Keuangan = 'keuangan';
    case Danlat = 'danlat';
    case Produksi = 'produksi';
    case SafetyOfficer = 'safety_officer';
    case MandorSipil = 'mandor_sipil';
    case MandorArsitek = 'mandor_arsitek';
    case MandorMep = 'mandor_mep';
    case Subkont = 'subkont';

    public function label(): string
    {
        return match ($this) {
            self::ProjectManager => 'Project Manager',
            self::DeputyProjectManager => 'Deputy Project Manager',
            self::Engineering => 'Engineering',
            self::Komersial => 'Komersial',
            self::Keuangan => 'Keuangan',
            self::Danlat => 'Danlat',
            self::Produksi => 'Produksi',
            self::SafetyOfficer => 'Safety Officer',
            self::MandorSipil => 'Mandor Sipil + Tukang',
            self::MandorArsitek => 'Mandor Arsitek + Tukang',
            self::MandorMep => 'Mandor MEP + Tukang',
            self::Subkont => 'Subkont',
        };
    }
}
