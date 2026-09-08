<?php

namespace Modules\Crm\Services;

use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Lead;

/**
 * `crm_leads.next_follow_up_at` — SATU definisi, dan sejak F-3 ia turunan.
 *
 * ATURANNYA, satu kalimat: tanggal tindak lanjut sebuah prospek adalah `due_at`
 * TERAWAL di antara aktivitas prospek itu yang BELUM SELESAI (`done_at` kosong,
 * baris tidak dihapus) dan yang PUNYA tanggal. Tidak ada aktivitas terbuka
 * bertanggal → kolomnya null, dan layarnya menulis "—", bukan tanggal lama yang
 * sudah tidak berarti apa-apa.
 *
 * KENAPA KOLOMNYA TETAP ADA (dan bukan dihitung saat dibaca). Daftar prospek
 * mengurutkan dan menyaring dengan kolom ini (LeadController::listing sortable),
 * kalender dan pengawas tenggat membaca kolom, dan seluruh mesin itu bekerja di
 * atas SQL — sebuah accessor PHP tidak bisa diurutkan basis data. Jadi kolomnya
 * tinggal sebagai CACHE TURUNAN dengan satu penulis: kelas ini, dipanggil
 * ActivityService pada setiap perubahan aktivitas. Permukaan tulis lamanya
 * (POST/PUT prospek) menolak field-nya dengan kalimat yang menyebut jalan yang
 * benar — LeadStoreRequest/LeadUpdateRequest — karena sebuah kolom turunan yang
 * masih menerima ketikan akan berselisih dengan sumbernya pada hari pertama.
 *
 * Aktivitas pada PENAWARAN dan PELANGGAN milik prospek yang sama sengaja TIDAK
 * ikut dihitung: keduanya dokumen lain dengan layarnya sendiri, dan menariknya
 * ke sini akan membuat tanggal di baris prospek berubah karena sesuatu yang
 * tidak terlihat di layar prospek itu.
 */
class LeadFollowUpService
{
    /**
     * Tanggal turunan untuk satu prospek — 'YYYY-MM-DD' atau null.
     *
     * Baca lewat query, bukan lewat koleksi yang sudah termuat: pemanggilnya
     * baru saja menulis satu baris, dan koleksi yang termuat sebelum tulisan
     * itu akan menurunkan tanggal kemarin.
     */
    public function derive(int $leadId): ?string
    {
        $earliest = Activity::query()
            ->for('lead', $leadId)
            ->open()
            ->whereNotNull('due_at')
            ->min('due_at');

        // MySQL memulangkan 'YYYY-MM-DD', SQLite bisa memulangkan
        // 'YYYY-MM-DD 00:00:00' untuk kolom date yang ditulis lewat cast —
        // sepuluh karakter pertamanya adalah harinya pada kedua driver.
        return $earliest === null ? null : substr((string) $earliest, 0, 10);
    }

    /**
     * Tulis turunannya ke kolomnya. Memulangkan tanggal yang berlaku sekarang.
     *
     * forceFill + saveQuietly: ini cache turunan, bukan sunting pengguna —
     * tidak ada yang boleh menganggapnya perubahan dokumen.
     */
    public function recompute(Lead $lead): ?string
    {
        $derived = $this->derive((int) $lead->id);

        if ($lead->next_follow_up_at?->toDateString() !== $derived) {
            $lead->forceFill(['next_follow_up_at' => $derived])->saveQuietly();
        }

        return $derived;
    }

    /**
     * Bentuk yang dipanggil ActivityService: hitung ulang hanya bila dokumen
     * yang berubah memang sebuah prospek. Aktivitas penawaran/pelanggan lewat
     * di sini tanpa efek — dan itulah aturannya, bukan kelalaian.
     */
    public function recomputeFor(string $documentType, int $documentId): ?string
    {
        if ($documentType !== 'lead') {
            return null;
        }

        $lead = Lead::query()->find($documentId);

        return $lead === null ? null : $this->recompute($lead);
    }
}
