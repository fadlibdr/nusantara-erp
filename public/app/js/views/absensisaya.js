/* Absensi Saya — absen masuk dan pulang dari ponsel sendiri (F-4).
 *
 * Satu kolom, dua tombol besar, dan tidak ada yang harus diketik. Orang yang
 * memakainya sedang berdiri di gerbang proyek pukul tujuh pagi dengan satu
 * tangan bebas.
 *
 * TIGA KALIMAT YANG TIDAK BOLEH DIKATAKAN LAYAR INI.
 *
 * 1. "Di lokasi" untuk absen yang posisinya tidak pernah terukur. Server
 *    mengirim tiga keadaan (inside/outside/unknown) beserta kalimatnya sendiri;
 *    layar mengulang kalimat itu dan tidak pernah menyusunnya dari angka.
 * 2. "0 m" untuk jarak yang tidak diketahui. `distance_text` sudah bergaris.
 * 3. "Terkirim" untuk butir yang masih di antrean. Absen yang belum sampai
 *    server tampil sebagai baris antrean, bukan sebagai keberhasilan.
 *
 * Izin lokasi yang ditolak TIDAK menghalangi absen: absensi tanpa posisi tetap
 * jauh lebih berguna daripada tidak ada absensi. Itu aturan paketnya, dan layar
 * ini mengatakannya sebelum orangnya menekan tombol, bukan sesudah.
 */

import { api } from '../api.js';
import { el, clear, button, badge, errorState, toastError, offlineRibbon, field } from '../ui.js';
import * as fmt from '../format.js';
import {
  MAX_BYTES, devicePosition, readAsBase64, readQueue, enqueue, retry, listen, notify, queueRows, pendingCard,
} from '../uploadqueue.js';

const ENDPOINT = {
  check_in: 'hr/attendances/me/clock-in',
  check_out: 'hr/attendances/me/clock-out',
};

const SIDE_LABEL = { check_in: 'Absen masuk', check_out: 'Absen pulang' };

/* Butir antrean absensi dikelompokkan dengan slug semu supaya kartu "belum
   terkirim" milik uploadqueue.js bisa mengenali mana yang sudah tampil di layar
   ini — mekanisme yang sama dengan `slug:id` foto lampiran. */
const QUEUE_SLUG = 'hr/attendances/me';

const state = { projectId: '' };

function rows(payload) {
  if (Array.isArray(payload)) return payload;
  return (payload && payload.data) || [];
}

function verdictBadge(side) {
  if (!side.recorded) return badge('Belum tercatat', '');
  if (side.verdict === 'unknown') return badge(side.verdict_text, '');
  return badge(side.verdict_text, side.verdict === 'outside' ? 'red' : 'green');
}

/**
 * Satu hari sebagai satu kartu.
 *
 * Jam server dan jam perangkat ditulis BERDAMPINGAN ketika keduanya ada dan
 * berbeda: itulah satu-satunya cara pembacanya tahu absen ini sempat menginap
 * di antrean, dan berapa lama.
 */
function dayCard(row) {
  const sides = [['check_in', row.check_in], ['check_out', row.check_out]].map(([key, side]) => {
    const lines = [];

    if (side.recorded) {
      lines.push(el('.cell-sub', {
        text: side.device_time_text && side.device_time_text !== side.time_text
          ? `Tercatat server ${side.time_text} · ditekan di ponsel ${side.device_time_text}`
          : `Tercatat ${side.time_text}`,
      }));
      lines.push(el('.cell-sub', {
        text: `Jarak ke titik proyek: ${side.distance_text}`
          + (side.accuracy_m ? ` · akurasi fix ±${side.accuracy_m} m` : ''),
      }));
    } else {
      lines.push(el('.cell-sub', { text: 'Belum ada catatan.' }));
    }

    return el('.absensi-side', [
      el('.absensi-side-head', [
        el('strong', { text: SIDE_LABEL[key] }),
        el('.spacer'),
        verdictBadge(side),
      ]),
      ...lines,
    ]);
  });

  return el('.card.absensi-day', [
    el('.card-head', [
      el('h2', { text: fmt.date(row.date) }),
      el('.spacer'),
      badge(row.status_label || row.status || '—', row.status === 'hadir' ? 'green' : ''),
    ]),
    el('.card-body', [
      el('.cell-sub', { text: row.project ? `Proyek: ${row.project.code} — ${row.project.name}` : 'Tanpa proyek.' }),
      ...sides,
    ]),
  ]);
}

/**
 * Menekan tombol absen.
 *
 * Posisi diminta dan selfie dibaca BERSAMAAN — fix GPS adalah paruh yang lambat
 * (sampai GEO_TIMEOUT_MS) — lalu keduanya masuk antrean sebagai satu butir.
 * Butir, bukan permintaan langsung: di gerbang proyek dengan satu bar sinyal,
 * permintaan yang gagal berarti orangnya berdiri menekan tombol berulang kali
 * sampai menyerah, dan tidak ada catatan sama sekali.
 */
async function punch(side, file, onQueued) {
  const positioning = devicePosition();

  let content = null;
  if (file) {
    /* Foto yang ditolak MENGORBANKAN FOTONYA, bukan absennya. Sebelumnya baris
       ini `return` — jadi menekan "Absen pulang + selfie" dengan foto 6 MB
       (ukuran biasa kamera ponsel modern) tidak mengirim apa pun, tidak
       meninggalkan baris antrean, dan hanya memunculkan toast tentang FOTO.
       Orangnya pulang mengira sudah absen. Itu kebalikan persis dari aturan
       paket ini, dan servernya sendiri sudah menanganinya dengan benar —
       kliennya yang memotong jalan sebelum sampai ke sana. */
    if (file.size > MAX_BYTES) {
      toastError(new Error(`Foto ${(file.size / 1024 / 1024).toFixed(1)} MB melebihi batas 5 MB — `
        + 'absensi tetap dikirim TANPA foto.'));
    } else {
      try {
        content = await readAsBase64(file);
      } catch (error) {
        // Foto yang tidak terbaca juga tidak membatalkan absennya.
        toastError(error);
      }
    }
  }

  const item = enqueue({
    kind: 'clock',
    endpoint: ENDPOINT[side],
    fields: {
      project_id: state.projectId || null,
      // Jam ponsel saat tombol ditekan, dikirim apa adanya. Server MENCATATNYA
      // di samping jam server dan hanya memakainya untuk memilih tanggal
      // barisnya — lihat AttendanceClockService.
      device_at: new Date().toISOString(),
      selfie_filename: file ? (file.name || `selfie-${Date.now()}.jpg`) : null,
    },
    slug: QUEUE_SLUG,
    id: side,
    label: SIDE_LABEL[side],
    filename: `${SIDE_LABEL[side]} ${fmt.today()}`,
    content,
    size: file ? file.size : 0,
    position: null,
    state: 'locating',
    discardTitle: 'Buang absensi ini?',
    discardMessage: 'Absensi ini dibuang dari antrean dan tidak akan pernah sampai ke server. '
      + 'Kalau Anda memang bekerja hari ini, mintalah pengawas mencatatkannya.',
  });

  item.position = await positioning;
  // Butir yang dibuang saat GPS masih mencari tidak boleh kembali menjadi "queued".
  if (!readQueue().includes(item)) return;

  if (onQueued) onQueued();
  // retry() adalah jalur yang sama dengan tombol "Kirim ulang": satu pintu.
  retry(item);
}

export async function renderAbsensiSaya(host) {
  clear(host);

  /* Kalimat pengantar ditahan sampai kita tahu akun ini BISA absen. Untuk
     empat akun demo tanpa kartu karyawan, janji "absensi Anda tetap tersimpan"
     berdiri tepat di atas kalimat "akun ini belum ditautkan" — dan yang dibaca
     orang lebih dulu adalah janjinya. */
  const desc = el('.desc');
  host.appendChild(el('.page-head', [el('div', [el('h1', { text: 'Absensi Saya' }), desc])]));

  const ribbon = offlineRibbon(() => (readQueue().length
    ? 'Tanpa koneksi. Absensi yang sudah ditekan tersimpan di ponsel ini — setelah sinyal kembali, '
      + 'tekan "Kirim ulang" pada barisnya.'
    : 'Tanpa koneksi. Absensi yang Anda tekan sekarang tersimpan di ponsel ini sampai sinyal kembali.'));

  const actions = el('div');
  const list = el('div');
  /* Antrean DI LUAR kedua kotak yang dilukis ulang load(), dan itu keputusan
     yang dibayar sekali: selama luring, load() gagal (api.get tidak bisa
     terhubung) dan segalanya yang dilukisnya lenyap. Kalau barisnya ikut
     lenyap, pita luring di atas menyuruh orang menekan "Kirim ulang" pada
     baris yang tidak ada di layar — persis kebohongan yang P1-I bayar mahal
     untuk diperbaiki. Terukur di chromium 8 Sep 2026 (harness S31): sebelum
     baris ini, layar luring hanya berisi panel galat. */
  const queueNode = queueRows((item) => item.slug === QUEUE_SLUG, { doc: `${QUEUE_SLUG}:panel` });

  // Keterangannya disusun uploadqueue.js dari isi antreannya sendiri: kartu ini
  // bisa memuat foto lapangan, absensi dari hari lain, atau keduanya.
  host.append(ribbon, pendingCard({ filter: (item) => item.slug !== QUEUE_SLUG }), actions, queueNode, list);

  /* Jawaban terakhir yang BERHASIL. Dipakai saat muatan berikutnya gagal:
     tombol absen harus tetap ada di layar tanpa sinyal — antreannya memang
     dibuat untuk itu — dan satu-satunya yang hilang adalah daftar harinya. */
  let lastPayload = null;

  /* Nomor urut muatan. Dua absen beruntun memanggil load() dua kali; tanpa
     penanda ini keduanya membersihkan lalu menempel, dan layarnya berakhir
     dengan DUA kartu tombol (terukur 8 Sep 2026: delapan tombol untuk empat).
     Idiom yang sama dengan absensi.js. */
  let loadToken = 0;

  const camera = el('input', { type: 'file', accept: 'image/*', capture: 'user', style: { display: 'none' } });
  let pendingSide = null;
  camera.addEventListener('change', async () => {
    const file = camera.files && camera.files[0];
    camera.value = '';
    if (!pendingSide) return;
    const side = pendingSide;
    pendingSide = null;
    await punch(side, file || null, load);
  });
  host.appendChild(camera);

  function projectSelect(payload) {
    const select = el('select', [el('option', { value: '', text: '— Tanpa proyek —' })]);
    (payload.projects || []).forEach((project) => {
      select.appendChild(el('option', { value: String(project.id), text: `${project.code} — ${project.name}` }));
    });
    select.value = state.projectId;
    // Dibaca balik: proyek yang sejak kunjungan terakhir hilang dari daftar
    // membuat penetapan nilainya diam-diam menjadi '' — dan tanpa baris ini
    // kotaknya bertuliskan "Tanpa proyek" sementara absennya tetap mengirim
    // id proyek lama.
    state.projectId = select.value;
    select.addEventListener('change', () => { state.projectId = select.value; });
    return select;
  }

  function paintActions(payload, { stale = false, blind = false } = {}) {
    clear(actions);

    if (!payload.linked) {
      desc.textContent = 'Layar absensi pribadi.';
      actions.appendChild(el('.alert.info', {
        text: 'Akun ini belum ditautkan ke data karyawan, jadi tidak ada absensi yang bisa dicatat '
          + 'atas namanya. Minta HR menautkan akun Anda ke kartu karyawan.',
      }));
      return;
    }

    desc.textContent = 'Absen masuk dan pulang dari ponsel. Lokasi dicatat kalau ponsel '
      + 'memberikannya — absensi Anda tetap tersimpan meski lokasi ditolak atau Anda berada jauh '
      + 'dari titik proyek.';

    const today = (payload.data || []).find((row) => row.date === fmt.today()) || null;
    const doneIn = Boolean(today && today.check_in.recorded);
    const doneOut = Boolean(today && today.check_out.recorded);

    actions.appendChild(el('.card', [
      el('.card-body', [
        stale ? el('.alert.warn', {
          text: blind
            ? 'Daftar absensi belum pernah termuat di perangkat ini, jadi layar ini belum tahu '
              + 'absensi Anda hari ini maupun daftar proyeknya. Tombol di bawah tetap bekerja: '
              + 'absensinya masuk antrean di ponsel ini dan terkirim setelah sinyal kembali.'
            : 'Daftar absensi tidak dapat dimuat sekarang. Tombol di bawah tetap bekerja: '
              + 'absensinya masuk antrean di ponsel ini dan terkirim setelah sinyal kembali.',
        }) : null,
        field('Proyek hari ini', projectSelect(payload), {
          help: blind
            ? 'Daftar proyek belum termuat. Absensi tetap terkirim tanpa proyek — jaraknya tidak '
              + 'terukur, dan barisnya akan bergaris.'
            : 'Dipakai untuk mengukur jarak Anda ke titik proyek. Tanpa proyek, atau pada proyek '
              + 'yang titik petanya belum diisi, jaraknya tidak terukur dan barisnya bergaris.',
        }),
        el('.absensi-actions', [
          el('p.cell-sub', {
            text: 'Selfie bersifat pilihan dan maksimal 5 MB. Foto yang lebih besar tidak terkirim, '
              + 'tetapi absensinya tetap dikirim.',
          }),
          button(doneIn ? 'Absen masuk (sudah tercatat)' : 'Absen masuk + selfie', {
            variant: 'primary', size: 'lg', iconName: 'camera', onClick: () => { pendingSide = 'check_in'; camera.click(); },
          }),
          button('Absen masuk tanpa foto', { size: 'lg', onClick: () => punch('check_in', null, load) }),
          button(doneOut ? 'Absen pulang (catat ulang)' : 'Absen pulang + selfie', {
            variant: 'primary', size: 'lg', iconName: 'camera', onClick: () => { pendingSide = 'check_out'; camera.click(); },
          }),
          button('Absen pulang tanpa foto', { size: 'lg', onClick: () => punch('check_out', null, load) }),
        ]),
        el('p.cell-sub', {
          // Radius yang TIDAK diketahui tidak disebut angkanya. "Radius yang
          // berlaku: null m" adalah kalimat yang lebih buruk daripada diam.
          text: payload.geofence_m === null || payload.geofence_m === undefined
            ? 'Di luar radius lokasi proyek, absensi Anda TETAP tersimpan dan hanya ditandai untuk '
              + 'dilihat pengawas. Radius yang berlaku baru terbaca setelah layar ini terhubung.'
            : `Radius lokasi yang berlaku: ${payload.geofence_m} m. Di luar radius, absensi Anda `
              + 'TETAP tersimpan dan hanya ditandai untuk dilihat pengawas.',
        }),
      ].filter(Boolean)),
    ]));
  }

  async function load() {
    const token = ++loadToken;
    if (!lastPayload) clear(actions).appendChild(el('p.muted', { text: 'Memuat…' }));

    let payload;
    try {
      payload = await api.get('hr/attendances/me');
    } catch (error) {
      if (token !== loadToken) return;

      /* Gagal memuat TIDAK boleh menghapus tombolnya — termasuk pada kunjungan
         PERTAMA yang luring, yang justru kasus tersulit: sebelum perbaikan ini
         layarnya hanya berisi panel galat sementara pita luring menyuruh orang
         menekan "Kirim ulang" pada baris yang tidak ada, dan tidak ada satu pun
         tombol absen. Itu persis cacat P1-I yang mahal, terulang.

         Pintu absen tidak membutuhkan daftar hari maupun daftar proyek, jadi
         tombolnya bisa digambar tanpa jawaban server sama sekali. Yang tidak
         bisa ditebak dinyatakan apa adanya: proyek tidak dapat dipilih, dan
         radius yang berlaku tidak diketahui. */
      clear(list).appendChild(errorState(error, load));
      paintActions(lastPayload || {
        linked: true,
        data: [],
        projects: [],
        geofence_m: null,
      }, { stale: true, blind: !lastPayload });
      return;
    }

    if (token !== loadToken) return;

    /* Daftar proyek menempel pada payload supaya paintActions() bisa
       menggambarnya ulang dari cache saat luring — sumbernya endpoint yang
       berbeda, dan memintanya lagi saat jaringan mati akan gagal juga. */
    if (payload.linked && !payload.projects) {
      try {
        payload.projects = rows(await api.get('projects', { per_page: 100 }));
      } catch {
        // Daftar proyek yang gagal dimuat tidak menghalangi absen: tanpa
        // proyek, jaraknya memang tidak terukur, dan layarnya mengatakan itu.
        payload.projects = (lastPayload && lastPayload.projects) || [];
      }
      if (token !== loadToken) return;
    }

    lastPayload = payload;
    paintActions(payload);
    clear(list);

    if (!payload.linked) return;

    if (!payload.data.length) {
      list.appendChild(el('.alert.info', { text: 'Belum ada absensi tercatat atas nama Anda.' }));
      return;
    }

    payload.data.forEach((row) => list.appendChild(dayCard(row)));
  }

  listen(list, (event, item) => {
    if (event === 'sent' && item.slug === QUEUE_SLUG) load();
  });

  await load();
  notify('mounted');
}
