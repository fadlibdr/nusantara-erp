<?php

namespace Modules\Core\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Exceptions\DeliveryRejectedException;
use Modules\Core\Exceptions\DeliverySkippedException;
use Modules\Core\Models\NotificationDelivery;
use Modules\Core\Support\DeliveryChannels;
use Modules\Core\Support\DeliveryGate;
use Modules\Core\Support\QuietHours;
use Throwable;

/**
 * Sampaikan SATU baris core_notification_deliveries lewat kanalnya
 * (Fase 0 / P-0b, T0b.3).
 *
 * Yang dipegang job hanya id barisnya: baris itulah kebenaran tentang
 * pengiriman ini, dan pekerja yang berjalan setahun kemudian membacanya
 * segar dari basis data, bukan dari payload yang dibekukan saat dispatch.
 *
 * Percobaan: 5, backoff 60 / 300 / 900 / 3600 detik (ROADMAP P-0b). Setiap
 * kegagalan menaikkan attempts, menyimpan pesan penyedia (error, ≤ 500) dan
 * next_attempt_at, lalu MELEMPAR ULANG supaya pekerja yang menjadwalkan
 * percobaan berikutnya. Setelah percobaan terakhir pekerja memanggil failed():
 * status `failed`, pesan terakhir tetap di error, dan barisnya menunggu
 * "Kirim ulang" di Sistem › Pengiriman Notifikasi. Tidak ada jalur yang
 * membuat kegagalan tampak berhasil.
 *
 * ShouldQueueAfterCommit: dispatch dari dalam transaksi ditunda sampai
 * commit — baris pengiriman yang belum ter-commit tidak boleh dikerjakan
 * pekerja lain.
 *
 * P-3a (11 Sep 2026) menambah dua hasil selain sent/failed, dan satu syarat:
 *
 *   skipped   kanal MEMUTUSKAN tidak mencoba (DeliverySkippedException:
 *             mailer masih log, kanal belum dikonfigurasi, penerima belum
 *             opt-in) — tanpa percobaan ulang, dengan sebabnya di error.
 *             Gerbang DeliveryGate diperiksa ulang di sini SEBELUM mengirim:
 *             keadaan bisa berubah antara baris ditulis dan job berjalan.
 *   failed    SEKETIKA bila penyedia menolak permanen (DeliveryRejectedException:
 *             token salah, template tidak ada) — mengulang lima kali hanya
 *             menunda kabar buruknya 78 menit.
 *   sent      HANYA dengan pengenal dari penyedia. Kanal yang memulangkan
 *             kosong tanpa melempar tidak memberi bukti; itu dicatat sebagai
 *             percobaan gagal. Sebelum ini MAIL_MAILER=log menghasilkan `sent`
 *             ber-Message-ID lokal — klaim tanpa server di baliknya.
 *
 * JAM TENANG (T3a.2) MENUNDA TANPA MEMAKAN PERCOBAAN. Job yang diambil pekerja
 * di dalam jendela tidak dilepas release(): release() menaikkan hitungan
 * percobaan PEKERJA, dan diukur 12 Sep 2026 (verifikasi P-3a) empat kegagalan
 * SMTP sementara dalam 21 menit + satu penundaan = pekerja menggagalkan job
 * pukul 06:00 SEBELUM handle() berjalan ("Percobaan habis"), tanpa percobaan
 * kelima — satu malam gangguan sudah cukup. Kini job yang tertunda selesai
 * dan MEWARISKAN sisa jadwalnya kepada job pengganti ($priorAttempts, $tries
 * sisa, backoff() yang dipotong) yang diantrekan untuk akhir jendela: lima
 * percobaan tetap lima, 1/5/15/60 berlanjut dari posisinya, attempts baris
 * tetap riwayat.
 */
class DeliverNotification implements ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Lima percobaan per pengiriman — dipaku literal di DeliveryRetryScheduleTest. */
    public const TRIES = 5;

    /** @var list<int> */
    public const BACKOFF = [60, 300, 900, 3600];

    /**
     * Sisa percobaan untuk job INI. Bawaan TRIES; job pengganti sesudah
     * penundaan jam tenang membawa sisanya (TRIES − $priorAttempts).
     */
    public int $tries = self::TRIES;

    /**
     * Percobaan yang sudah dipakai job pendahulu sebelum penundaan jam tenang
     * — posisi jadwal 1/5/15/60 berlanjut dari sini. Nol untuk job pertama
     * dan untuk Kirim ulang (yang memang memulai hitungan pekerja dari awal).
     */
    public int $priorAttempts = 0;

    public function __construct(public readonly int $deliveryId) {}

    /**
     * Jeda yang dibaca pekerja, dipotong sebanyak percobaan pendahulu supaya
     * indeks pekerja (attempts − 1) menunjuk posisi jadwal yang benar.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_values(array_slice(self::BACKOFF, $this->priorAttempts));
    }

    public function handle(): void
    {
        $delivery = NotificationDelivery::query()->with(['notification', 'notification.user'])->find($this->deliveryId);

        // Baris dihapus (notifikasinya dihapus, cascade) atau sudah selesai
        // lewat jalur lain: tidak ada yang perlu dikirim, dan mengirim ulang
        // e-mail yang sudah `sent` adalah kesalahan yang lebih buruk.
        if ($delivery === null || $delivery->status !== NotificationDelivery::QUEUED || $delivery->notification === null) {
            // Baris `failed` yang job-nya kembali lewat `queue:retry` di shell:
            // tetap dilewati (kebenaran pengiriman adalah barisnya — keputusan
            // pemilik, ROADMAP-HASHMICRO §5 #16; layar Antrean Gagal menolak
            // 422), tetapi TERLIHAT — sebelumnya queue:retry "berhasil" tanpa
            // jejak apa pun (verifikasi P-0b, 5 Sep 2026).
            if ($delivery !== null && $delivery->status === NotificationDelivery::FAILED) {
                Log::warning(
                    "Pengiriman #{$delivery->id} sudah berstatus failed — job DeliverNotification dilewati tanpa mengirim "
                    .'apa pun (queue:retry dari shell tidak mengirim ulang). Kirim ulang lewat Sistem › Pengiriman Notifikasi.'
                );
            }

            return;
        }

        // Gerbang yang sama dengan kotak keluar dan Kirim ulang, diperiksa
        // ULANG saat job benar-benar berjalan: sakelar yang dimatikan atau
        // mailer yang diganti sesudah baris ditulis membuat baris `skipped`
        // dengan sebabnya — bukan surat yang tetap keluar, bukan `failed`.
        $recipient = $delivery->notification->user;
        $reason = $recipient === null
            ? 'Penerima tidak ada lagi.'
            : DeliveryGate::reasonToSkip($delivery->channel, $recipient, $delivery->notification->template);

        if ($reason !== null) {
            $this->skip($delivery, $reason);

            return;
        }

        // Jam tenang (T3a.2), diperiksa ULANG di sini: backoff 60 s setelah
        // penolakan pukul 21.59 mendarat pukul 22.00 — di dalam jendela.
        // Baris tetap `queued` dan mengatakan sampai kapan; job ini selesai
        // tanpa mencatat percobaan, dan job PENGGANTI dengan sisa jadwalnya
        // menunggu akhir jendela (bukan release(): lihat docblock kelas).
        $postpone = DeliveryGate::postponement($recipient);

        if ($postpone !== null) {
            $delivery->forceFill([
                'next_attempt_at' => $postpone['until'],
                'error' => $postpone['reason'],
            ])->save();

            $this->handOverAfter($postpone['until']);

            return;
        }

        // attempts disimpan SEBELUM mengirim: pekerja yang dibunuh pcntl pada
        // batas --timeout (SMTP yang bisu) tidak pernah sampai ke blok catch,
        // dan tanpa ini baris tetap attempts=0 setelah lima kali dibunuh
        // (verifikasi P-0b, 5 Sep 2026).
        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        try {
            $providerId = trim((string) DeliveryChannels::for($delivery->channel)->send($delivery, $delivery->notification));

            if ($providerId === '') {
                throw new \RuntimeException(
                    'Kanal tidak memulangkan pengenal dari penyedia; tanpa itu tidak ada bukti pesan diterima, '
                    .'jadi status tidak ditandai terkirim.',
                );
            }
        } catch (DeliverySkippedException $e) {
            $this->skip($delivery, $e->getMessage());

            return;
        } catch (DeliveryRejectedException $e) {
            $delivery->forceFill([
                'status' => NotificationDelivery::FAILED,
                'error' => self::message($e),
                'next_attempt_at' => null,
            ])->save();

            return;
        } catch (Throwable $e) {
            // Percobaan ke-n gagal: catat, jadwalkan, lempar ulang. Yang
            // menentukan jadwal adalah hitungan PEKERJA untuk job ini
            // ($this->attempts(), 1..$tries) — bukan attempts baris, yang
            // adalah riwayat kumulatif dan berlanjut setelah Kirim ulang (baris
            // gagal mulai di 5: BACKOFF[5] tidak ada → next_attempt_at kosong
            // padahal pekerja masih menjadwalkan empat percobaan lagi —
            // verifikasi P-0b, 5 Sep 2026). Indeks backoff = percobaan pekerja
            // yang baru gagal - 1; percobaan terakhir = next_attempt_at kosong.
            // Sesudah penundaan jam tenang, job pengganti melanjutkan posisi
            // jadwal pendahulunya ($priorAttempts) — bukan mulai dari 60 s lagi.
            $attempt = $this->attempts();
            $delay = self::BACKOFF[$this->priorAttempts + $attempt - 1] ?? null;

            $delivery->forceFill([
                'error' => self::message($e),
                'next_attempt_at' => $delay === null || $attempt >= $this->tries ? null : now()->addSeconds($delay),
            ])->save();

            throw $e;
        }

        // Pesan BARU diterima penyedia: status penyedia (webhook) milik pesan
        // sebelumnya — bila ada — tidak boleh menempel padanya (verifikasi
        // P-3a, 12 Sep 2026: "Terkirim" hijau di samping "Gagal di jalan"
        // merah bertanggal sebelum jam Terkirim-nya).
        $delivery->forceFill([
            'status' => NotificationDelivery::SENT,
            'provider_id' => Str::limit($providerId, 190, ''),
            'provider_status' => null,
            'provider_status_at' => null,
            'error' => null,
            'sent_at' => now(),
            'next_attempt_at' => null,
        ])->save();
    }

    /**
     * Serahkan sisa jadwal kepada job pengganti yang menunggu akhir jam
     * tenang. Job ini sendiri selesai normal (pekerja menghapusnya), jadi
     * percobaan yang dipakainya untuk penundaan tidak dihitung siapa pun.
     *
     * Percobaan yang sudah terpakai = percobaan pendahulu + percobaan job ini
     * yang benar-benar GAGAL (attempts() − 1: yang sekarang adalah penundaan,
     * bukan kegagalan). Sisa selalu ≥ 1: attempts() ≤ $tries saat handle()
     * dijalankan pekerja.
     *
     * Tanpa antrean sungguhan (handle() dipanggil langsung, atau
     * QUEUE_CONNECTION=sync yang menjalankan job seketika dan mengabaikan
     * delay) tidak ada yang bisa menunggu: job berhenti di sini, seperti
     * release() dulu — baris sudah `queued` dengan next_attempt_at-nya.
     */
    private function handOverAfter(CarbonImmutable $until): void
    {
        if ($this->job === null || $this->job instanceof SyncJob) {
            return;
        }

        $successor = new self($this->deliveryId);
        $successor->priorAttempts = $this->priorAttempts + max(0, $this->attempts() - 1);
        $successor->tries = max(1, self::TRIES - $successor->priorAttempts);

        dispatch($successor->delay($until));
    }

    /**
     * `skipped`: tidak pernah dicoba (atau diputuskan tidak dicoba), dengan
     * sebabnya. attempts tidak disentuh — ini bukan percobaan — dan tidak ada
     * yang dilempar ulang: pekerja tidak punya apa-apa untuk diulang.
     */
    private function skip(NotificationDelivery $delivery, string $reason): void
    {
        $delivery->forceFill([
            'status' => NotificationDelivery::SKIPPED,
            'error' => Str::limit(trim($reason) === '' ? 'Dilewati tanpa sebab yang disebut kanal.' : trim($reason), 480),
            'next_attempt_at' => null,
        ])->save();
    }

    /**
     * Dipanggil pekerja setelah percobaan terakhir (atau job kedaluwarsa).
     *
     * Dua pengecualian datang dari PEKERJA, bukan penyedia, dan kalimat
     * Inggrisnya ("… has timed out.", "… has been attempted too many times.")
     * bukan pesan penyedia yang dijanjikan kolom error: kehabisan waktu
     * (pcntl membunuh proses pada --timeout) dan percobaan yang habis tanpa
     * sempat melempar. Keduanya ditulis dalam kalimat kita, dengan pesan
     * penyedia terakhir yang masih tersimpan ikut dibawa — kecuali bila yang
     * tersimpan adalah kalimat penundaan jam tenang: itu bukan pesan penyedia
     * (verifikasi P-3a, 12 Sep 2026).
     */
    public function failed(?Throwable $e): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== NotificationDelivery::QUEUED) {
            return;
        }

        $last = QuietHours::isPostponedSentence($delivery->error) ? '' : trim((string) $delivery->error);
        $suffix = $last === '' ? '' : " Pesan penyedia terakhir: {$last}";

        $error = match (true) {
            $e === null => $last === '' ? 'Gagal tanpa pesan.' : $last,
            $e instanceof TimeoutExceededException => 'Pekerja antrean kehabisan waktu saat mengirim — penyedia tidak menjawab dalam batas waktu pekerja.'.$suffix,
            $e instanceof MaxAttemptsExceededException => 'Percobaan habis sebelum penyedia menjawab.'.$suffix,
            default => self::message($e),
        };

        $delivery->forceFill([
            'status' => NotificationDelivery::FAILED,
            'error' => Str::limit($error, 480),
            'next_attempt_at' => null,
        ])->save();
    }

    private static function message(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return Str::limit($message === '' ? get_class($e) : $message, 480);
    }
}
