<?php

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Support\AttachableDocuments;

class Attachment extends BaseModel
{
    /**
     * TIGA KEADAAN, DAN YANG PERTAMA ADALAH KEADAAN NORMAL (F-8).
     *
     * `valid_until` nullable, dan hampir setiap baris tabel ini tidak akan
     * pernah punya tanggalnya: foto lapangan, nota, gambar kerja, selfie
     * absen. "Tanpa masa berlaku" karena itu adalah keadaan TERSENDIRI, bukan
     * cabang lain dari "kedaluwarsa" dan bukan "lupa diisi" — dan setiap
     * permukaan yang menampilkannya wajib menampilkannya sebagai keterangan,
     * bukan peringatan.
     */
    public const VALIDITY_NONE = 'tanpa_masa_berlaku';

    public const VALIDITY_OK = 'berlaku';

    public const VALIDITY_NEAR = 'menipis';

    public const VALIDITY_EXPIRED = 'kedaluwarsa';

    /**
     * Berapa hari sebelum tanggalnya sebuah lampiran disebut MENIPIS.
     *
     * SATU angka, dibaca DUA permukaan: entri WatchedDeadlines
     * attachment_valid_until_* (yang mengirim pemberitahuan 08.30 dan mengisi
     * layar Tenggat) dan kartu lampiran di layar dokumen. Angka kedua di salah
     * satunya adalah cara termurah membuat kartu berkata "masih berlaku"
     * sementara kotak masuk berkata "mendekati akhir masa berlaku" tentang
     * berkas yang sama — persis kontradiksi yang ditutup F-3 untuk aktivitas
     * CRM dan F-7 untuk servis alat. 30 hari, angka yang sama dengan dokumen
     * vendor (prc_vendor_documents): satu bulan cukup untuk mengurus
     * perpanjangan polis atau sertifikat, dan tidak begitu lama sampai orang
     * belajar mengabaikannya.
     */
    public const VALID_UNTIL_LEAD_DAYS = 30;

    protected $table = 'core_attachments';

    /**
     * Keadaan masa berlaku ikut SETIAP lampiran yang diserialisasi, bukan
     * dihitung ulang di klien: aturan "berlaku s/d masih sah PADA hari
     * terakhirnya" hidup satu kali, di sini.
     *
     * @var array<int, string>
     */
    protected $appends = ['validity'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'integer',
            'taken_at' => 'datetime',
            /*
             * 'date:Y-m-d', bukan 'date' polos — dan itu bukan selera.
             *
             * Cast 'date' polos menserialisasi "2027-06-30" menjadi
             * "2027-06-29T17:00:00.000000Z" (Asia/Jakarta → UTC). fmt.date()
             * di SPA membacanya dengan getDate() waktu lokal, jadi ia benar
             * HANYA di peramban yang zonanya +07:00 atau lebih timur;
             * satu peramban ber-UTC dan kartu lampiran menulis 29 Jun untuk
             * berkas yang berlaku sampai 30 Jun. Masa berlaku berbutir HARI —
             * jam 17.00 di dalamnya bukan informasi, ia hanya risiko geser
             * satu hari. Dikirim sebagai tanggal telanjang, ia juga langsung
             * muat di <input type="date"> tanpa konversi apa pun.
             */
            'valid_until' => 'date:Y-m-d',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Named `uploader`, not `uploadedBy`. The latter serialises to the key
     * "uploaded_by", which is also the foreign-key column — the relation
     * overwrites the integer, and any client reading either one gets the other.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function hasPosition(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * "Berlaku s/d" berarti masih sah PADA hari terakhirnya: kedaluwarsa baru
     * mulai hari berikutnya, dan NULL berarti tidak kedaluwarsa sama sekali.
     * Bacaan yang SAMA dengan VendorDocument::isExpired, Guarantee::isExpired
     * dan bendera valid_through_end di WatchedDeadlines — supaya kartu,
     * layar Tenggat dan pemberitahuan pagi tidak pernah menyebut berkas yang
     * sama dengan dua status berbeda.
     */
    public function isExpired(): bool
    {
        return $this->valid_until !== null
            && $this->valid_until->toDateString() < now()->toDateString();
    }

    /**
     * Sisa hari sampai masa berlakunya habis; negatif bila sudah lewat, null
     * bila memang tidak punya masa berlaku. 0 = hari terakhirnya, yang MASIH
     * berlaku.
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->valid_until === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->valid_until->startOfDay());
    }

    /** Salah satu dari empat VALIDITY_* — lihat konstanta di kepala kelas. */
    public function validityState(): string
    {
        if ($this->valid_until === null) {
            return self::VALIDITY_NONE;
        }

        if ($this->isExpired()) {
            return self::VALIDITY_EXPIRED;
        }

        return $this->daysUntilExpiry() <= self::VALID_UNTIL_LEAD_DAYS
            ? self::VALIDITY_NEAR
            : self::VALIDITY_OK;
    }

    /**
     * Bentuk yang dikirim ke klien. `lead_days` ikut supaya kartu bisa
     * menuliskan jendela peringatannya tanpa menyalin angkanya.
     *
     * @return array{state: string, days: int|null, lead_days: int}
     */
    public function getValidityAttribute(): array
    {
        return [
            'state' => $this->validityState(),
            'days' => $this->daysUntilExpiry(),
            'lead_days' => self::VALID_UNTIL_LEAD_DAYS,
        ];
    }

    /** The slug the API and the SPA address this attachment's parent by. */
    public function documentSlug(): ?string
    {
        return AttachableDocuments::slugForClass($this->attachable_type);
    }

    /**
     * Whether a browser may render this in place rather than downloading it.
     * Images and PDFs only — anything else is served as a download so a file
     * that turns out to be markup cannot execute in the application's origin.
     */
    public function isInlineSafe(): bool
    {
        return str_starts_with($this->mime, 'image/') || $this->mime === 'application/pdf';
    }
}
