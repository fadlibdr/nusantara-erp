/* Pindai Barcode Item (F-6) — dan empat keadaan yang berbeda satu sama lain.
 *
 * ==========================================================================
 * BarcodeDetector TIDAK ADA DI iOS SAFARI, dan itu bukan detail: separuh
 * ponsel lapangan adalah iPhone. Layar yang memanggilnya begitu saja akan
 * melempar ReferenceError di dalam ponsel yang justru paling sering dipakai,
 * dan yang terlihat pemakainya hanyalah tombol yang tidak melakukan apa-apa.
 *
 * Maka JALUR MANUAL SELALU ADA — bukan sebagai jalan pintas untuk keadaan
 * darurat, melainkan sebagai isian pertama di layar, aktif, terfokus. Kamera
 * adalah tambahan di atasnya, bukan syaratnya.
 *
 * DAN KEEMPAT KEADAAN INI PUNYA EMPAT KALIMAT BERBEDA, karena keempatnya
 * menuntut tindakan yang berbeda dari orang yang membacanya:
 *
 *   1. peramban ini tidak punya BarcodeDetector  → tidak ada yang bisa
 *      diperbaiki; ketik kodenya (atau pakai Chrome Android);
 *   2. halaman ini bukan konteks aman (http://)  → kamera memang dimatikan
 *      peramban; buka lewat https;
 *   3. izin kamera DITOLAK                       → ada yang bisa diperbaiki,
 *      di setelan situs peramban;
 *   4. tidak ada kamera sama sekali              → perangkat ini memang tidak
 *      punya; ketik kodenya.
 *
 * …dan keadaan kelima yang bukan galat: kamera menyala dan belum menemukan
 * apa pun. Itu BUKAN kegagalan, dan tidak ditulis sebagai kegagalan — ia
 * "belum ketemu", dengan saran jarak dan cahaya, sementara kameranya jalan
 * terus.
 *
 * Sebuah layar yang menuliskan satu kalimat yang sama untuk keempatnya akan
 * mengirim orang gudang mencari setelan izin di ponsel yang memang tidak
 * punya kamera.
 * ==========================================================================
 *
 * BARCODE GANDA (perangkap E): server tidak pernah memilihkan, dan layar ini
 * tidak pernah memilihkan. Dua item dengan barcode yang sama tampil sebagai
 * DUA kartu dengan peringatan di atasnya, dan orangnya yang membuka salah
 * satunya.
 */

import { api } from '../api.js';
import { el, clear, button, badge, errorState, emptyState, toast } from '../ui.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';

/* Satu tempat yang tahu apakah peramban ini bisa memindai. Diperiksa lewat
   globalThis dan bukan dengan menyentuh nama BarcodeDetector langsung: sebuah
   referensi telanjang ke pengenal yang tidak ada adalah ReferenceError, dan
   ReferenceError di jalur render menjatuhkan seluruh layar — termasuk isian
   manualnya, yang justru satu-satunya jalan yang tersisa. */
function detectorSupported() {
  return typeof globalThis.BarcodeDetector === 'function';
}

/* Format yang dipindai. 1D untuk label gudang (Code 128 yang dicetak F/LBL,
   plus EAN/UPC kardus pemasok) dan QR karena separuh label pemasok sekarang
   membawanya. Daftar yang eksplisit, bukan bawaan: bawaan peramban memuat
   format yang tidak pernah dicetak siapa pun di sini dan memperlambat tiap
   bingkai. */
const FORMATS = ['code_128', 'code_39', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf', 'qr_code'];

export async function renderPindai(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Pindai Barcode Item' }),
      el('.desc', {
        text: 'Pindai atau ketik barcode/kode item untuk melihat kartu item dan saldo stoknya per gudang. '
          + 'Layar ini hanya membaca — ia tidak memindahkan stok apa pun.',
      }),
    ]),
  ]));

  // ------------------------------------------------------------- isian manual
  /* 46 px DAN 16 px, di layar mana pun — bukan hanya di ponsel.
   *
   * Ini SATU-SATUNYA jalan yang tersisa di iPhone (BarcodeDetector tidak ada
   * di Safari), jadi isian ini adalah pemindai bagi separuh lapangan. Ia harus
   * memenuhi standar target sentuh rumah ini (42–46 px; .btn.lg = 46 px) —
   * versi pertama layar ini memakai tinggi baku 34 px dan harness S33m
   * mengukurnya: sebuah kotak setinggi 34 px adalah kotak yang dicoba ditekan
   * dua kali oleh orang bersarung tangan.
   *
   * font-size 16 px karena Safari iOS MEMPERBESAR seluruh halaman saat fokus
   * masuk ke isian yang hurufnya lebih kecil dari itu, lalu tidak mengecil
   * lagi — layar yang sudah dipakai satu tangan menjadi layar yang harus
   * digeser dua arah. */
  const input = el('input', {
    type: 'text', inputmode: 'text', autocomplete: 'off', spellcheck: 'false',
    placeholder: 'Ketik atau tempel barcode / kode item…',
    'aria-label': 'Barcode atau kode item',
    style: { fontFamily: 'var(--mono, monospace)', height: '46px', fontSize: '16px' },
  });

  const submit = button('Cari', { variant: 'primary', iconName: 'search', type: 'submit' });
  // .btn menyetel height: 34px eksplisit dengan box-sizing: border-box, jadi
  // padding tidak menumbuhkannya sama sekali (catatan .btn.lg di app.css).
  Object.assign(submit.style, { height: '46px', padding: '0 18px' });

  const form = el('form', { style: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' } }, [
    el('div', { style: { flex: '1 1 220px', minWidth: '0' } }, input),
    submit,
  ]);

  const cameraBox = el('div');
  const result = el('div');

  host.append(
    el('.card', [
      el('.card-head', [el('h2', { text: 'Ketik kodenya' }), el('.spacer')]),
      el('.card-body', [
        form,
        el('.cell-sub', {
          style: { marginTop: '8px' },
          text: 'Jalur ini selalu tersedia, di ponsel mana pun, dengan atau tanpa kamera.',
        }),
      ]),
    ]),
    cameraBox,
    result,
  );

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    lookup(input.value.trim());
  });

  input.focus();

  // ----------------------------------------------------------------- pencarian
  let lookupToken = 0;

  async function lookup(code) {
    if (!code) {
      toast('Isi dulu barcode atau kode itemnya.', { tone: 'warn' });
      return;
    }

    const token = ++lookupToken;
    clear(result).appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '16px' } }))));

    let payload;
    try {
      payload = await api.get('inventory/items/scan', { code });
    } catch (error) {
      if (token !== lookupToken) return;
      clear(result).appendChild(errorState(error, () => lookup(code)));
      return;
    }

    if (token !== lookupToken) return;
    drawResult(payload);
  }

  function drawResult(payload) {
    clear(result);

    if (payload.status === 'none') {
      result.appendChild(emptyState(payload.message, { title: `Tidak ketemu: ${payload.query}`, kind: 'search' }));
      return;
    }

    /* PERINGATAN GANDA di atas daftarnya, bukan di dalam salah satu kartunya:
       yang salah bukan salah satu item melainkan hubungan antara keduanya. */
    if (payload.status === 'ambiguous') {
      result.appendChild(el('.card', [
        el('.card-head', [el('h2', { text: 'Barcode ini dipakai lebih dari satu item' }), el('.spacer')]),
        el('.card-body', el('.cell-sub', { text: payload.message })),
      ]));
    }

    payload.matches.forEach((item) => {
      result.appendChild(el('.card', [
        el('.card-head', [
          el('h2', { text: item.name }),
          el('.spacer'),
          item.is_active ? null : badge('Nonaktif', 'amber'),
          button('Buka kartu item', {
            size: 'sm', variant: 'ghost', iconName: 'chevronRight',
            onClick: () => navigate(`d/inventory/items/${item.id}`),
          }),
        ]),
        el('.card-body', [
          el('.cell-sub.mono', {
            text: `${item.code}${item.barcode ? ` · barcode ${item.barcode}` : ' · tanpa barcode pemasok'}`
              + `${item.category ? ` · ${item.category}` : ''}`,
          }),
          /* Kenapa item INI yang cocok. Satu kode bisa menjadi barcode sebuah
             item DAN kode item lain sekaligus, dan orang yang memilih di
             antara keduanya berhak tahu sebabnya. */
          el('.cell-sub', {
            text: item.matched_on === 'barcode'
              ? 'Cocok pada kolom Barcode item ini.'
              : 'Cocok pada KODE item ini, bukan pada kolom Barcode-nya.',
          }),
          item.balances.length
            ? el('.table-wrap', el('table.data', [
              el('thead', el('tr', [el('th', { text: 'Gudang' }), el('th.right', { text: 'Stok' })])),
              el('tbody', item.balances.map((balance) => el('tr', [
                el('td', el('span', [
                  el('span.cell-main', { text: balance.warehouse_name }),
                  el('span.cell-sub.mono', { text: balance.warehouse_code }),
                ])),
                el('td.right.num', { text: fmt.qty(balance.qty, item.unit) }),
              ]))),
            ]))
            /* "Belum pernah ada saldo di gudang mana pun" ≠ "stok 0". Menulis 0
               di sini adalah janji bahwa kita sudah menghitung. */
            : el('.cell-sub', { text: 'Belum ada baris saldo untuk item ini di gudang mana pun.' }),
        ]),
      ]));
    });
  }

  // -------------------------------------------------------------------- kamera
  let stream = null;
  let scanning = false;
  let video = null;

  function stopCamera() {
    scanning = false;
    if (stream) {
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    }
    if (video) {
      video.srcObject = null;
      video = null;
    }
  }

  /* LAYAR YANG DITINGGALKAN HARUS MEMATIKAN KAMERANYA, dan tidak ada kait
     "teardown" di router ini untuk memberitahunya.

     Yang ada adalah fakta DOM: berpindah rute memanggil clear() atas #view,
     yang mencabut elemen <video> ini dari dokumen. Jadi putaran pemindai
     memeriksa video.isConnected setiap 250 ms dan mematikan trek-nya sendiri
     begitu ia tercabut. Tanpa itu lampu kamera tetap menyala sesudah orangnya
     pindah layar — dan di Android kamera yang tidak dilepas menahan aplikasi
     lain dari memakainya sampai tab-nya ditutup. */

  function cameraNotice(title, message) {
    return el('.card', [
      el('.card-head', [el('h2', { text: title }), el('.spacer')]),
      el('.card-body', el('.cell-sub', { text: message })),
    ]);
  }

  function drawCamera() {
    clear(cameraBox);

    // KEADAAN 1 — peramban ini tidak punya BarcodeDetector (iOS Safari).
    if (!detectorSupported()) {
      cameraBox.appendChild(cameraNotice(
        'Kamera tidak bisa dipakai di peramban ini',
        'Peramban ini tidak menyediakan BarcodeDetector — pemindai bawaan peramban. Safari di iPhone dan '
        + 'iPad termasuk yang tidak punya, dan tidak ada setelan yang bisa menyalakannya. Ketik kodenya '
        + 'di kotak di atas, atau buka halaman ini dengan Chrome di Android.',
      ));
      return;
    }

    // KEADAAN 2 — halaman ini bukan konteks aman; peramban mematikan kamera.
    if (window.isSecureContext === false) {
      cameraBox.appendChild(cameraNotice(
        'Kamera dimatikan karena halaman ini bukan HTTPS',
        'Peramban hanya memberikan kamera kepada halaman yang dibuka lewat https:// (atau localhost). '
        + 'Halaman ini bukan salah satunya, jadi kameranya tidak akan pernah diminta. Buka aplikasi lewat '
        + 'alamat https-nya, atau ketik kodenya di kotak di atas.',
      ));
      return;
    }

    const status = el('.cell-sub', { text: 'Kamera belum dinyalakan.' });
    const start = button('Nyalakan kamera', { variant: 'primary', onClick: () => startCamera(status, holder, start) });
    const holder = el('div', { style: { marginTop: '10px' } });

    cameraBox.appendChild(el('.card', [
      el('.card-head', [el('h2', { text: 'Pindai dengan kamera' }), el('.spacer'), start]),
      el('.card-body', [status, holder]),
    ]));
  }

  async function startCamera(status, holder, trigger) {
    trigger.disabled = true;
    status.textContent = 'Meminta izin kamera…';

    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment' },
        audio: false,
      });
    } catch (error) {
      trigger.disabled = false;
      const name = error && error.name;

      // KEADAAN 3 dan 4, dan mereka BERBEDA. Izin yang ditolak bisa dicabut
      // kembali oleh orangnya; kamera yang tidak ada tidak bisa.
      if (name === 'NotAllowedError' || name === 'SecurityError') {
        status.textContent = 'Izin kamera ditolak. Buka setelan situs di peramban (ikon gembok di bilah '
          + 'alamat), izinkan Kamera untuk alamat ini, lalu tekan "Nyalakan kamera" lagi. Sementara itu, '
          + 'kotak isian di atas tetap bekerja.';
        return;
      }

      if (name === 'NotFoundError' || name === 'OverconstrainedError' || name === 'DevicesNotFoundError') {
        status.textContent = 'Perangkat ini tidak punya kamera yang bisa dipakai peramban. Bukan soal izin — '
          + 'tidak ada yang bisa diizinkan. Ketik kodenya di kotak di atas.';
        return;
      }

      status.textContent = `Kamera tidak bisa dibuka (${name || 'galat tidak dikenal'}). `
        + 'Ketik kodenya di kotak di atas.';
      return;
    }

    trigger.textContent = 'Matikan kamera';
    trigger.disabled = false;
    trigger.onclick = () => {
      stopCamera();
      drawCamera();
    };

    video = el('video', { autoplay: true, muted: true, playsinline: true, style: { width: '100%', maxWidth: '420px', borderRadius: 'var(--radius)', background: '#000' } });
    video.srcObject = stream;
    clear(holder).appendChild(video);
    /* TIDAK di-await. play() hanya menyelesaikan janjinya ketika trek video
       benar-benar mengirim bingkai pertamanya, dan sebuah kamera yang menyala
       tanpa mengirim apa pun akan menggantung baris ini SELAMANYA: pemindainya
       tidak pernah mulai dan kalimat di layar berhenti di "Meminta izin
       kamera…", yang persis salah — izinnya sudah diberikan. Terukur di
       harness S33k (trek kanvas tanpa bingkai). Putaran pemindai di bawah
       memang tahan terhadap bingkai yang belum ada. */
    video.play().catch(() => {});

    const detector = new globalThis.BarcodeDetector({ formats: FORMATS });
    scanning = true;

    // KEADAAN 5 — jalan, belum ketemu. Bukan galat, dan tidak ditulis sebagai
    // galat: kameranya terus mencari sementara kalimatnya berubah menjadi
    // saran yang bisa ditindaklanjuti.
    status.textContent = 'Kamera menyala. Arahkan ke barcode.';
    const nudge = setTimeout(() => {
      if (scanning) {
        status.textContent = 'Belum menemukan barcode. Dekatkan 10–20 cm, pastikan seluruh batang masuk '
          + 'layar termasuk ruang kosong di kiri-kanannya, dan cari cahaya yang lebih rata. '
          + 'Kotak isian di atas tetap bisa dipakai.';
      }
    }, 6000);

    let last = '';

    const tick = async () => {
      if (!scanning || !video) return;

      // Rute sudah berpindah: elemen ini tidak lagi di dokumen.
      if (!video.isConnected) {
        clearTimeout(nudge);
        stopCamera();

        return;
      }

      try {
        const found = await detector.detect(video);
        if (found.length) {
          const value = String(found[0].rawValue || '').trim();
          // Satu barcode yang tetap di depan kamera menghasilkan puluhan
          // pembacaan per detik; tanpa penjaga ini layar akan memuat ulang
          // hasil yang sama terus-menerus.
          if (value && value !== last) {
            last = value;
            clearTimeout(nudge);
            status.textContent = `Terbaca: ${value}`;
            input.value = value;
            await lookup(value);
          }
        }
      } catch {
        // Satu bingkai yang gagal dibaca bukan alasan mematikan pemindai.
      }

      if (scanning) setTimeout(tick, 250);
    };

    tick();
  }

  drawCamera();
}
