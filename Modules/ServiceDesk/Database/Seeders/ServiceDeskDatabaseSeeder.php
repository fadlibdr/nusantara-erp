<?php

namespace Modules\ServiceDesk\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Core\Models\NumberSequence;
use Modules\Crm\Models\Contract as CrmContract;
use Modules\Crm\Models\Customer;
use Modules\HrPayroll\Models\Employee;
use Modules\Inventory\Models\Item;
use Modules\ServiceDesk\Models\FieldReport;
use Modules\ServiceDesk\Models\PreventiveSchedule;
use Modules\ServiceDesk\Models\ServiceContract;
use Modules\ServiceDesk\Models\Ticket;

class ServiceDeskDatabaseSeeder extends Seeder
{
    private const CONTRACT_CODE = 'SVC/2026/III/0001';

    public function run(): void
    {
        $contract = $this->seedContract();

        if (! $contract) {
            return; // CRM customer canon not seeded yet — nothing to hang tickets on
        }

        $this->seedPreventiveSchedules($contract);
        $this->seedTickets($contract);
        $this->seedFieldReport();
        $this->syncNumberSequences();
    }

    private function seedContract(): ?ServiceContract
    {
        $customer = Customer::query()->where('code', 'CUST-0003')->first();

        if (! $customer) {
            return null;
        }

        $crmContractId = CrmContract::query()->where('code', 'CTR/2026/III/0003')->value('id');

        $contract = ServiceContract::withTrashed()->updateOrCreate(
            ['code' => self::CONTRACT_CODE],
            [
                'customer_id' => $customer->id,
                'contract_id' => $crmContractId,
                'name' => 'Pemeliharaan CCTV & Akses Kontrol RS Medika Husada',
                'period_start' => '2026-04-01',
                'period_end' => '2027-03-31',
                'contract_value' => 480000000, // Rp 480 jt / tahun (DPP)
                'billing_cycle' => 'quarterly',
                'sla_response_hours' => 4,
                'sla_resolution_hours' => 24,
                'coverage' => 'Pemeliharaan preventif bulanan dan korektif on-call untuk sistem CCTV '
                    .'(64 kamera, 4 NVR) serta akses kontrol & alarm (22 pintu) di 2 gedung. '
                    .'Termasuk jasa teknisi, transportasi, dan suku cadang minor < Rp 500.000; '
                    .'suku cadang utama ditagihkan terpisah setelah persetujuan tertulis. '
                    .'SLA respons 4 jam, penyelesaian 24 jam (prioritas kritis berlaku 24/7).',
                'status' => 'active',
            ],
        );

        $sites = [
            [
                'site_name' => 'Gedung Utama RS Medika Husada',
                'address' => 'Jl. Raya Serpong No. 88',
                'city' => 'Tangerang Selatan',
                'pic_name' => 'Darto Prasetyo',
                'pic_phone' => '0812-9034-771',
            ],
            [
                'site_name' => 'Gedung Poliklinik & Rawat Jalan',
                'address' => 'Jl. Raya Serpong No. 88 (kompleks RS, sayap timur)',
                'city' => 'Tangerang Selatan',
                'pic_name' => 'Ns. Ratna Sari',
                'pic_phone' => '0813-5566-102',
            ],
        ];

        foreach ($sites as $site) {
            $contract->sites()->updateOrCreate(
                ['site_name' => $site['site_name']],
                $site,
            );
        }

        return $contract;
    }

    private function seedPreventiveSchedules(ServiceContract $contract): void
    {
        $technicianId = Employee::query()->where('code', 'EMP-0007')->value('id'); // Joko Susilo, Teknisi ELV
        $mainSiteId = $contract->sites()->where('site_name', 'Gedung Utama RS Medika Husada')->value('id');
        $clinicSiteId = $contract->sites()->where('site_name', 'Gedung Poliklinik & Rawat Jalan')->value('id');

        $schedules = [
            [
                'name' => 'PM CCTV Bulanan',
                'site_id' => $mainSiteId,
                // The July 5 visit was already generated (TKT-202607-0003); rolled to August.
                'next_due_date' => '2026-08-05',
                'checklist' => [
                    'Cek kondisi fisik & fokus seluruh kamera',
                    'Cek rekaman & retensi NVR (min. 30 hari)',
                    'Cek kesehatan HDD NVR (SMART)',
                    'Bersihkan housing kamera outdoor',
                    'Uji playback & backup rekaman sampel',
                ],
            ],
            [
                'name' => 'PM Akses Kontrol & Alarm Bulanan',
                'site_id' => $clinicSiteId,
                'next_due_date' => '2026-08-12',
                'checklist' => [
                    'Uji baca kartu di seluruh reader',
                    'Cek baterai backup panel akses kontrol',
                    'Uji magnetic lock & push button emergency',
                    'Uji sirine alarm & notifikasi ke pos keamanan',
                    'Sinkronisasi log akses ke server',
                ],
            ],
        ];

        foreach ($schedules as $schedule) {
            PreventiveSchedule::withTrashed()->updateOrCreate(
                [
                    'service_contract_id' => $contract->id,
                    'name' => $schedule['name'],
                ],
                [
                    'site_id' => $schedule['site_id'],
                    'frequency' => 'monthly',
                    'next_due_date' => $schedule['next_due_date'],
                    'assigned_to' => $technicianId,
                    'checklist' => $schedule['checklist'],
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Due dates follow SlaService rules: business hours Mon-Fri 08:00-17:00 WIB
     * for low/medium/high, plain clock hours for critical (SLA: 4h / 24h).
     */
    private function seedTickets(ServiceContract $contract): void
    {
        $technicianId = Employee::query()->where('code', 'EMP-0007')->value('id');
        $userId = User::query()->orderBy('id')->value('id');
        $mainSiteId = $contract->sites()->where('site_name', 'Gedung Utama RS Medika Husada')->value('id');
        $clinicSiteId = $contract->sites()->where('site_name', 'Gedung Poliklinik & Rawat Jalan')->value('id');

        $tickets = [
            [
                // Old ticket, fully closed.
                'code' => 'TKT-202606-0001',
                'site_id' => $clinicSiteId,
                'title' => 'Kamera koridor lantai 2 poliklinik tidak menampilkan gambar',
                'description' => 'Monitor pos keamanan menampilkan "video loss" untuk kamera koridor lantai 2 sejak pagi.',
                'category' => 'incident',
                'priority' => 'medium',
                'status' => 'closed',
                'channel' => 'phone',
                'reported_by_name' => 'Ns. Ratna Sari',
                'reported_at' => '2026-06-03 10:30:00',
                'assigned_to' => $technicianId,
                'response_due_at' => '2026-06-03 14:30:00',   // +4 business hours
                'resolution_due_at' => '2026-06-05 16:30:00', // +24 business hours (Wed 6.5h + Thu 9h + Fri 8.5h)
                'first_response_at' => '2026-06-03 11:05:00',
                'resolved_at' => '2026-06-04 09:40:00',
                'closed_at' => '2026-06-05 14:00:00',
                'resolution_notes' => 'Konektor BNC kendor di patch panel; dikencangkan ulang dan jalur video diuji normal.',
                'activities' => [
                    ['type' => 'assignment', 'body' => 'Tiket ditugaskan kepada Joko Susilo.', 'at' => '2026-06-03 10:50:00'],
                    ['type' => 'comment', 'body' => 'Konfirmasi ke PIC poliklinik; kamera koridor lantai 2 video loss, dijadwalkan pengecekan siang ini.', 'at' => '2026-06-03 11:05:00'],
                    ['type' => 'work_log', 'body' => 'Pengecekan jalur video: konektor BNC kendor di patch panel ruang server. Dikencangkan dan diuji, gambar normal.', 'minutes' => 90, 'at' => '2026-06-04 09:40:00'],
                    ['type' => 'status_change', 'body' => 'Tiket diselesaikan (resolved).', 'at' => '2026-06-04 09:40:00'],
                    ['type' => 'status_change', 'body' => 'Tiket ditutup (closed) setelah konfirmasi pelanggan.', 'at' => '2026-06-05 14:00:00'],
                ],
            ],
            [
                // Critical incident, resolved within SLA (clock hours: due 13:15 / next day 09:15).
                'code' => 'TKT-202606-0002',
                'site_id' => $mainSiteId,
                'title' => 'NVR utama mati total — rekaman CCTV gedung utama berhenti',
                'description' => 'NVR utama di ruang server gedung utama mati, indikator power padam. Seluruh rekaman 32 kamera gedung utama berhenti.',
                'category' => 'incident',
                'priority' => 'critical',
                'status' => 'resolved',
                'channel' => 'wa',
                'reported_by_name' => 'Darto Prasetyo',
                'reported_at' => '2026-06-10 09:15:00',
                'assigned_to' => $technicianId,
                'response_due_at' => '2026-06-10 13:15:00',   // +4 clock hours (critical = 24/7)
                'resolution_due_at' => '2026-06-11 09:15:00', // +24 clock hours
                'first_response_at' => '2026-06-10 09:40:00',
                'resolved_at' => '2026-06-10 16:30:00',
                'closed_at' => null,
                'resolution_notes' => 'PSU NVR gagal dan 1 kamera dome lobi ikut mati. PSU diganti, kamera dome diganti unit baru (ITM-0004); rekaman 32 kamera normal kembali.',
                'activities' => [
                    ['type' => 'assignment', 'body' => 'Tiket ditugaskan kepada Joko Susilo (prioritas kritis).', 'at' => '2026-06-10 09:30:00'],
                    ['type' => 'comment', 'body' => 'Menghubungi PIC keamanan; NVR utama tidak menyala sama sekali. Teknisi berangkat ke lokasi membawa PSU cadangan.', 'at' => '2026-06-10 09:40:00'],
                    ['type' => 'work_log', 'body' => 'Diagnosa di lokasi: PSU NVR gagal (tegangan drop), 1 kamera dome lobi lantai 1 mati total. PSU diganti, NVR menyala kembali.', 'minutes' => 180, 'at' => '2026-06-10 13:45:00'],
                    ['type' => 'work_log', 'body' => 'Penggantian 1 unit CCTV Dome 4MP di lobi lantai 1, konfigurasi ulang channel, uji rekaman seluruh 32 kamera normal.', 'minutes' => 150, 'at' => '2026-06-10 16:30:00'],
                    ['type' => 'status_change', 'body' => 'Tiket diselesaikan (resolved) dalam SLA.', 'at' => '2026-06-10 16:30:00'],
                ],
            ],
            [
                // Preventive ticket generated from "PM CCTV Bulanan" (due 2026-07-05, a Sunday).
                'code' => 'TKT-202607-0003',
                'site_id' => $mainSiteId,
                'title' => 'PM CCTV Bulanan — 05/07/2026',
                'description' => "Checklist PM:\n- Cek kondisi fisik & fokus seluruh kamera\n- Cek rekaman & retensi NVR (min. 30 hari)\n- Cek kesehatan HDD NVR (SMART)\n- Bersihkan housing kamera outdoor\n- Uji playback & backup rekaman sampel",
                'category' => 'preventive',
                'priority' => 'low',
                'status' => 'assigned',
                'channel' => 'system',
                'reported_by_name' => 'Jadwal PM otomatis',
                'reported_at' => '2026-07-05 06:00:00',
                'assigned_to' => $technicianId,
                'response_due_at' => '2026-07-06 12:00:00',   // Sunday snaps to Mon 08:00 + 4 business hours
                'resolution_due_at' => '2026-07-08 14:00:00', // Mon 9h + Tue 9h + Wed 6h
                'first_response_at' => null,
                'resolved_at' => null,
                'closed_at' => null,
                'resolution_notes' => null,
                'activities' => [
                    ['type' => 'assignment', 'body' => 'Tiket ditugaskan kepada Joko Susilo (jadwal PM).', 'at' => '2026-07-05 06:00:00'],
                ],
            ],
            [
                // Fresh open ticket, assigned, first response given, SLA still running.
                'code' => 'TKT-202607-0004',
                'site_id' => $mainSiteId,
                'title' => 'Akses kontrol pintu farmasi sering gagal membaca kartu',
                'description' => 'Reader pintu farmasi gedung utama harus tap 3-4 kali sebelum terbuka; kadang tidak merespons sama sekali.',
                'category' => 'incident',
                'priority' => 'medium',
                'status' => 'assigned',
                'channel' => 'phone',
                'reported_by_name' => 'Darto Prasetyo',
                'reported_at' => '2026-07-24 14:30:00',       // Friday afternoon
                'assigned_to' => $technicianId,
                'response_due_at' => '2026-07-27 09:30:00',   // Fri 2.5h left + Mon 1.5h
                'resolution_due_at' => '2026-07-29 11:30:00', // Fri 2.5h + Mon 9h + Tue 9h + Wed 3.5h
                'first_response_at' => '2026-07-24 15:10:00',
                'resolved_at' => null,
                'closed_at' => null,
                'resolution_notes' => null,
                'activities' => [
                    ['type' => 'assignment', 'body' => 'Tiket ditugaskan kepada Joko Susilo.', 'at' => '2026-07-24 14:45:00'],
                    ['type' => 'comment', 'body' => 'Konfirmasi ke PIC: reader pintu farmasi intermiten. Kunjungan dijadwalkan Senin pagi, dugaan awal reader aus atau kabel data terjepit.', 'at' => '2026-07-24 15:10:00'],
                ],
            ],
        ];

        foreach ($tickets as $data) {
            $activities = $data['activities'];
            unset($data['activities']);

            $ticket = Ticket::withTrashed()->updateOrCreate(
                ['code' => $data['code']],
                array_merge($data, [
                    'service_contract_id' => $contract->id,
                    'customer_id' => $contract->customer_id,
                ]),
            );

            $ticket->activities()->delete();

            foreach ($activities as $activity) {
                $ticket->activities()->create([
                    'user_id' => $userId,
                    'activity_type' => $activity['type'],
                    'body' => $activity['body'],
                    'minutes_spent' => $activity['minutes'] ?? null,
                    'created_at' => $activity['at'],
                ]);
            }
        }
    }

    private function seedFieldReport(): void
    {
        $ticket = Ticket::query()->where('code', 'TKT-202606-0002')->first();
        $technicianId = Employee::query()->where('code', 'EMP-0007')->value('id');

        if (! $ticket || ! $technicianId) {
            return; // technician column is required — skip when HR canon is absent
        }

        $report = FieldReport::withTrashed()->updateOrCreate(
            ['code' => 'PM/2026/VI/0001'],
            [
                'ticket_id' => $ticket->id,
                'report_date' => '2026-06-10',
                'technician_employee_id' => $technicianId,
                'findings' => 'PSU NVR utama gagal (output 12V drop ke 8V), menyebabkan NVR mati total. '
                    .'Saat pengujian menyeluruh ditemukan 1 kamera dome lobi lantai 1 mati total (tidak menyala dengan PSU baru).',
                'actions_taken' => 'Penggantian PSU NVR dengan unit cadangan, penggantian 1 unit CCTV Dome 4MP di lobi lantai 1, '
                    .'konfigurasi ulang channel NVR, pengujian rekaman dan playback seluruh 32 kamera gedung utama — hasil normal.',
                'recommendations' => 'Sarankan penambahan UPS khusus untuk rak NVR dan penggantian bertahap kamera produksi 2019 '
                    .'yang sudah melewati umur pakai (estimasi 6 unit dalam 12 bulan ke depan).',
                'customer_sign_name' => 'Darto Prasetyo',
                'customer_signed_at' => '2026-06-10 17:05:00',
                'status' => 'acknowledged',
            ],
        );

        $report->parts()->delete();

        $itemId = Item::query()->where('code', 'ITM-0004')->value('id'); // CCTV Dome 4MP

        if ($itemId) {
            $report->parts()->create([
                'item_id' => $itemId,
                'qty' => 1,
                'notes' => 'Penggantian kamera dome lobi lantai 1 (unit lama mati total). Pengeluaran stok via modul Inventory.',
            ]);
        }
    }

    /**
     * Seeded codes use explicit sequence numbers; move the 2026 counters past
     * them so runtime-generated SVC/TKT/PM numbers never collide with the canon.
     */
    private function syncNumberSequences(): void
    {
        foreach (['SVC' => 1, 'TKT' => 4, 'PM' => 1] as $type => $minimum) {
            $sequence = NumberSequence::query()->firstOrCreate(
                ['type' => $type, 'year' => 2026],
                ['last_number' => 0],
            );

            if ((int) $sequence->last_number < $minimum) {
                $sequence->update(['last_number' => $minimum]);
            }
        }
    }
}
