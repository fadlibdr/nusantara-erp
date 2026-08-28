<?php

/**
 * Generates the six engineering-file fixtures in this directory and verifies
 * what PHP's finfo reports for each — the numbers that
 * Modules\Core\Services\AttachmentService::ALLOWED pins for dwg/dxf/mpp/xml/
 * pptx/ppt.
 *
 *     php tests/fixtures/attachments/generate.php
 *
 * Exit code 0 means every committed fixture still sniffs as the MIME the
 * service allows ON THIS PHP BUILD. A non-zero exit means the PHP/libmagic
 * build changed its answer: re-read the table it prints, and re-pin ALLOWED to
 * what finfo now actually says — never to what a MIME registry says it should.
 *
 * Why these MIMEs and not the "official" ones: PHP's fileinfo uses a BUNDLED,
 * patched libmagic (5.43 on PHP 8.3.6) with its own compiled database. It
 * disagrees with the /usr/bin/file CLI — the CLI answers image/vnd.dxf for an
 * ASCII DXF, PHP's finfo answers text/plain; the CLI's libmagic knows
 * vnd.ms-project, PHP's bundled one does not contain that string at all. The
 * service calls finfo, so ALLOWED must pin finfo's answers, observed, per
 * build, from real bytes. Every fixture here is a minimal GENUINE file of its
 * format — a real zip with [Content_Types].xml, a real OLE compound file with
 * a parseable directory and summary stream — not a magic-number prefix glued
 * onto junk.
 *
 * Observed on PHP 8.3.6 / bundled libmagic 543 (Ubuntu 24.04):
 *
 *   sample.dwg   image/vnd.dwg
 *   sample.dxf   text/plain
 *   sample.mpp   application/vnd.ms-office
 *   sample.xml   text/xml
 *   sample.pptx  application/vnd.openxmlformats-officedocument.presentationml.presentation
 *   sample.ppt   application/vnd.ms-powerpoint
 *   (bare OLE container, no summary stream — genuine but sparse ppt/mpp)
 *                application/x-ole-storage
 */
$dir = __DIR__;

// ---------------------------------------------------------------------- DWG
// The AC10xx signature AutoCAD has used since R13; AC1027 = AutoCAD 2013-2014.
// finfo matches on the signature alone.
$dwg = 'AC1027'.str_repeat("\x00", 6)."\x00\x01\x00\x00\x00\x00".str_repeat(' ', 100);
file_put_contents("$dir/sample.dwg", $dwg);

// ---------------------------------------------------------------------- DXF
// A genuine minimal ASCII DXF: group-code/value pairs, HEADER section with
// $ACADVER, ENDSEC, EOF. The /usr/bin/file CLI calls this image/vnd.dxf; PHP's
// bundled libmagic does not apply that regex rule and answers text/plain.
$dxf = implode("\r\n", [
    '  0', 'SECTION',
    '  2', 'HEADER',
    '  9', '$ACADVER',
    '  1', 'AC1027',
    '  0', 'ENDSEC',
    '  0', 'SECTION',
    '  2', 'ENTITIES',
    '  0', 'ENDSEC',
    '  0', 'EOF',
])."\r\n";
file_put_contents("$dir/sample.dxf", $dxf);

// ---------------------------------------------------------------------- XML
// A Microsoft Project XML export skeleton — the schedule interchange format
// the roadmap names alongside .mpp.
$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n"
    ."<Project xmlns=\"http://schemas.microsoft.com/project\">\n"
    ."  <Name>Jadwal induk fixture</Name>\n"
    ."  <Title>PRJ-2026-001</Title>\n"
    ."</Project>\n";
file_put_contents("$dir/sample.xml", $xml);

// --------------------------------------------------------------------- PPTX
// A real zip whose parts make it an OOXML presentation package. finfo walks
// the archive for [Content_Types].xml and the ppt/ part (probed: it still
// answers the full MIME with the content types part 60 entries deep).
$zip = new ZipArchive;
$zip->open("$dir/sample.pptx", ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    .'<Default Extension="xml" ContentType="application/xml"/>'
    .'<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
    .'</Types>');
$zip->addFromString('_rels/.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
    .'</Relationships>');
$zip->addFromString('ppt/presentation.xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');
$zip->close();

// ----------------------------------------------------------------- PPT, MPP
// Both are OLE Compound File Binary containers. finfo's CDF parser walks the
// real directory: with a \x05SummaryInformation property set it answers from
// the stream names / application name ("PowerPoint Document" stream →
// vnd.ms-powerpoint; no known stream name → the generic vnd.ms-office, which
// is all this build can ever say for MS Project — its magic database has no
// vnd.ms-project mapping). Without a summary stream the same genuine
// container sniffs as application/x-ole-storage, which is why the service
// accepts that as well, for these two extensions only.
file_put_contents("$dir/sample.ppt", cfb_build([
    ['name' => 'PowerPoint Document', 'data' => str_repeat("\x00", 16)],
    ['name' => "\x05SummaryInformation", 'data' => summary_info('Microsoft PowerPoint')],
]));
file_put_contents("$dir/sample.mpp", cfb_build([
    ['name' => "\x05MSProject", 'data' => str_repeat("\x00", 16)],
    ['name' => "\x05SummaryInformation", 'data' => summary_info('Microsoft Project')],
]));

// ------------------------------------------------------------------- verify

$expected = [
    'sample.dwg' => 'image/vnd.dwg',
    'sample.dxf' => 'text/plain',
    'sample.mpp' => 'application/vnd.ms-office',
    'sample.xml' => 'text/xml',
    'sample.pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'sample.ppt' => 'application/vnd.ms-powerpoint',
];

$finfo = new finfo(FILEINFO_MIME_TYPE);
$fail = false;

foreach ($expected as $file => $want) {
    $got = (string) $finfo->buffer((string) file_get_contents("$dir/$file"));
    $ok = $got === $want;
    $fail = $fail || ! $ok;
    printf("%-13s %6d bytes  %-72s %s\n", $file, filesize("$dir/$file"), $got, $ok ? 'ok' : "EXPECTED $want");
}

// The sparse-container observation backing 'application/x-ole-storage':
$bare = cfb_build([['name' => 'PowerPoint Document', 'data' => str_repeat("\x00", 16)]]);
$got = (string) $finfo->buffer($bare);
$ok = $got === 'application/x-ole-storage';
$fail = $fail || ! $ok;
printf("%-13s %6d bytes  %-72s %s\n", '(bare OLE)', strlen($bare), $got, $ok ? 'ok' : 'EXPECTED application/x-ole-storage');

exit($fail ? 1 : 0);

// ------------------------------------------------------------------ builders

/**
 * Minimal genuine OLE Compound File Binary (v3, 512-byte sectors).
 *
 * Sector 0 FAT, sector 1 directory, and — when a stream carries data — sector
 * 2 mini FAT and sector 3 the mini stream (every stream here is far below the
 * 4096-byte cutoff, so data lives in 64-byte mini sectors, as the format
 * requires). finfo's CDF parser reads all of it for real; a malformed chain
 * falls back to x-ole-storage, so this has to be an honest container.
 *
 * @param  array<int, array{name: string, data: string}>  $streams
 */
function cfb_build(array $streams): string
{
    $ENDOFCHAIN = 0xFFFFFFFE;
    $FATSECT = 0xFFFFFFFD;
    $NOSTREAM = 0xFFFFFFFF;

    $sect = fn (string $s) => str_pad($s, 512, "\x00");

    // Mini stream: stream data at 64-byte mini sectors, with its mini FAT.
    $mini = '';
    $miniFat = [];
    $placed = [];
    foreach ($streams as $i => $s) {
        if ($s['data'] === '') {
            $placed[$i] = [$ENDOFCHAIN, 0];

            continue;
        }
        $start = intdiv(strlen($mini), 64);
        $count = (int) ceil(strlen($s['data']) / 64);
        for ($m = 0; $m < $count; $m++) {
            $miniFat[$start + $m] = $m === $count - 1 ? $ENDOFCHAIN : $start + $m + 1;
        }
        $mini .= str_pad($s['data'], $count * 64, "\x00");
        $placed[$i] = [$start, strlen($s['data'])];
    }
    $miniUsed = strlen($mini);

    $entry = function (string $name, int $type, int $right, int $child, int $start, int $size) use ($NOSTREAM): string {
        $utf16 = mb_convert_encoding($name, 'UTF-16LE', 'UTF-8')."\x00\x00";
        $e = str_pad($utf16, 64, "\x00");
        $e .= pack('v', strlen($utf16));
        $e .= chr($type)."\x01";                       // entry type; black node
        $e .= pack('V', $NOSTREAM).pack('V', $right).pack('V', $child);
        $e .= str_repeat("\x00", 16);                  // CLSID
        $e .= pack('V', 0);                            // state bits
        $e .= str_repeat("\x00", 16);                  // timestamps
        $e .= pack('V', $start);
        $e .= pack('V', $size).pack('V', 0);           // 64-bit size

        return $e;                                     // 128 bytes
    };

    // Root entry (type 5) owns the mini stream; its child chain lists the
    // streams (type 2) via right-sibling links.
    $dir = $entry('Root Entry', 5, $NOSTREAM, $streams === [] ? $NOSTREAM : 1,
        $miniUsed > 0 ? 3 : $ENDOFCHAIN, $miniUsed);
    foreach ($streams as $i => $s) {
        [$start, $size] = $placed[$i];
        $dir .= $entry($s['name'], 2, isset($streams[$i + 1]) ? $i + 2 : $NOSTREAM, $NOSTREAM, $start, $size);
    }
    $dir = str_pad($dir, 512, "\x00");

    // FAT for sectors 0..3; unused entries FREESECT (0xFFFFFFFF).
    $fat = pack('V', $FATSECT).pack('V', $ENDOFCHAIN);
    if ($miniUsed > 0) {
        $fat .= pack('V', $ENDOFCHAIN).pack('V', $ENDOFCHAIN);
    }
    $fat = str_pad($fat, 512, "\xFF");

    $mf = '';
    for ($m = 0; $m < intdiv($miniUsed, 64); $m++) {
        $mf .= pack('V', $miniFat[$m]);
    }
    $mf = str_pad($mf, 512, "\xFF");

    $h = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";           // CFB signature
    $h .= str_repeat("\x00", 16);                      // CLSID
    $h .= pack('v', 0x003E).pack('v', 0x0003);         // minor, major (v3)
    $h .= "\xFE\xFF";                                  // little-endian marker
    $h .= pack('v', 9).pack('v', 6);                   // 512-byte / 64-byte shifts
    $h .= str_repeat("\x00", 6);
    $h .= pack('V', 0);                                // dir sector count (v3: 0)
    $h .= pack('V', 1);                                // FAT sector count
    $h .= pack('V', 1);                                // first directory sector
    $h .= pack('V', 0);                                // transaction signature
    $h .= pack('V', 0x00001000);                       // mini stream cutoff 4096
    $h .= pack('V', $miniUsed > 0 ? 2 : $ENDOFCHAIN);  // first mini FAT sector
    $h .= pack('V', $miniUsed > 0 ? 1 : 0);            // mini FAT sector count
    $h .= pack('V', $ENDOFCHAIN);                      // first DIFAT sector
    $h .= pack('V', 0);                                // DIFAT sector count
    $h .= pack('V', 0);                                // DIFAT[0] → FAT at sector 0
    $h .= str_repeat("\xFF", 108 * 4);                 // DIFAT[1..108] free

    $out = $sect($h).$sect($fat).$sect($dir);
    if ($miniUsed > 0) {
        $out .= $sect($mf).$sect($mini);
    }

    return $out;
}

/**
 * A \x05SummaryInformation property set (MS-OLEPS) carrying one property:
 * PIDSI_APPNAME (0x12) as VT_LPSTR. The application name is what finfo's CDF
 * reader uses to name an OLE file's producing application.
 */
function summary_info(string $appName): string
{
    $name = $appName."\x00";
    $padded = str_pad($name, (int) (ceil(strlen($name) / 4) * 4), "\x00");

    // Section: size(4) count(4) + one (id, offset) pair(8), value at +16.
    $value = pack('V', 0x1E).pack('V', strlen($name)).$padded;
    $section = pack('V', 16 + strlen($value)).pack('V', 1)
        .pack('V', 0x12).pack('V', 16)
        .$value;

    // FMTID F29F85E0-4FF9-1068-AB91-08002B27B3D9, serialized little-endian.
    $fmtid = pack('V', 0xF29F85E0).pack('v', 0x4FF9).pack('v', 0x1068)
        ."\xAB\x91\x08\x00\x2B\x27\xB3\xD9";

    return pack('v', 0xFFFE).pack('v', 0)              // byte order, version
        .pack('V', 0x00020006)                         // OS version stamp
        .str_repeat("\x00", 16)                        // CLSID
        .pack('V', 1)                                  // one section
        .$fmtid.pack('V', 48)                          // its FMTID + offset
        .$section;
}
