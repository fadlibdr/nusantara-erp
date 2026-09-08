<?php

namespace Modules\Crm\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Proposal = 'proposal';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Baru',
            self::Contacted => 'Sudah Dihubungi',
            self::Qualified => 'Terkualifikasi',
            self::Proposal => 'Penawaran Dikirim',
            self::Won => 'Menang',
            self::Lost => 'Kalah',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Won, self::Lost], true);
    }

    /** Sudah diputuskan penawarannya — menang atau kalah. */
    public function isClosed(): bool
    {
        return ! $this->isOpen();
    }

    /**
     * Urutan corong. null untuk menang/kalah: keduanya bukan tahap yang lebih
     * jauh dari "Penawaran Dikirim", melainkan HASIL — dan memberi mereka
     * nomor akan membuat "mundur dari Menang ke Penawaran" terhitung sebagai
     * perpindahan biasa yang cukup dijelaskan satu alasan.
     */
    public function rank(): ?int
    {
        return match ($this) {
            self::New => 0,
            self::Contacted => 1,
            self::Qualified => 2,
            self::Proposal => 3,
            self::Won, self::Lost => null,
        };
    }

    /**
     * ATURAN TRANSISI PIPELINE (F-3 / T3.5), satu tempat.
     *
     * Maju bebas (boleh melompati tahap: sebuah prospek yang datang lewat
     * undangan tender memang lahir langsung terkualifikasi). Mundur boleh,
     * tetapi menuntut alasan yang tersimpan dan terbaca di riwayat — mundur
     * adalah kabar buruk, dan corong yang bisa dimundurkan diam-diam adalah
     * corong yang angka konversinya tidak berarti apa-apa. Menang/Kalah TIDAK
     * PERNAH lewat tahap: keduanya milik penawaran (Tandai Menang / Tandai
     * Kalah), yang sekaligus mencatat nilai, tanggal keputusan, dan alasan
     * kalah — sebuah kartu yang diseret ke kolom Menang akan menciptakan
     * kemenangan tanpa satu rupiah pun di belakangnya.
     *
     * Kalimat penolakannya BUKAN di sini melainkan di LeadPipelineService:
     * enum ini tidak tahu kode penawaran mana yang harus disebut.
     */
    public function canMoveTo(self $to): LeadMove
    {
        if ($this === $to) {
            return LeadMove::Sama;
        }

        if ($to->isClosed()) {
            return LeadMove::LewatPenawaran;
        }

        if ($this->isClosed()) {
            return LeadMove::Terkunci;
        }

        return $to->rank() > $this->rank() ? LeadMove::Maju : LeadMove::Mundur;
    }
}
