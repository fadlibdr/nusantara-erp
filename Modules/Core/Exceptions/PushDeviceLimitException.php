<?php

namespace Modules\Core\Exceptions;

use RuntimeException;

/**
 * Plafon perangkat web push per pengguna terlampaui — PushSubscriptions::
 * register() (P-3e, putaran verifikasi: A-5).
 *
 * Kelas sendiri supaya pendaftaran perangkat bisa menjawab 422 dengan kalimat
 * yang menyebut plafonnya dan apa yang harus dilakukan, sementara pemanggil
 * lain (rotasi) tidak pernah menemuinya: rotasi tidak pernah MEMBUAT baris,
 * ia hanya memindahkan yang sudah ada, jadi ia tidak bisa melewati plafon.
 */
class PushDeviceLimitException extends RuntimeException {}
