# Verifikasi migrasi SQLite → MySQL — gladi (dry run), 5 September 2026

Bukti T0.5 (ROADMAP-HASHMICRO Fase 0): salinan berkas SQLite produksi erp1
(`cp` 04:55 UTC, 2,98 MB, 227 migrasi) dipindahkan ke basis data MySQL kosong
`erp_dryrun` dengan `erp:sqlite-to-mysql`, lalu dibuktikan sama dengan
`erp:migration-verify`. Tidak ada yang menyentuh produksi: sumbernya salinan,
tujuannya basis data gladi.

Urutan yang dijalankan (persis urutan runbook DEPLOYMENT.md §10.9, langkah
"salin" dan "verifikasi"):

```
DB_CONNECTION=mysql DB_DATABASE=erp_dryrun php artisan migrate:fresh --force        # tujuan kosong, 256 langkah
SQLITE_LEGACY_PATH=<salinan> php artisan migrate --database=sqlite_legacy --force   # yang dilakukan deploy: menambah baris 000746 (no-op di SQLite)
SQLITE_LEGACY_PATH=<salinan> DB_CONNECTION=mysql DB_DATABASE=erp_dryrun \
    php artisan erp:sqlite-to-mysql --from=<salinan> --to=mysql
SQLITE_LEGACY_PATH=<salinan> DB_CONNECTION=mysql DB_DATABASE=erp_dryrun \
    php artisan erp:migration-verify --from=sqlite_legacy --to=mysql
```

## Keluaran erp:sqlite-to-mysql (ringkasan)

```
Sumber : sqlite <S>/prod-copy-migrated.sqlite
Tujuan : mysql erp_dryrun (koneksi mysql)
  … 189 baris per tabel …
189 tabel, 1240 baris disalin.
AUTO_INCREMENT: 181 tabel sudah ≥ max(id)+1, 0 tabel disetel ulang lewat ALTER TABLE.
JSON dinormalkan: 5 nilai; DATE dipotong jamnya: 367 nilai.
Perubahan nilai: 0 (tidak ada desimal lepas-skala, tidak ada jam yang hilang dari kolom DATE).
Selanjutnya: php artisan erp:migration-verify --from=sqlite_legacy --to=mysql
```

Dua hal yang diukur gladi pertama dan diperbaiki sebelum keluaran di atas:
(1) SQLite menyimpan `prj_baseline_points.planned_value` sebagai REAL, dan
pembacaan float 12 desimal melaporkan `21048283043.470001220703 →
21048283043.47` sebagai "pembulatan" — itu representasi float, bukan nilai;
alat kini membaca REAL sebagai bentuk terpendek yang round-trip (seperti
`json_encode`), dan daftar perubahan nilai menjadi kosong seperti yang
diukur preflight T0.1. (2) `migrations` berbeda hash hanya karena `batch`
(tujuan segar = batch 1); ledger kini dibandingkan berdasarkan nama migrasi
saja.

Pemeriksaan tambahan di erp_dryrun sesudah salin: `prj_daily_reports` 5 baris,
`live_key = 1` pada 4 baris = `deleted_at IS NULL` pada 4 baris (kolom
generated dihitung server, tidak pernah dikirim); `report_date` tersimpan
sebagai `2026-03-25` (DATE), sumber `2026-03-25 00:00:00`; AUTO_INCREMENT
`users` = 12 untuk max(id) = 11, `fin_journals` = 17 untuk 16 jurnal (dibaca
dengan `information_schema_stats_expiry = 0` — nilai cache 24 jam di klien
lain masih berbunyi 1).

## Laporan erp:migration-verify

# Laporan verifikasi migrasi — 2026-09-05 14:33:54

- Sumber: `sqlite_legacy` (sqlite `<S>/prod-copy-migrated.sqlite`)
- Tujuan: `mysql` (mysql `erp_dryrun`)
- **190 tabel dibandingkan**, 1468 baris sumber / 1468 baris tujuan, 264 kolom desimal dijumlahkan, **0 selisih**, 0 tidak diketahui → **identik**

Ukuran per tabel: jumlah baris; SUM(ROUND(kolom, skala)) per kolom desimal (dihitung eksak sebagai bilangan bulat berskala); md5 bebas-urutan atas kolom kunci (id lalu urutan skema; tanpa kolom generated, desimal, JSON). Angka yang tidak bisa dihitung ditulis `?`.

## Selisih

Tidak ada. Setiap tabel yang dibandingkan identik pada ketiga ukuran.

## Per tabel

| Tabel | Baris sumber | Baris tujuan | Kolom kunci | Hash sumber | Hash tujuan | Desimal (SUM sumber = tujuan) | Status |
|---|---:|---:|---|---|---|---|---|
| `ast_assets` | 6 | 6 | id, code, name, category_id, brand | `fba61ac82ebc` | `fba61ac82ebc` | rental_rate=kosong, acquisition_cost=1635000000.00, salvage_value=213000000.00, accumulated_depreciation=363750000.00, book_value=1271250000.00, disposal_value=kosong | sama |
| `ast_categories` | 5 | 5 | id, code, name, useful_life_months_default, depreciation_account_hint | `69bd67160cf7` | `69bd67160cf7` | — | sama |
| `ast_deployments` | 3 | 3 | id, code, asset_id, project_id, deployed_from | `f4648e5afa1e` | `f4648e5afa1e` | daily_rate_internal=4000000.00 | sama |
| `ast_depreciation_entries` | 6 | 6 | id, depreciation_run_id, asset_id, created_at, updated_at | `0d59e64515c9` | `0d59e64515c9` | amount=25125000.00, book_value_after=1271250000.00 | sama |
| `ast_depreciation_runs` | 1 | 1 | id, code, period_year, period_month, status | `3366c305c2e6` | `3366c305c2e6` | total_amount=25125000.00 | sama |
| `ast_equipment_logs` | 0 | 0 | id, deployment_id, log_date, notes, logged_by | `d41d8cd98f00` | `d41d8cd98f00` | hour_meter=kosong, fuel_liters=kosong | sama |
| `ast_maintenances` | 1 | 1 | id, code, asset_id, maintenance_date, maintenance_type | `d5c269eb167e` | `d5c269eb167e` | cost=18500000.00 | sama |
| `cache` | 3 | 3 | key, value, expiration | `fe5c59b69e15` | `fe5c59b69e15` | — | sama |
| `cache_locks` | 0 | 0 | key, owner, expiration | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `core_approvals` | 36 | 36 | id, approvable_type, approvable_id, action, user_id | `18422acab616` | `18422acab616` | — | sama |
| `core_attachments` | 6 | 6 | id, attachable_type, attachable_id, disk, path | `9aade32ecd2e` | `9aade32ecd2e` | latitude=-12.5050000, longitude=213.6204200 | sama |
| `core_audit_log` | 12 | 12 | id, user_id, user_name, event, auditable_type | `4da1b3f0313a` | `4da1b3f0313a` | — | sama |
| `core_company` | 1 | 1 | id, name, legal_name, npwp, nib | `d56f873ca294` | `d56f873ca294` | — | sama |
| `core_external_approvals` | 0 | 0 | id, document_slug, document_id, party, name | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `core_locations` | 1 | 1 | id, project_id, parent_id, kind, code | `785c66d8aa01` | `785c66d8aa01` | — | sama |
| `core_method_library` | 1 | 1 | id, code, category, work_package, title | `6364e943ef42` | `6364e943ef42` | — | sama |
| `core_notifications` | 50 | 50 | id, user_id, event, title, body | `9f3963cbd3de` | `9f3963cbd3de` | — | sama |
| `core_number_sequences` | 32 | 32 | id, type, year, scope, last_number | `5e766efc7543` | `5e766efc7543` | — | sama |
| `core_rate_history` | 0 | 0 | id, rate_key, changed_by, created_at, updated_at | `d41d8cd98f00` | `d41d8cd98f00` | old_rate=kosong, new_rate=kosong | sama |
| `core_settings` | 1 | 1 | id, key, group, created_at, updated_at | `e494313815e3` | `e494313815e3` | — | sama |
| `crm_contract_change_orders` | 2 | 2 | id, code, contract_id, change_date, title | `9e2584662fbf` | `9e2584662fbf` | value_change=900000000.00, ppn_change=99000000.00 | sama |
| `crm_contract_termins` | 19 | 19 | id, contract_id, termin_no, name, billing_condition | `c5a8d12142ec` | `c5a8d12142ec` | percent=400.0000, amount=60618385147.00 | sama |
| `crm_contracts` | 5 | 5 | id, code, customer_id, quotation_id, contract_number_customer | `8847c77136ef` | `8847c77136ef` | value=61098985147.00, original_value=480000000.00, ppn_rate=55.0000, ppn_amount=6720888366.17, total_with_ppn=67819873513.17, retention_pct=25.0000 | sama |
| `crm_customers` | 4 | 4 | id, code, name, legal_name, npwp | `eaf73a9029e0` | `eaf73a9029e0` | — | sama |
| `crm_guarantees` | 0 | 0 | id, guarantee_type, number, issuer, contract_id | `d41d8cd98f00` | `d41d8cd98f00` | value=kosong | sama |
| `crm_leads` | 3 | 3 | id, code, name, company_name, source | `8d3d842ba946` | `8d3d842ba946` | estimated_value=71300000000.00 | sama |
| `crm_quotation_items` | 29 | 29 | id, quotation_id, line_no, description, unit | `9960d706b6a2` | `9960d706b6a2` | qty=99.000, unit_price=53644246320.00, amount=60722296320.00 | sama |
| `crm_quotations` | 9 | 9 | id, code, customer_id, lead_id, title | `7edaaf7fa02d` | `7edaaf7fa02d` | subtotal=60722296320.00, discount_amount=0.00, dpp=60722296320.00, ppn_rate=100.0000, ppn_amount=6679462595.20, total=67401758915.20 | sama |
| `crm_rkk_documents` | 0 | 0 | id, code, tender_package_id, project_id, boq_id | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `crm_rkk_ibprp_links` | 0 | 0 | id, rkk_id, risk_entry_id, sort_order, created_at | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `crm_rkk_smkk_costs` | 0 | 0 | id, rkk_id, boq_item_id, sort_order, category | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `crm_tender_documents` | 0 | 0 | id, tender_package_id, sort_order, title, chapter | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `crm_tender_packages` | 0 | 0 | id, code, lead_id, title, owner_name | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `crm_tkdn_worksheet_items` | 0 | 0 | id, worksheet_id, quotation_item_id, sort_order, cost_group | `d41d8cd98f00` | `d41d8cd98f00` | amount=kosong, domestic_share_pct=kosong | sama |
| `crm_tkdn_worksheets` | 0 | 0 | id, code, quotation_id, tender_package_id, notes | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `eng_drawing_submittals` | 0 | 0 | id, code, drawing_id, revision, submitted_at | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `eng_drawings` | 0 | 0 | id, project_id, number, title, discipline | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `eng_ipp_drawings` | 0 | 0 | id, ipp_id, drawing_submittal_id, created_at, updated_at | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `eng_ipp_equipment` | 0 | 0 | id, ipp_id, description, qty, notes | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `eng_ipp_material_approvals` | 0 | 0 | id, ipp_id, material_submittal_id, created_at, updated_at | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `eng_ipp_materials` | 0 | 0 | id, ipp_id, item_id, description, unit | `d41d8cd98f00` | `d41d8cd98f00` | qty=kosong | sama |
| `eng_material_submittals` | 0 | 0 | id, code, project_id, item_id, material_name | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `eng_transmittal_lines` | 0 | 0 | id, transmittal_id, document_type, document_id, description | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `eng_transmittals` | 0 | 0 | id, code, project_id, direction, to_party | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `eng_work_permits_ipp` | 0 | 0 | id, code, project_id, scope, location_id | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `est_ahsp` | 8 | 8 | id, code, name, unit, category | `78bb91c29e89` | `78bb91c29e89` | overhead_pct=80.0000, unit_price=18896301.60 | sama |
| `est_ahsp_components` | 40 | 40 | id, ahsp_id, component_type, name, item_id | `c3d90c0414ae` | `c3d90c0414ae` | coefficient=108.963400, unit_price=22875300.00 | sama |
| `est_boq_items` | 29 | 29 | id, boq_id, section_id, wbs_code, description | `ceddb8331cd4` | `ceddb8331cd4` | qty=1063375.000, unit_price=7987699516.40, amount=68100000000.00 | sama |
| `est_boq_sections` | 9 | 9 | id, boq_id, section_no, name, sort_order | `8cfeb4a35ed4` | `8cfeb4a35ed4` | subtotal=68100000000.00 | sama |
| `est_boqs` | 4 | 4 | id, code, project_id, quotation_id, contract_id | `19bc04d59749` | `19bc04d59749` | total=68100000000.00 | sama |
| `est_cost_budget_items` | 23 | 23 | id, cost_budget_id, boq_item_id, cost_category, description | `e0ed3c02c6c6` | `e0ed3c02c6c6` | qty=3024363.000, unit_price=1500314762.04, amount=42173913043.47 | sama |
| `est_cost_budgets` | 1 | 1 | id, code, boq_id, project_id, status | `5ea9e34b4b45` | `5ea9e34b4b45` | target_margin_pct=15.0000, total_budget=42173913043.47 | sama |
| `failed_jobs` | 0 | 0 | id, uuid, connection, queue, payload | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `fin_accounts` | 78 | 78 | id, code, name, account_type, parent_id | `826a9e315168` | `826a9e315168` | — | sama |
| `fin_ap_bill_goods_receipts` | 0 | 0 | id, ap_bill_id, goods_receipt_id, created_at, updated_at | `d41d8cd98f00` | `d41d8cd98f00` | dpp_amount=kosong, cleared_amount=kosong | sama |
| `fin_ap_bills` | 2 | 2 | id, code, vendor_id, project_id, cost_category | `f63f7f154c49` | `f63f7f154c49` | dpp=258000000.00, ppn_amount=23045000.00, pph_amount=0.00, retention_amount=0.00, total_payable=281045000.00, gl_cleared_amount=0.00, advance_applied_amount=0.00, amount_paid=232545000.00 | sama |
| `fin_ar_invoices` | 4 | 4 | id, code, customer_id, contract_id, termin_id | `a6307664a97c` | `a6307664a97c` | dpp=24490000000.00, ppn_rate=44.0000, ppn_amount=2693900000.00, retention_withheld=727500000.00, advance_recovery_amount=0.00, penalty_amount=0.00, total=26456400000.00, amount_paid=10900200000.00 | sama |
| `fin_ar_retentions` | 1 | 1 | id, contract_id, project_id, source_invoice_id, released | `c7816c7c7802` | `c7816c7c7802` | amount=727500000.00 | sama |
| `fin_bank_accounts` | 2 | 2 | id, code, name, bank_name, account_no | `2f5a7ad05b6a` | `2f5a7ad05b6a` | — | sama |
| `fin_bank_statement_lines` | 3 | 3 | id, bank_statement_id, line_no, entry_date, value_date | `df37abe2aac2` | `df37abe2aac2` | amount=10999795000.00 | sama |
| `fin_bank_statements` | 2 | 2 | id, code, bank_account_id, source_format, statement_ref | `5969ced9999d` | `5969ced9999d` | opening_balance=0.00, closing_balance=10534205000.00 | sama |
| `fin_fiscal_periods` | 24 | 24 | id, year, month, status, closed_at | `48b798debe88` | `48b798debe88` | — | sama |
| `fin_journal_lines` | 43 | 43 | id, journal_id, account_id, description, project_id | `ee3d98968682` | `ee3d98968682` | debit=49140717490.83, credit=49140717490.83 | sama |
| `fin_journals` | 16 | 16 | id, code, journal_date, description, reference_type | `fd3507559a13` | `fd3507559a13` | — | sama |
| `fin_kasbon_lines` | 0 | 0 | id, kasbon_id, category, description, project_id | `d41d8cd98f00` | `d41d8cd98f00` | amount=kosong | sama |
| `fin_kasbons` | 0 | 0 | id, code, fund_id, employee_id, advance_date | `d41d8cd98f00` | `d41d8cd98f00` | amount=kosong, cash_returned=kosong, wage_offset_total=kosong | sama |
| `fin_payment_allocations` | 4 | 4 | id, payment_id, payable_type, payable_id, remark | `f4757d8c62e4` | `f4757d8c62e4` | amount=11142745000.00 | sama |
| `fin_payment_withholdings` | 1 | 1 | id, payment_id, ar_invoice_id, type, reason | `d319869b9405` | `d319869b9405` | amount=3180000.00 | sama |
| `fin_payments` | 4 | 4 | id, code, direction, payment_date, bank_account_id | `17e9f36e7d3f` | `17e9f36e7d3f` | amount=11139565000.00 | sama |
| `fin_period_events` | 0 | 0 | id, fiscal_period_id, action, user_id, note | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `fin_petty_cash_funds` | 1 | 1 | id, code, name, coa_account_id, custodian_id | `1b65782d7e83` | `1b65782d7e83` | float_amount=10000000.00, max_voucher_amount=kosong, max_kasbon_amount=kosong | sama |
| `fin_petty_cash_vouchers` | 0 | 0 | id, code, fund_id, voucher_date, category | `d41d8cd98f00` | `d41d8cd98f00` | amount=kosong | sama |
| `fin_project_costs` | 18 | 18 | id, project_id, cost_date, cost_category, reference_type | `17f7b018bcc6` | `17f7b018bcc6` | amount=925240000.00 | sama |
| `fin_revenue_recognition_lines` | 2 | 2 | id, run_id, contract_id, project_id, scope_type | `66042c27177c` | `66042c27177c` | transaction_price=58300000000.00, estimated_total_cost=42173913043.47, cost_to_date=209500000.00, progress_pct=0.4968, revenue_cumulative=240925000.00, billed_cumulative=9700000000.00, contract_balance=-9459075000.00, provision_balance=0.00, revenue_adjustment=-9459075000.00, provision_adjustment=0.00 | sama |
| `fin_revenue_recognition_runs` | 1 | 1 | id, code, period_year, period_month, status | `079ae8a4b77f` | `079ae8a4b77f` | total_adjustment=-9459075000.00 | sama |
| `fin_tax_obligations` | 48 | 48 | id, tax_type, masa_year, masa_month, name | `9c989e595e7e` | `9c989e595e7e` | amount=kosong | sama |
| `fin_taxes` | 10 | 10 | id, code, name, tax_type, object_code | `4548190d9bab` | `4548190d9bab` | rate=37.5500 | sama |
| `hr_attendance_recaps` | 8 | 8 | id, employee_id, period_year, period_month, work_days | `3675d911892d` | `3675d911892d` | overtime_hours=64.00 | sama |
| `hr_attendances` | 0 | 0 | id, employee_id, date, status, project_id | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `hr_certificates` | 0 | 0 | id, employee_id, certificate_type, name, number | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `hr_employees` | 8 | 8 | id, code, name, nik_ktp, npwp | `192123980aa9` | `192123980aa9` | base_salary=153500000.00 | sama |
| `hr_leave_requests` | 0 | 0 | id, code, employee_id, leave_type, start_date | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `hr_payroll_runs` | 2 | 2 | id, code, period_year, period_month, run_type | `5c4416094b3d` | `5c4416094b3d` | total_gross=344770346.83, total_deductions=65625415.40, total_net=279144931.43 | sama |
| `hr_payslips` | 16 | 16 | id, payroll_run_id, employee_id, project_id, ter_category | `0706b9c14e48` | `0706b9c14e48` | basic_salary=153500000.00, allowances_total=34220000.00, overtime_hours=64.00, overtime_pay=8550346.83, thr_amount=148500000.00, gross_income=344770346.83, bpjs_employee_total=5497518.00, bpjs_company_total=14502144.00, ter_rate=176.5000, pph21_amount=60127897.40, total_deductions=65625415.40, net_pay=279144931.43 | sama |
| `inv_goods_receipt_items` | 7 | 7 | id, goods_receipt_id, item_id, po_item_id, created_at | `eb0904a54bff` | `eb0904a54bff` | qty=2220.000, unit_cost=9965000.00, amount=351250000.00 | sama |
| `inv_goods_receipts` | 1 | 1 | id, code, warehouse_id, purchase_order_id, vendor_id | `0978536ee438` | `0978536ee438` | gl_clearing_amount=kosong | sama |
| `inv_issue_items` | 3 | 3 | id, issue_id, item_id, wbs_task_id, created_at | `c0d9d785f3d2` | `c0d9d785f3d2` | qty=1000229.000, unit_cost=180000.00, amount=18740000.00 | sama |
| `inv_issue_return_items` | 0 | 0 | id, issue_return_id, issue_item_id, item_id, created_at | `d41d8cd98f00` | `d41d8cd98f00` | qty=kosong, unit_cost=kosong, amount=kosong | sama |
| `inv_issue_returns` | 0 | 0 | id, code, issue_id, warehouse_id, return_date | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `inv_issues` | 2 | 2 | id, code, warehouse_id, project_id, wbs_task_id | `61dffd8f2296` | `61dffd8f2296` | — | sama |
| `inv_item_categories` | 5 | 5 | id, code, name, parent_id, created_at | `dfdb77398c18` | `dfdb77398c18` | — | sama |
| `inv_items` | 8 | 8 | id, code, name, category_id, unit | `070558da1275` | `070558da1275` | min_stock=335.000, avg_cost=9965000.00, last_price=10915000.00 | sama |
| `inv_purchase_return_items` | 0 | 0 | id, purchase_return_id, grn_item_id, item_id, created_at | `d41d8cd98f00` | `d41d8cd98f00` | qty=kosong, unit_cost=kosong, amount=kosong | sama |
| `inv_purchase_returns` | 0 | 0 | id, code, goods_receipt_id, warehouse_id, vendor_id | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `inv_stock_adjustment_items` | 0 | 0 | id, stock_adjustment_id, item_id, created_at, updated_at | `d41d8cd98f00` | `d41d8cd98f00` | system_qty=kosong, counted_qty=kosong, diff_qty=kosong, unit_cost=kosong | sama |
| `inv_stock_adjustments` | 0 | 0 | id, code, warehouse_id, adjustment_date, reason | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `inv_stock_balances` | 9 | 9 | id, warehouse_id, item_id, created_at, updated_at | `3b9b281a85e9` | `3b9b281a85e9` | qty=1990.000, avg_cost=10145000.00 | sama |
| `inv_stock_ledger` | 13 | 13 | id, item_id, warehouse_id, trx_date, reference_type | `23cde0c10c82` | `23cde0c10c82` | qty=4050.000, unit_cost=10505000.00, balance_qty_after=4790.000 | sama |
| `inv_transfer_items` | 2 | 2 | id, transfer_id, item_id, created_at, updated_at | `dd219ce3009a` | `dd219ce3009a` | qty=800.000, unit_cost=180000.00 | sama |
| `inv_transfers` | 1 | 1 | id, code, from_warehouse_id, to_warehouse_id, transfer_date | `f8c575530db0` | `f8c575530db0` | — | sama |
| `inv_warehouses` | 3 | 3 | id, code, name, project_id, address | `97b35e7d3001` | `97b35e7d3001` | — | sama |
| `job_batches` | 0 | 0 | id, name, total_jobs, pending_jobs, failed_jobs | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `jobs` | 0 | 0 | id, queue, payload, attempts, reserved_at | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `migrations` | 228 | 228 | migration | `9715a5766a31` | `9715a5766a31` | — | sama |
| `model_has_permissions` | 0 | 0 | permission_id, model_type, model_id | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `model_has_roles` | 11 | 11 | role_id, model_type, model_id | `2d6491f8e971` | `2d6491f8e971` | — | sama |
| `password_reset_tokens` | 0 | 0 | email, token, created_at | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `permissions` | 86 | 86 | id, name, guard_name, created_at, updated_at | `e0b137f9901f` | `e0b137f9901f` | — | sama |
| `personal_access_tokens` | 6 | 6 | id, tokenable_type, tokenable_id, name, token | `6745a7d518ee` | `6745a7d518ee` | — | sama |
| `prc_award_decisions` | 0 | 0 | id, code, rfq_id, vendor_id, deviation_reason | `d41d8cd98f00` | `d41d8cd98f00` | rab_amount=kosong, awarded_amount=kosong, deviation_amount=kosong | sama |
| `prc_bid_evaluations` | 0 | 0 | id, rfq_id, vendor_id, rank, notes | `d41d8cd98f00` | `d41d8cd98f00` | rab_amount=kosong, offered_amount=kosong, harga_score=kosong, mutu_score=kosong, waktu_score=kosong, keuangan_score=kosong, k3_score=kosong, weighted_score=kosong | sama |
| `prc_negotiation_minute_items` | 0 | 0 | id, negotiation_minute_id, rfq_item_id, line_no, description | `d41d8cd98f00` | `d41d8cd98f00` | qty=kosong, harga_awal=kosong, harga_nego=kosong | sama |
| `prc_negotiation_minutes` | 0 | 0 | id, code, rfq_id, vendor_id, meeting_date | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prc_procurement_plan_items` | 0 | 0 | id, procurement_plan_id, line_no, boq_item_id, package | `d41d8cd98f00` | `d41d8cd98f00` | estimated_amount=kosong | sama |
| `prc_procurement_plans` | 0 | 0 | id, code, project_id, cost_budget_id, title | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prc_purchase_order_items` | 5 | 5 | id, purchase_order_id, line_no, item_id, boq_item_id | `52936b9e4ada` | `52936b9e4ada` | qty=2370.000, unit_price=7547000.00, amount=325100000.00, qty_received=0.000 | sama |
| `prc_purchase_orders` | 2 | 2 | id, code, vendor_id, purchase_requisition_id, rfq_id | `11c5988961fc` | `11c5988961fc` | subtotal=325100000.00, discount_amount=0.00, dpp=325100000.00, ppn_rate=22.0000, ppn_amount=35761000.00, total=360861000.00 | sama |
| `prc_purchase_requisition_items` | 5 | 5 | id, purchase_requisition_id, line_no, item_id, description | `f2bc54d6ba70` | `f2bc54d6ba70` | qty=3570.000, estimated_price=7407000.00 | sama |
| `prc_purchase_requisitions` | 2 | 2 | id, code, project_id, warehouse_id, requested_by | `7d0193fc78b6` | `7d0193fc78b6` | — | sama |
| `prc_rfq_items` | 0 | 0 | id, rfq_id, line_no, item_id, boq_item_id | `d41d8cd98f00` | `d41d8cd98f00` | qty=kosong | sama |
| `prc_rfq_quotes` | 0 | 0 | id, rfq_item_id, vendor_id, is_winner, notes | `d41d8cd98f00` | `d41d8cd98f00` | unit_price=kosong | sama |
| `prc_rfq_vendors` | 0 | 0 | id, rfq_id, vendor_id, notes, created_at | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prc_rfqs` | 0 | 0 | id, code, purchase_requisition_id, project_id, rfq_date | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prc_vendor_documents` | 0 | 0 | id, vendor_id, doc_type, name, number | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prc_vendor_evaluations` | 1 | 1 | id, vendor_id, project_id, evaluated_by, period | `5c638f8ff297` | `5c638f8ff297` | total_score=4.50 | sama |
| `prc_vendors` | 5 | 5 | id, code, name, legal_name, npwp | `7753881fc99a` | `7753881fc99a` | rating=4.5 | sama |
| `prc_work_order_billing_lines` | 0 | 0 | id, work_order_billing_id, work_order_item_id, created_at, updated_at | `d41d8cd98f00` | `d41d8cd98f00` | qty=kosong, amount=kosong, meter_start=kosong, meter_end=kosong | sama |
| `prc_work_order_billings` | 0 | 0 | id, code, work_order_id, billing_no, period_start | `d41d8cd98f00` | `d41d8cd98f00` | total_amount=kosong | sama |
| `prc_work_order_items` | 0 | 0 | id, work_order_id, line_no, asset_id, description | `d41d8cd98f00` | `d41d8cd98f00` | rate=kosong, qty_periods=kosong, amount=kosong | sama |
| `prc_work_orders` | 0 | 0 | id, code, vendor_id, project_id, title | `d41d8cd98f00` | `d41d8cd98f00` | value=kosong, ppn_rate=kosong | sama |
| `prj_baseline_points` | 18 | 18 | id, baseline_id, seq, period_end, created_at | `8839947a7ad4` | `8839947a7ad4` | planned_pct=945.8715, planned_value=398911023912.95 | sama |
| `prj_baseline_tasks` | 14 | 14 | id, baseline_id, wbs_task_id, wbs_code, parent_wbs_code | `cc033fef77cb` | `cc033fef77cb` | weight_pct=200.0000 | sama |
| `prj_baselines` | 1 | 1 | id, code, project_id, revision_no, status | `eb5f8dbe93f8` | `eb5f8dbe93f8` | bac=42173913043.47, contract_value=48500000000.00, leaf_weight_total=100.0000 | sama |
| `prj_bast` | 1 | 1 | id, code, project_id, bast_type, handover_date | `2c25cf2db25c` | `2c25cf2db25c` | — | sama |
| `prj_contract_variations` | 0 | 0 | id, contract_id, change_order_id, boq_item_id, unit | `d41d8cd98f00` | `d41d8cd98f00` | qty_change=kosong | sama |
| `prj_daily_report_activities` | 0 | 0 | id, daily_report_id, wbs_task_id, description, progress_note | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prj_daily_report_equipment` | 0 | 0 | id, daily_report_id, asset_id, description, qty | `d41d8cd98f00` | `d41d8cd98f00` | hours=kosong | sama |
| `prj_daily_report_manpower` | 0 | 0 | id, daily_report_id, role_key, headcount, notes | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prj_daily_report_materials` | 7 | 7 | id, daily_report_id, item_id, unit, created_at | `55a2554764af` | `55a2554764af` | qty_used=1212.000 | sama |
| `prj_daily_report_receipts` | 0 | 0 | id, daily_report_id, goods_receipt_id, item_id, description | `d41d8cd98f00` | `d41d8cd98f00` | qty_received=kosong, qty_rejected=kosong | sama |
| `prj_daily_reports` | 5 | 5 | id, code, project_id, report_date, weather_am | `60e86a7537f5` | `60e86a7537f5` | — | sama |
| `prj_defects` | 0 | 0 | id, code, project_id, wbs_task_id, subcontract_id | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prj_gate_pass_items` | 0 | 0 | id, gate_pass_id, item_id, description, unit | `d41d8cd98f00` | `d41d8cd98f00` | qty=kosong | sama |
| `prj_gate_passes` | 0 | 0 | id, code, project_id, direction, pass_date | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prj_hse_daily` | 0 | 0 | id, code, project_id, report_date, daily_report_id | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prj_hse_daily_apd` | 0 | 0 | id, hse_daily_id, category, qty, created_at | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prj_hse_daily_findings` | 0 | 0 | id, hse_daily_id, sort_order, finding, follow_up | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prj_manpower_assignments` | 2 | 2 | id, project_id, employee_id, role_on_project, assigned_from | `527c9a8f1227` | `527c9a8f1227` | — | sama |
| `prj_milestones` | 2 | 2 | id, project_id, name, due_date, achieved_date | `c36bb5ac8c45` | `c36bb5ac8c45` | — | sama |
| `prj_overtime_permit_workers` | 0 | 0 | id, overtime_permit_id, employee_id, worker_name, created_at | `d41d8cd98f00` | `d41d8cd98f00` | hours=kosong | sama |
| `prj_overtime_permits` | 0 | 0 | id, code, project_id, overtime_date, start_time | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prj_progress_measurement_items` | 0 | 0 | id, progress_measurement_id, boq_item_id, location_id, description | `d41d8cd98f00` | `d41d8cd98f00` | unit_price=kosong, qty_prev=kosong, qty_this=kosong, qty_cum=kosong, amount=kosong | sama |
| `prj_progress_measurements` | 0 | 0 | id, code, project_id, contract_id, measurement_no | `d41d8cd98f00` | `d41d8cd98f00` | period_amount=kosong, cumulative_amount=kosong | sama |
| `prj_projects` | 5 | 5 | id, code, name, contract_id, customer_id | `a8278ce8fb8d` | `a8278ce8fb8d` | latitude=-12.5050170, longitude=213.6204160, contract_value=63815155441.00, retention_pct=35.0000, planned_progress_pct=2.0000, actual_progress_pct=0.0000 | sama |
| `prj_risk_register` | 0 | 0 | id, project_id, sort_order, activity, hazard | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prj_safety_incidents` | 2 | 2 | id, code, project_id, occurred_at, location | `905539b3654c` | `905539b3654c` | — | sama |
| `prj_wbs_tasks` | 26 | 26 | id, project_id, parent_id, boq_item_id, wbs_code | `968215a836d4` | `968215a836d4` | weight_pct=400.0000, progress_pct=0.0000 | sama |
| `prj_weekly_progress` | 8 | 8 | id, project_id, week_no, period_start, period_end | `24bd91a267f9` | `24bd91a267f9` | planned_pct=225.0000, actual_pct=197.5000, deviation_pct=-27.5000 | sama |
| `prj_work_permits` | 0 | 0 | id, code, project_id, wbs_task_id, permit_date | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `prj_zone_certificates` | 0 | 0 | id, code, project_id, location_id, status | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `qc_concrete_samples` | 0 | 0 | id, project_id, location_id, pour_date, grade | `d41d8cd98f00` | `d41d8cd98f00` | slump_cm=kosong, volume_m3=kosong | sama |
| `qc_concrete_tests` | 0 | 0 | id, sample_id, age_days, lab, tested_at | `d41d8cd98f00` | `d41d8cd98f00` | strength_mpa=kosong | sama |
| `qc_inspection_results` | 0 | 0 | id, inspection_id, template_item_id, result, remark | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `qc_inspection_template_items` | 0 | 0 | id, template_id, sort_order, check_text, acceptance | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `qc_inspection_templates` | 0 | 0 | id, code, work_package, stage, created_at | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `qc_inspections` | 0 | 0 | id, code, project_id, ipp_id, location_id | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `qc_ncr` | 0 | 0 | id, code, project_id, inspection_id, location_id | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `role_has_permissions` | 208 | 208 | permission_id, role_id | `779fac9b5ebb` | `779fac9b5ebb` | — | sama |
| `roles` | 12 | 12 | id, name, guard_name, created_at, updated_at | `9c16f9d60dae` | `9c16f9d60dae` | — | sama |
| `scm_handovers` | 0 | 0 | id, code, subcontract_id, handover_type, handover_date | `d41d8cd98f00` | `d41d8cd98f00` | — | sama |
| `scm_labor_claim_items` | 0 | 0 | id, labor_claim_id, labor_contract_item_id, created_at, updated_at | `d41d8cd98f00` | `d41d8cd98f00` | qty_prev=kosong, qty_this=kosong, amount=kosong | sama |
| `scm_labor_claims` | 0 | 0 | id, code, labor_contract_id, claim_no, period_start | `d41d8cd98f00` | `d41d8cd98f00` | gross_amount=kosong, ppn_amount=kosong, pph_amount=kosong, kasbon_deduction_amount=kosong, net_payable=kosong | sama |
| `scm_labor_contract_items` | 0 | 0 | id, labor_contract_id, line_no, boq_item_id, wbs_code | `d41d8cd98f00` | `d41d8cd98f00` | qty=kosong, unit_rate=kosong, amount=kosong | sama |
| `scm_labor_contracts` | 0 | 0 | id, code, vendor_id, project_id, title | `d41d8cd98f00` | `d41d8cd98f00` | value=kosong, ppn_rate=kosong, pph_rate=kosong | sama |
| `scm_progress_claim_items` | 8 | 8 | id, progress_claim_id, subcontract_item_id, created_at, updated_at | `677f677d3a37` | `677f677d3a37` | prev_progress_pct=60.0000, current_progress_pct=188.0000, period_progress_pct=128.0000, amount=2080000000.00 | sama |
| `scm_progress_claims` | 2 | 2 | id, code, subcontract_id, claim_no, is_advance | `ce869a3c1fed` | `ce869a3c1fed` | gross_amount=2080000000.00, retention_amount=104000000.00, net_before_tax=1976000000.00, ppn_amount=0.00, pph_amount=55120000.00, advance_recovery_amount=0.00, net_payable=1920880000.00 | sama |
| `scm_retention_releases` | 0 | 0 | id, subcontract_id, ap_bill_id, release_date, notes | `d41d8cd98f00` | `d41d8cd98f00` | amount=kosong | sama |
| `scm_subcontract_addenda` | 0 | 0 | id, code, subcontract_id, addendum_date, title | `d41d8cd98f00` | `d41d8cd98f00` | value_change=kosong | sama |
| `scm_subcontract_addendum_items` | 0 | 0 | id, addendum_id, wbs_code, description, unit | `d41d8cd98f00` | `d41d8cd98f00` | qty=kosong, unit_price=kosong, amount=kosong | sama |
| `scm_subcontract_items` | 7 | 7 | id, subcontract_id, boq_item_id, line_no, wbs_code | `e8098d6b0a26` | `e8098d6b0a26` | qty=1008203.000, unit_price=2100367500.00, amount=8600000000.00, progress_pct=128.0000 | sama |
| `scm_subcontracts` | 2 | 2 | id, code, vendor_id, project_id, rfq_id | `2c7e941ddf6d` | `2c7e941ddf6d` | value=8600000000.00, original_value=kosong, ppn_rate=11.0000, retention_pct=10.0000, pph_rate=5.3000 | sama |
| `sessions` | 44 | 44 | id, user_id, ip_address, user_agent, payload | `a1e1a58383ac` | `a1e1a58383ac` | — | sama |
| `svc_contract_sites` | 2 | 2 | id, service_contract_id, site_name, address, city | `5db4aee475bf` | `5db4aee475bf` | — | sama |
| `svc_contracts` | 1 | 1 | id, code, customer_id, contract_id, name | `1683014cd3ac` | `1683014cd3ac` | contract_value=480000000.00 | sama |
| `svc_field_report_parts` | 1 | 1 | id, field_report_id, item_id, notes, created_at | `d858dd4103de` | `d858dd4103de` | qty=1.000 | sama |
| `svc_field_reports` | 1 | 1 | id, code, ticket_id, report_date, technician_employee_id | `ed0ad745fb80` | `ed0ad745fb80` | — | sama |
| `svc_preventive_schedules` | 2 | 2 | id, service_contract_id, site_id, name, frequency | `453d93b86e7e` | `453d93b86e7e` | — | sama |
| `svc_ticket_activities` | 16 | 16 | id, ticket_id, user_id, activity_type, body | `000cd1adb82a` | `000cd1adb82a` | — | sama |
| `svc_tickets` | 7 | 7 | id, code, service_contract_id, customer_id, site_id | `2ad194d46c3d` | `2ad194d46c3d` | — | sama |
| `users` | 11 | 11 | id, name, email, email_verified_at, password | `83210f384bef` | `83210f384bef` | — | sama |
