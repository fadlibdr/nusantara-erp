/* Absensi Harian — lembar absen satu lokasi, satu tanggal, banyak orang
   (temuan #22 paruh 2).

   Bentuk layarnya mengikuti kertas yang digantikannya: kerani pilih tanggal
   dan proyek sekali, lalu menandai status per orang dan menekan satu tombol
   Simpan. Server meng-upsert pada kunci (karyawan, tanggal), jadi lembar yang
   dikirim ulang setelah koneksi putus MEMPERBAIKI hari itu, bukan
   menggandakannya — lihat AttendanceService::bulkUpsert.

   Register ini SENGAJA belum menggerakkan gaji. Rekap bulanan
   (hr/attendance-recaps) tetap menjadi masukan payroll; salah ketik di sini
   bisa diperbaiki kapan saja tanpa menyentuh uang yang sudah dibayar. */

import { api, session } from '../api.js';
import { el, clear, button, badge, toast, toastError, errorState, emptyState, skeletonTable, withBusy, field } from '../ui.js';
import * as fmt from '../format.js';
import { loadSource, peek } from '../lookup.js';
import { openPrintable } from '../print.js';

const STATUSES = [
  { value: 'hadir', label: 'Hadir', tone: 'green' },
  { value: 'setengah_hari', label: '½ Hari', tone: 'amber' },
  { value: 'absen', label: 'Absen', tone: 'red' },
];

const state = { date: fmt.today(), projectId: '' };

/* Satu halaman baris absensi untuk tanggal yang sedang tampil — SELURUH proyek,
   lihat savedToday di bawah. Ukurannya sama dengan halaman lookup.js, dan
   sama seperti di sana yang penting bukan angkanya melainkan bahwa layar ini
   TAHU kapan angkanya kurang: meta.total dibaca dan dipakai (savedTotal). */
const SAVED_PAGE_SIZE = 500;

/* Jawaban daftar bisa terbungkus paginator atau polos; baca dua-duanya. */
function rows(payload) {
  if (Array.isArray(payload)) return payload;
  return (payload && payload.data) || [];
}

export async function renderAbsensi(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Absensi Harian' }),
      el('.desc', { text: 'Lembar absen lapangan: satu proyek, satu tanggal, banyak karyawan. Tersimpan sebagai register — rekap bulanan payroll tetap dokumen terpisah.' }),
    ]),
  ]));

  const controls = el('.card');
  const body = el('div');
  host.append(controls, body);

  const canWrite = session.can('hr.create');

  const dateInput = el('input', { type: 'date', value: state.date, max: fmt.today() });
  dateInput.addEventListener('change', () => {
    /* Kotak tanggal yang DIKOSONGKAN (tombol bersihkan bawaan browser, atau
       hapus manual) tetap memancarkan change dengan value ''. Mengabaikannya
       diam-diam membuat kontrolnya berbohong: lembar di layar dan jangkar
       cetak masih milik state.date, sementara kotaknya kosong — lalu operator
       menekan Cetak dan mendapat tanggal yang tidak tertulis di mana pun di
       layar. Nilainya dikembalikan supaya kotak selalu menyebut tanggal
       lembar yang sedang tampil. */
    if (!dateInput.value) {
      dateInput.value = state.date;
      return;
    }
    state.date = dateInput.value;
    load();
  });

  const projectSelect = el('select');

  /* Daftar hadir dalam format formulir rumah (Form F/DH, lanskap) — lembar
     dengan kolom tanda tangan basah, yang dicetak untuk ditandatangani di
     lokasi lalu diarsipkan.

     Sheet-nya menjangkarkan diri pada SATU baris absensi milik (tanggal,
     proyek) yang dipilih, jadi tombolnya hidup hanya setelah pasangan itu
     punya isi. Mencetak lembar kosong dari layar yang belum menyimpan apa pun
     akan menghasilkan daftar hadir tanpa satu nama pun — kertas yang
     menyatakan tidak ada orang di lokasi, bukan kertas yang belum diisi. */
  const printAnchor = { id: null };

  /* Lembar sedang diambil. withBusy() memegang tombol selama itu — dan
     finally-nya menyalakan kembali TANPA SYARAT, karena ia tidak tahu apa-apa
     tentang jangkar cetak.

     Tanpa bendera ini, mengganti Proyek SELAGI lembar terbang membuat
     refreshPrintAnchor() mematikan tombol, lalu finally withBusy
     menghidupkannya lagi: tombol hidup dengan printAnchor.id === null, di
     bawah title yang berbunyi "Belum ada absensi tersimpan…" — satu klik lagi
     dan permintaannya berbunyi .../daftar-hadir/null.

     Ditutup dari dua sisi: refreshPrintAnchor() menghormati bendera selama
     penerbangan, dan onClick memanggilnya sekali lagi SETELAH withBusy
     melepaskan tombolnya, supaya keputusan terakhir soal hidup/mati tombol
     selalu milik jangkar, bukan milik withBusy. */
  let printing = false;

  const printButton = button('Cetak Daftar Hadir', {
    iconName: 'print',
    disabled: true,
    title: 'Simpan absensi hari ini terlebih dahulu',
    onClick: async (event) => {
      const trigger = event.currentTarget;
      /* Jangkar dibaca SEKARANG, bukan di dalam template setelah await: yang
         tercetak adalah lembar yang tombolnya sebut saat diklik, bukan lembar
         proyek yang dipilih sesudahnya. */
      const anchorId = printAnchor.id;
      if (!anchorId) return;

      printing = true;
      try {
        await openPrintable(`core/print/forms/daftar-hadir/${anchorId}`, trigger);
      } finally {
        printing = false;
        refreshPrintAnchor();
      }
    },
  });

  /* Baris absensi tersimpan untuk TANGGAL yang sedang tampil — seluruh proyek,
     karena kunci upsert-nya (karyawan, tanggal) dan lembar di layar memang
     satu tanggal. Disimpan di lingkup layar, bukan di dalam load(), supaya
     ganti proyek bisa menghitung ulang jangkar cetak tanpa memuat ulang
     lembarnya — memuat ulang akan membuang centang yang belum disimpan. */
  let savedToday = [];

  /* meta.total dari jawaban terakhir: berapa baris absensi SEBENARNYA ada untuk
     tanggal itu, sebagai lawan dari berapa yang termuat.

     Dulu jawabannya diambil lewat api.get(), yang membuang amplop {data, meta}
     dan menyisakan larik saja — jadi layar ini secara harfiah tidak punya cara
     mengetahui bahwa halamannya terpotong. Di atas SAVED_PAGE_SIZE baris untuk
     satu tanggal (perusahaan dengan >500 karyawan aktif yang ditandai hari itu)
     baris jangkar sebuah proyek bisa jatuh di halaman berikutnya, dan layar
     lalu MENYATAKAN "Belum ada absensi tersimpan" tentang proyek yang sudah
     punya absensi. Menolak mencetak tetap arah yang aman — jangkarnya memang
     tidak di tangan — tapi kalimat yang menyertainya adalah pernyataan yang
     layar ini tidak tahu benarnya, dan itulah yang diperbaiki di sini.

     Tidak terjangkau pada data yang ada sekarang — perusahaannya jauh di bawah
     satu halaman — dan sengaja diselesaikan dengan sebuah pengakuan, bukan
     dengan paging seperti lookup.js: satu-satunya
     alasan seluruh tanggal dimuat adalah supaya penukar Proyek bisa menghitung
     ulang jangkar TANPA memuat ulang — memuat ulang membuang centang yang
     belum disimpan. Menambah halaman kedua/ketiga akan mengembalikan tunggu
     itu ke jalur yang justru dibuat untuk menghindarinya. */
  let savedTotal = 0;

  const savedTruncated = () => savedTotal > savedToday.length;

  /* Jangkar cetak: SATU baris absensi milik pasangan (tanggal, proyek) yang
     sedang dipilih. Server membaca kedua kolom itu dari baris tersebut lalu
     mencetak seluruh register yang memuatnya — HrFormService::
     attendanceRegister menyaring whereDate(date) + where(project_id).

     Karena itu TIDAK ADA lagi cadangan "baris mana pun hari itu": baris milik
     proyek lain akan mencetak daftar hadir PROYEK LAIN di bawah judul proyek
     yang dipilih di layar — lembar bertanda tangan basah untuk pekerjaan yang
     salah, yang lalu diarsipkan sebagai catatan proyek ini. Kalau proyek
     terpilih belum punya absensi tersimpan hari itu, tombolnya mati dan
     alasannya tertulis di title-nya.

     Dipanggil dari load() DAN dari penukar proyek di kaki berkas ini: sebelum
     ini hanya load() yang menghitungnya, jadi mengganti Proyek meninggalkan
     jangkar proyek sebelumnya menempel di tombol. */
  function refreshPrintAnchor() {
    const anchor = savedToday.find(
      (row) => String(row.project_id || '') === String(state.projectId || ''),
    ) || null;

    const who = state.projectId ? 'proyek ini' : 'staf kantor';

    printAnchor.id = anchor ? anchor.id : null;
    // `printing` lebih dulu: selama lembar terbang tombolnya milik withBusy.
    printButton.disabled = printing || !printAnchor.id;

    if (printAnchor.id) {
      printButton.title = `Cetak daftar hadir ${fmt.date(state.date)} dalam format formulir perusahaan`;
    } else if (savedTruncated()) {
      /* Tidak ketemu di antara baris yang termuat — dan baris yang termuat
         bukan seluruh tanggal ini. "Belum ada absensi tersimpan" adalah
         pernyataan yang layar ini tidak tahu benarnya, jadi tidak diucapkan. */
      printButton.title = `Baris absensi ${fmt.date(state.date)} melebihi satu halaman `
        + `(${savedTotal} baris, ${savedToday.length} termuat) — layar ini tidak dapat memastikan `
        + `apakah ${who} sudah punya absensi tersimpan hari itu.`;
    } else {
      printButton.title = `Belum ada absensi tersimpan untuk ${who} pada ${fmt.date(state.date)}`;
    }
  }

  controls.appendChild(el('.card-body', {
    style: { display: 'flex', gap: '12px', flexWrap: 'wrap', alignItems: 'flex-end' },
  }, [
    field('Tanggal', dateInput),
    field('Proyek', projectSelect, { help: 'Kosongkan untuk staf kantor.' }),
    printButton,
  ]));

  /* Nomor urut muatan. Dua penekanan tanggal beruntun mengirim dua permintaan,
     dan yang berangkat lebih dulu boleh mendarat belakangan — jawaban tanggal
     LAMA lalu menimpa savedToday, menggambar ulang lembarnya, dan memasang
     jangkar cetak baris tanggal lama di tombol yang judulnya (state.date)
     sudah menyebut tanggal baru. Jawaban yang sudah didahului dibuang. */
  let loadToken = 0;

  async function load() {
    const token = ++loadToken;
    clear(body).appendChild(skeletonTable(8, 5));

    /* Jangkar lama dilepas SEBELUM permintaan berangkat, bukan sesudahnya:
       kalau tidak, menekan Cetak selama lembar tanggal baru masih dimuat —
       atau setelah permintaannya gagal dan panel error yang tampil — akan
       mencetak register tanggal SEBELUMNYA dari tombol yang tampak sedang
       membicarakan tanggal yang tertulis di kotak. */
    savedToday = [];
    savedTotal = 0;
    refreshPrintAnchor();

    try {
      // Karyawan dari sumber lookup (ter-cache), absensi hari terpilih dari
      // API — dua-duanya sebelum menggambar, supaya status tersimpan langsung
      // terlihat dan tidak tertimpa pilihan kosong.
      //
      // api.list, bukan api.get: yang pertama menyerahkan amplop {data, meta}
      // utuh, yang kedua membuang meta — dan meta.total adalah satu-satunya
      // cara layar ini tahu bahwa halamannya terpotong (lihat savedTotal).
      const [, saved] = await Promise.all([
        loadSource('employees'),
        api.list('hr/attendances', { date: state.date, per_page: SAVED_PAGE_SIZE }),
      ]);

      if (token !== loadToken) return;

      const employees = (peek('employees') || []).filter((row) => row.status === 'active');
      savedToday = rows(saved);
      // Endpoint yang menjawab larik polos tidak punya meta; 0 di sana berarti
      // "tidak ada yang mengaku terpotong", bukan "nol baris".
      savedTotal = Number((saved && saved.meta && saved.meta.total) || 0);
      const existing = new Map(savedToday.map((row) => [row.employee_id, row]));

      refreshPrintAnchor();
      paint(employees, existing);
    } catch (error) {
      // Muatan yang sudah didahului juga tidak boleh menaruh panel error di
      // atas lembar tanggal yang sudah berhasil tampil.
      if (token !== loadToken) return;
      clear(body).appendChild(errorState(error, load));
    }
  }

  function paint(employees, existing) {
    clear(body);

    if (!employees.length) {
      body.appendChild(emptyState('Tidak ada karyawan aktif.'));
      return;
    }

    if (savedTruncated()) {
      /* Kolom "Tersimpan" dirakit dari `existing`, yang dirakit dari baris yang
         TERMUAT. Karyawan yang barisnya jatuh di halaman berikutnya tampil
         sebagai "—", yaitu layar yang menyatakan "belum dicatat" tentang orang
         yang sudah dicatat — dan kerani lalu menandainya ulang. Kalimat ini
         ada supaya kolom itu tidak dibaca sebagai fakta saat ia tidak bisa
         menjadi fakta. */
      body.appendChild(el('.alert.warn',
        `Baris absensi ${fmt.date(state.date)} melebihi satu halaman: ${savedTotal} tersimpan, `
        + `${savedToday.length} termuat. Kolom "Tersimpan" dan tombol Cetak Daftar Hadir hanya `
        + 'melihat yang termuat — "—" di kolom itu berarti "tidak termuat", belum tentu "belum dicatat". '
        + 'Menyimpan lembar ini tetap aman: hanya baris yang Anda tandai yang terkirim.'));
    }

    // Pilihan layar dimulai dari yang sudah tersimpan; baris tanpa pilihan
    // tidak ikut terkirim — "belum dicatat" bukan "absen".
    const selection = new Map();
    existing.forEach((row, employeeId) => selection.set(employeeId, row.status));

    const counter = el('span.muted', { text: '' });

    function refreshCounter() {
      counter.textContent = `${selection.size} dari ${employees.length} tercatat`;
    }

    const table = el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Karyawan' }),
        el('th', { text: 'Status' }),
        el('th', { text: 'Tersimpan' }),
      ])),
      el('tbody', employees.map((employee) => {
        const savedRow = existing.get(employee.id);
        const savedCell = el('td');

        if (savedRow) {
          const meta = STATUSES.find((status) => status.value === savedRow.status);
          savedCell.appendChild(badge(meta ? meta.label : savedRow.status, meta ? meta.tone : ''));
        } else {
          savedCell.appendChild(el('span.muted', { text: '—' }));
        }

        const buttons = STATUSES.map((status) => {
          const btn = button(status.label, {
            size: 'sm',
            disabled: !canWrite,
            onClick: () => {
              // Klik ulang status terpilih = batal memilih; baris kembali ke
              // "belum dicatat" dan tidak dikirim.
              if (selection.get(employee.id) === status.value) selection.delete(employee.id);
              else selection.set(employee.id, status.value);
              paintButtons();
              refreshCounter();
            },
          });
          btn.dataset.status = status.value;
          return btn;
        });

        function paintButtons() {
          buttons.forEach((btn) => {
            btn.classList.toggle('active', selection.get(employee.id) === btn.dataset.status);
          });
        }

        paintButtons();

        return el('tr', [
          el('td', [
            el('span.cell-main', { text: employee.name }),
            el('span.cell-sub.mono', { text: `${employee.code} · ${employee.position || ''}` }),
          ]),
          el('td', el('.btn-group', { style: { display: 'flex', gap: '6px' } }, buttons)),
          savedCell,
        ]);
      })),
    ]);

    const save = button('Simpan Lembar Absen', {
      variant: 'primary',
      iconName: 'check',
      disabled: !canWrite,
      onClick: () => withBusy(save, async () => {
        if (!selection.size) {
          toastError(new Error('Belum ada status yang dipilih.'));
          return;
        }

        try {
          // api.post membuka amplop {data}, jadi yang kembali adalah hitungan
          // {created, updated} dari AttendanceController::bulk.
          const result = await api.post('hr/attendances/bulk', {
            date: state.date,
            project_id: state.projectId ? Number(state.projectId) : null,
            entries: [...selection.entries()].map(([employeeId, status]) => ({
              employee_id: employeeId,
              status,
            })),
          });

          toast(`Absensi tersimpan: ${result.created} baru, ${result.updated} diperbarui.`);
          load();
        } catch (error) {
          toastError(error);
        }
      }),
    });

    refreshCounter();

    body.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: `Lembar ${fmt.date(state.date)}` }),
        el('.spacer'),
        counter,
      ]),
      el('.table-wrap', table),
      el('.card-body', canWrite
        ? save
        : el('p.muted', { text: 'Anda tidak memiliki izin hr.create — layar ini hanya membaca.', style: { margin: 0 } })),
    ]));
  }

  // Proyek dimuat sekali; gagalnya tidak mematikan layar — absensi kantor sah
  // tanpa proyek, jadi select kosong tetap bisa dipakai.
  try {
    await loadSource('projects');
    const projects = peek('projects') || [];
    projectSelect.append(
      el('option', { value: '', text: '— Tanpa proyek (kantor) —' }),
      ...projects.map((project) => el('option', { value: String(project.id), text: `${project.code} — ${project.name}` })),
    );
    projectSelect.value = state.projectId;
    /* Dibaca balik: `state` hidup lintas navigasi (konstanta modul), jadi
       proyek yang sejak kunjungan terakhir dihapus/di-soft-delete tidak lagi
       ada di daftar option — menetapkan nilainya diam-diam menghasilkan ''.
       Tanpa baris ini kotaknya bertuliskan "Tanpa proyek (kantor)" sementara
       Simpan tetap mengirim id proyek lama. */
    state.projectId = projectSelect.value;
    projectSelect.addEventListener('change', () => {
      state.projectId = projectSelect.value;
      /* Jangkar cetak ikut pindah. Lembar di layar sengaja TIDAK dimuat ulang:
         daftarnya sama untuk tanggal itu (kunci upsert (karyawan, tanggal)),
         dan memuat ulang akan membuang centang yang belum disimpan — persis
         saat operator baru sadar proyeknya salah. */
      refreshPrintAnchor();
    });
  } catch {
    /* Sumber proyek gagal dimuat: satu-satunya pilihan tinggal kantor, jadi
       state ikut dikembalikan ke sana. Tanpa ini sisa state.projectId dari
       kunjungan sebelumnya tetap terkirim saat Simpan sementara kotaknya
       bertuliskan "Tanpa proyek". */
    state.projectId = '';
    projectSelect.append(el('option', { value: '', text: '— Tanpa proyek —' }));
  }

  await load();
}
