@php
    // ---------- Helpers ----------
    $fmt = fn($v, $d = 2) => number_format((float) $v, $d);
    $qc = fn($v, $suffix = '') => $v ? (is_numeric($v) ? number_format((float) $v, 2) . $suffix : $v) : '-';
    $qcClass = fn($v) => strtoupper(trim((string) $v)) === 'PASS' ? 'qc-pass' : '';

    // ---------- Company ----------
    $companyAddress = collect([$company->address, $company->city, $company->state, $company->pincode])->filter()->implode(', ');
    $companyGstin = $company->gstin ?: '-';
    $companyState = $company->state ?: '-';
    $companyStateCode = $company->state_code ?: '33';
    $companyPhone = collect([$company->phone, $company->phone_2])->filter()->implode(' / ') ?: '-';

    // ---------- Vendor / Bill ----------
    $vendor = $settlement->vendor;
    $vendorName = $vendor->vendor_name ?? '-';
    $vendorAddress = $vendor->address ?? '';
    $vendorCity = trim(($vendor->city ?? '') . (!empty($vendor->pincode) ? ' - ' . $vendor->pincode : ''));
    $vendorGstin = $vendor->gstin ?? '-';
    $vendorPhone = collect([$vendor->phone ?? null, $vendor->phone_2 ?? null])->filter()->implode(' / ');
    $productName = $settlement->product->product_name ?? '-';
    $lotNumber = $settlement->lot_number ?: '-';
    $vehicleNumber = $settlement->vehicle_number ?: '-';

    $billNo = $settlement->bill_number ?: ($settlement->invoice?->invoice_number ?: '68');
    $billDateRaw = $settlement->bill_date ?: ($settlement->invoice?->invoice_date ?: $settlement->settlement_date);
    $billDate = $billDateRaw ? date('d/m/Y', strtotime($billDateRaw)) : '-';

    $tdsLabel = 'TDS Deduction' . ((!empty($settlement->tds_rate) && (float) $settlement->tds_rate > 0)
        ? ' (' . number_format((float) $settlement->tds_rate, 2) . '%)' : '');

    $remarksText = $settlement->remarks ?: (!empty($showNote)
        ? 'Auto-generated ' . ($debitNoteData['note_type_label'] ?? 'Debit Note') . ' posted to vendor ledger' : '');

    // ---------- Debit / Credit Note ----------
    $noteLabel = $debitNoteData['note_type_label'] ?? 'CREDIT NOTE';
    $isCredit = ($debitNoteData['transaction_type'] ?? 'debit') === 'credit';
    $noteColor = $isCredit ? '#1f6f4a' : '#9b2c2c';
    $noteTotalLb = $isCredit ? 'Total (Credit)' : 'Total (Debit)';
    $noteDocLb = $debitNoteData['doc_number_label'] ?? 'CreditNote No:';
    $noteDate = !empty($debitNoteData['date']) ? date('d/m/Y', strtotime($debitNoteData['date'])) : '-';
    $noteInvDate = !empty($debitNoteData['invoice_date']) ? date('d/m/Y', strtotime($debitNoteData['invoice_date'])) : '-';
    $noteLot = $debitNoteData['lot_number'] ?? '-';
    $noteParticulars = $debitNoteData['particulars_label'] ?? 'Rebate (Oil Content & Quality Adjustment Difference)';
@endphp
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Settlement Bill{{ !empty($showNote) ? ' & ' . $noteLabel : '' }} - {{ $settlement->settlement_number }}
    </title>
    <style>
        /* Page margin is handled by .page padding (works the same in every PDF engine) */
        @page {
            size: A4 portrait;
            margin: 0;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            font-family: 'DejaVu Sans', 'Helvetica Neue', Arial, sans-serif;
            font-size: 8pt;
            line-height: 1.25;
            color: #1a202c;
            background-color: #ffffff;
        }

        .page {
            padding: 10mm 11mm 10mm 11mm;
        }

        /* ---------- Utilities ---------- */
        .text-right {
            text-align: right !important;
        }

        .text-center {
            text-align: center !important;
        }

        .text-bold {
            font-weight: bold !important;
        }

        .text-navy {
            color: #0b3558 !important;
        }

        .muted {
            color: #64748b;
        }

        .lbl {
            color: #64748b;
        }

        /* ---------- Top accent + company header ---------- */
        .accent-navy {
            height: 5pt;
            background-color: #0b3558;
        }

        .accent-gold {
            height: 1.6pt;
            background-color: #d69e2e;
            margin-bottom: 6px;
        }

        .company-header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .company-logo-cell {
            width: 62px;
            vertical-align: middle;
            padding-right: 8px;
        }

        .company-name-cell {
            vertical-align: middle;
            width: 44%;
        }

        .company-title {
            font-size: 16pt;
            font-weight: bold;
            color: #0b3558;
            letter-spacing: 0.6px;
            line-height: 1.05;
            text-transform: uppercase;
        }

        .company-tagline {
            font-size: 7.2pt;
            font-weight: bold;
            color: #b37d14;
            letter-spacing: 0.9px;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .company-subtag {
            font-size: 6.4pt;
            color: #4a5568;
            font-style: italic;
            margin-top: 1px;
        }

        .company-contact-cell {
            text-align: right;
            vertical-align: middle;
            font-size: 6.8pt;
            color: #2d3748;
            line-height: 1.45;
        }

        .header-divider {
            height: 0.8pt;
            background-color: #cbd5e0;
            margin: 7px 0 8px 0;
        }

        /* ---------- Banner (title bar) ---------- */
        .banner-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }

        .banner-title {
            background-color: #0b3558;
            color: #ffffff;
            font-weight: bold;
            font-size: 10pt;
            letter-spacing: 2px;
            padding: 5px 10px;
            text-transform: uppercase;
        }

        .banner-meta {
            background-color: #0b3558;
            color: #f6d58e;
            text-align: right;
            font-size: 7.6pt;
            padding: 5px 10px;
            letter-spacing: 0.3px;
        }

        .banner-meta b {
            color: #ffffff;
        }

        /* ---------- Cards ---------- */
        .two-panel-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }

        .two-panel-table td.col {
            vertical-align: top;
            padding: 0;
        }

        table.card {
            width: 100%;
            border-collapse: collapse;
            border: 0.7pt solid #d5dce6;
        }

        .card-head {
            background-color: #eef2f7;
            color: #0b3558;
            font-weight: bold;
            font-size: 6.8pt;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 3.5px 7px;
            border-bottom: 0.7pt solid #d5dce6;
        }

        .card-body {
            padding: 5px 7px;
            font-size: 7.6pt;
            color: #2d3748;
            line-height: 1.45;
        }

        .vendor-name {
            font-size: 10pt;
            font-weight: bold;
            color: #0b3558;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .product-line {
            margin-top: 4px;
            padding-top: 3px;
            border-top: 0.5pt dashed #cbd5e0;
        }

        table.kv td {
            padding: 3px 7px;
            border-bottom: 0.5pt solid #e5eaf1;
            font-size: 7.6pt;
        }

        table.kv tr.last td {
            border-bottom: none;
        }

        table.kv td.k {
            color: #64748b;
            width: 46%;
            border-right: 0.5pt solid #e5eaf1;
        }

        table.kv td.v {
            font-weight: bold;
            color: #1a202c;
        }

        /* ---------- KPI strip ---------- */
        .kpi-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }

        .kpi {
            border: 0.7pt solid #d5dce6;
            background-color: #f6f8fb;
            padding: 5px 9px;
            width: 24%;
        }

        .kpi-label {
            font-size: 6pt;
            letter-spacing: 0.9px;
            text-transform: uppercase;
            color: #64748b;
        }

        .kpi-value {
            font-size: 12.5pt;
            font-weight: bold;
            color: #0b3558;
            margin-top: 1px;
        }

        .kpi-dark {
            background-color: #0b3558;
            border-color: #0b3558;
        }

        .kpi-dark .kpi-label {
            color: #cbd5e1;
        }

        .kpi-dark .kpi-value {
            color: #f6d58e;
        }

        /* ---------- Data tables ---------- */
        table.data {
            width: 100%;
            border-collapse: collapse;
            border: 0.7pt solid #d5dce6;
        }

        table.data td,
        table.data th {
            padding: 3px 7px;
            font-size: 7.8pt;
            border-bottom: 0.5pt solid #e5eaf1;
            vertical-align: middle;
        }

        table.data tr.head td,
        table.data tr.head th {
            background-color: #0b3558;
            color: #ffffff;
            font-weight: bold;
            font-size: 6.8pt;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 4px 7px;
            border-bottom: none;
            text-align: left;
        }

        table.data tr.alt td {
            background-color: #f8fafc;
        }

        table.data tr.strong td {
            font-weight: bold;
            color: #0b3558;
        }

        table.data tr.subtotal td {
            background-color: #e8eef6;
            font-weight: bold;
            color: #0b3558;
            border-top: 0.9pt solid #0b3558;
            border-bottom: 0.9pt solid #0b3558;
        }

        table.data tr.net td {
            background-color: #0b3558;
            color: #ffffff;
            font-weight: bold;
            font-size: 9pt;
            padding: 5px 7px;
            border-bottom: none;
        }

        table.data tr.net td.amt {
            color: #f6d58e;
            font-size: 10.5pt;
        }

        .qc-pass {
            color: #15803d;
            font-weight: bold;
        }

        /* ---------- Remarks ---------- */
        .remarks-text {
            font-size: 7.4pt;
            color: #0b3558;
            font-weight: bold;
            line-height: 1.4;
        }

        /* ---------- Note section ---------- */
        .note-wrap {
            margin-top: 9px;
        }

        /* ---------- Footer ---------- */
        .footer-divider {
            height: 1pt;
            background-color: #0b3558;
            margin-top: 9px;
        }

        .footer-gold {
            height: 1.2pt;
            background-color: #d69e2e;
            margin-bottom: 5px;
            margin-top: 1.5px;
        }

        .signature-table {
            width: 100%;
            border-collapse: collapse;
        }

        .sign-box {
            width: 190px;
            margin-left: auto;
            border-collapse: collapse;
        }
    </style>
</head>

<body>
    <div class="page">

        <!-- ===== Top accent ===== -->
        <div class="accent-navy"></div>
        <div class="accent-gold"></div>

        <!-- ===== Company header ===== -->
        <table class="company-header-table">
            <tr>
                <td class="company-logo-cell">
                    @if(!empty($company->logo_base64))
                        <img src="{{ $company->logo_base64 }}" style="max-height: 54px; max-width: 62px;" alt="Logo">
                    @endif
                </td>
                <td class="company-name-cell">
                    <div class="company-title">{{ $company->company_name }}</div>
                    @if(!empty($company->tagline))
                        <div class="company-tagline">{{ $company->tagline }}</div>
                    @endif
                    @if(!empty($company->sub_tagline))
                        <div class="company-subtag">{{ $company->sub_tagline }}</div>
                    @endif
                </td>
                <td class="company-contact-cell">
                    <div>{{ $companyAddress }}</div>
                    <div><span class="lbl">GSTIN :</span> <b>{{ $companyGstin }}</b></div>
                    <div><span class="lbl">State :</span> {{ $companyState }} &nbsp;|&nbsp; <span class="lbl">State Code
                            :</span> {{ $companyStateCode }}</div>
                    <div><span class="lbl">Phone :</span> {{ $companyPhone }}
                        @if(!empty($company->email))
                            &nbsp;|&nbsp; <span class="lbl">Email :</span> {{ $company->email }}
                        @endif
                    </div>
                </td>
            </tr>
        </table>

        <div class="header-divider"></div>

        <!-- =========================================================== -->
        <!-- SECTION 1 : SETTLEMENT BILL                                  -->
        <!-- =========================================================== -->
        <table class="banner-table">
            <tr>
                <td class="banner-title">Settlement Bill</td>
                <td class="banner-meta">Bill No : <b>{{ $billNo }}</b> &nbsp;&nbsp;|&nbsp;&nbsp; Date :
                    <b>{{ $billDate }}</b></td>
            </tr>
        </table>

        <!-- Vendor card + Bill details card -->
        <table class="two-panel-table">
            <tr>
                <td class="col" style="width: 49%;">
                    <table class="card">
                        <tr>
                            <td class="card-head">Vendor Details</td>
                        </tr>
                        <tr>
                            <td class="card-body">
                                <div class="vendor-name">{{ $vendorName }}</div>
                                <div>{{ $vendorAddress }}</div>
                                <div>{{ $vendorCity }}</div>
                                <div><span class="lbl">GSTIN :</span> <b>{{ $vendorGstin }}</b></div>
                                @if(!empty($vendorPhone))
                                    <div><span class="lbl">Phone :</span> {{ $vendorPhone }}</div>
                                @endif
                                <div class="product-line"><span class="lbl">Product :</span> <b
                                        class="text-navy">{{ $productName }}</b></div>
                            </td>
                        </tr>
                    </table>
                </td>
                <td style="width: 2%;"></td>
                <td class="col" style="width: 49%;">
                    <table class="card kv">
                        <tr>
                            <td class="card-head" colspan="2">Bill Details</td>
                        </tr>
                        <tr>
                            <td class="k">Lot Number</td>
                            <td class="v">{{ $lotNumber }}</td>
                        </tr>
                        <tr>
                            <td class="k">Vehicle Number</td>
                            <td class="v">{{ $vehicleNumber }}</td>
                        </tr>
                        <tr>
                            <td class="k">Cont. Unit Rate</td>
                            <td class="v">{{ $fmt($settlement->contract_unit_rate) }}</td>
                        </tr>
                        <tr>
                            <td class="k">Final Unit Rate</td>
                            <td class="v">{{ $fmt($settlement->final_unit_rate) }}</td>
                        </tr>
                        <tr>
                            <td class="k">Final Weight (MT)</td>
                            <td class="v">{{ $fmt($settlement->final_weight_mt, 3) }}</td>
                        </tr>
                        <tr>
                            <td class="k">Jute Bag</td>
                            <td class="v">{{ $fmt($settlement->jute_bag, 3) }}</td>
                        </tr>
                        <tr class="last">
                            <td class="k">PP Bag</td>
                            <td class="v">{{ $fmt($settlement->pp_bag, 3) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- KPI strip -->
        <table class="kpi-table">
            <tr>
                <td class="kpi">
                    <div class="kpi-label">Final Weight (MT)</div>
                    <div class="kpi-value">{{ $fmt($settlement->final_weight_mt, 3) }}</div>
                </td>
                <td style="width: 1.33%;"></td>
                <td class="kpi">
                    <div class="kpi-label">Final Unit Rate (&#8377;)</div>
                    <div class="kpi-value">{{ $fmt($settlement->final_unit_rate) }}</div>
                </td>
                <td style="width: 1.33%;"></td>
                <td class="kpi">
                    <div class="kpi-label">Bran Value (&#8377;)</div>
                    <div class="kpi-value">{{ $fmt($settlement->bran_value) }}</div>
                </td>
                <td style="width: 1.33%;"></td>
                <td class="kpi kpi-dark">
                    <div class="kpi-label">Net Payable (&#8377;)</div>
                    <div class="kpi-value">{{ $fmt($settlement->net_payable) }}</div>
                </td>
            </tr>
        </table>

        <!-- QC (left) + Account summary (right) -->
        <table class="two-panel-table">
            <tr>
                <td class="col" style="width: 34%;">
                    <table class="data">
                        <tr class="head">
                            <th colspan="2">QC Test Particulars</th>
                        </tr>
                        <tr>
                            <td style="width: 58%; font-weight: bold;">Oil Content</td>
                            <td class="text-center text-bold {{ $qcClass($settlement->oil_content) }}">
                                {{ $qc($settlement->oil_content, ' %') }}</td>
                        </tr>
                        <tr class="alt">
                            <td>FFA</td>
                            <td class="text-center {{ $qcClass($settlement->ffa) }}">{{ $qc($settlement->ffa) }}</td>
                        </tr>
                        <tr>
                            <td>SS (Sand Silica)</td>
                            <td class="text-center {{ $qcClass($settlement->ss) }}">{{ $qc($settlement->ss) }}</td>
                        </tr>
                        <tr class="alt">
                            <td>Fibre</td>
                            <td class="text-center {{ $qcClass($settlement->fibre) }}">{{ $qc($settlement->fibre) }}
                            </td>
                        </tr>
                        <tr>
                            <td>Moisture</td>
                            <td class="text-center {{ $qcClass($settlement->moisture) }}">
                                {{ $qc($settlement->moisture) }}</td>
                        </tr>
                    </table>

                    @if(!empty($remarksText))
                        <div style="height: 6px;"></div>
                        <table class="card">
                            <tr>
                                <td class="card-head">Remarks</td>
                            </tr>
                            <tr>
                                <td class="card-body remarks-text">{{ $remarksText }}</td>
                            </tr>
                        </table>
                    @endif
                </td>

                <td style="width: 2%;"></td>

                <td class="col" style="width: 64%;">
                    <table class="data">
                        <tr class="head">
                            <td style="width: 62%;">Account Summary</td>
                            <td class="text-right">Amount (&#8377;)</td>
                        </tr>
                        <tr class="strong">
                            <td>Total Value</td>
                            <td class="text-right">{{ $fmt($settlement->bran_value) }}</td>
                        </tr>
                        <tr class="alt">
                            <td>Bag Amount</td>
                            <td class="text-right">{{ $fmt($settlement->bag_amount) }}</td>
                        </tr>
                        <tr class="strong">
                            <td>Bran Value</td>
                            <td class="text-right">{{ $fmt($settlement->bran_value) }}</td>
                        </tr>
                        <tr class="alt">
                            <td>CGST ({{ $fmt($settlement->cgst_rate) }}%)</td>
                            <td class="text-right">{{ $fmt($settlement->cgst_amount) }}</td>
                        </tr>
                        <tr>
                            <td>SGST ({{ $fmt($settlement->sgst_rate) }}%)</td>
                            <td class="text-right">{{ $fmt($settlement->sgst_amount) }}</td>
                        </tr>
                        <tr class="alt">
                            <td>IGST ({{ $fmt($settlement->igst_rate) }}%)</td>
                            <td class="text-right">{{ $fmt($settlement->igst_amount) }}</td>
                        </tr>
                        <tr class="subtotal">
                            <td>Sub Total (Bran Value + GST)</td>
                            <td class="text-right">{{ $fmt($settlement->subtotal_amount) }}</td>
                        </tr>
                        <tr>
                            <td>Loading Charges</td>
                            <td class="text-right">{{ $fmt($settlement->loading_charges) }}</td>
                        </tr>
                        <tr class="alt">
                            <td>Unloading Charges</td>
                            <td class="text-right">{{ $fmt($settlement->unloading_charges) }}</td>
                        </tr>
                        <tr>
                            <td>{{ $tdsLabel }}</td>
                            <td class="text-right">{{ $fmt($settlement->tds) }}</td>
                        </tr>
                        <tr class="alt">
                            <td>Advance Paid</td>
                            <td class="text-right">{{ $fmt($settlement->advance_paid) }}</td>
                        </tr>
                        <tr>
                            <td>Lorry Freight</td>
                            <td class="text-right">{{ $fmt($settlement->lorry_freight) }}</td>
                        </tr>
                        <tr class="alt">
                            <td>Other Charges</td>
                            <td class="text-right">{{ $fmt($settlement->other_charges) }}</td>
                        </tr>
                        <tr class="net">
                            <td>Net Payable</td>
                            <td class="text-right amt">&#8377; {{ $fmt($settlement->net_payable) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        @if(!empty($showNote))
            <!-- =========================================================== -->
            <!-- SECTION 2 : DEBIT / CREDIT NOTE (same single page)           -->
            <!-- =========================================================== -->
            <div class="note-wrap">
                <table class="banner-table">
                    <tr>
                        <td class="banner-title" style="background-color: {{ $noteColor }};">{{ $noteLabel }}</td>
                        <td class="banner-meta" style="background-color: {{ $noteColor }};">{{ $noteDocLb }}
                            <b>{{ $debitNoteData['debit_note_number'] }}</b> &nbsp;&nbsp;|&nbsp;&nbsp; Date :
                            <b>{{ $noteDate }}</b></td>
                    </tr>
                </table>

                <table class="two-panel-table">
                    <tr>
                        <td class="col" style="width: 49%;">
                            <table class="card">
                                <tr>
                                    <td class="card-head">Vendor Details</td>
                                </tr>
                                <tr>
                                    <td class="card-body">
                                        <div class="vendor-name" style="font-size: 9pt;">{{ $vendorName }}</div>
                                        <div>{{ $vendorAddress }}</div>
                                        <div>{{ $vendorCity }}</div>
                                        <div><span class="lbl">GSTIN :</span> <b>{{ $vendorGstin }}</b></div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td style="width: 2%;"></td>
                        <td class="col" style="width: 49%;">
                            <table class="card kv">
                                <tr>
                                    <td class="card-head" colspan="2">Reference Details</td>
                                </tr>
                                <tr>
                                    <td class="k">Invoice No</td>
                                    <td class="v">{{ $debitNoteData['invoice_number'] }}</td>
                                </tr>
                                <tr>
                                    <td class="k">Invoice Date</td>
                                    <td class="v">{{ $noteInvDate }}</td>
                                </tr>
                                <tr class="last">
                                    <td class="k">Lot No</td>
                                    <td class="v">{{ $noteLot }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <!-- Line items -->
                <table class="data" style="margin-bottom: 6px;">
                    <tr class="head" style="background-color: {{ $noteColor }};">
                        <td style="width: 8%; background-color: {{ $noteColor }};" class="text-center">S.No</td>
                        <td style="width: 67%; background-color: {{ $noteColor }};">Particulars</td>
                        <td style="width: 25%; background-color: {{ $noteColor }};" class="text-right">Amount (&#8377;)</td>
                    </tr>
                    <tr>
                        <td class="text-center text-bold">1</td>
                        <td>{{ $noteParticulars }}</td>
                        <td class="text-right text-bold">&#8377; {{ $fmt($debitNoteData['rebate_amount']) }}</td>
                    </tr>
                </table>

                <!-- Summaries -->
                <table class="two-panel-table">
                    <tr>
                        <td class="col" style="width: 49%;">
                            <table class="data">
                                <tr class="head">
                                    <td style="background-color: {{ $noteColor }};">Party Invoice</td>
                                    <td class="text-right" style="background-color: {{ $noteColor }};">Amount (&#8377;)</td>
                                </tr>
                                <tr class="strong">
                                    <td>Party Invoice</td>
                                    <td class="text-right">&#8377; {{ $fmt($debitNoteData['invoice_value']) }}</td>
                                </tr>
                                <tr class="alt">
                                    <td>CGST</td>
                                    <td class="text-right">&#8377; {{ $fmt($debitNoteData['inv_cgst']) }}</td>
                                </tr>
                                <tr>
                                    <td>SGST</td>
                                    <td class="text-right">&#8377; {{ $fmt($debitNoteData['inv_sgst']) }}</td>
                                </tr>
                                <tr class="alt">
                                    <td>IGST</td>
                                    <td class="text-right">&#8377; {{ $fmt($debitNoteData['inv_igst']) }}</td>
                                </tr>
                                <tr class="subtotal">
                                    <td>Total Party Invoice</td>
                                    <td class="text-right">&#8377; {{ $fmt($debitNoteData['inv_total']) }}</td>
                                </tr>
                            </table>
                        </td>
                        <td style="width: 2%;"></td>
                        <td class="col" style="width: 49%;">
                            <table class="data">
                                <tr class="head">
                                    <td style="background-color: {{ $noteColor }};">{{ $noteLabel }}</td>
                                    <td class="text-right" style="background-color: {{ $noteColor }};">Amount (&#8377;)</td>
                                </tr>
                                <tr class="strong">
                                    <td>{{ $noteTotalLb }}</td>
                                    <td class="text-right">&#8377; {{ $fmt($debitNoteData['rebate_amount']) }}</td>
                                </tr>
                                <tr class="alt">
                                    <td>CGST</td>
                                    <td class="text-right">&#8377; {{ $fmt($debitNoteData['rebate_cgst']) }}</td>
                                </tr>
                                <tr>
                                    <td>SGST</td>
                                    <td class="text-right">&#8377; {{ $fmt($debitNoteData['rebate_sgst']) }}</td>
                                </tr>
                                <tr class="alt">
                                    <td>IGST</td>
                                    <td class="text-right">&#8377; {{ $fmt($debitNoteData['rebate_igst']) }}</td>
                                </tr>
                                <tr class="net">
                                    <td style="background-color: {{ $noteColor }};">Total</td>
                                    <td class="text-right amt" style="background-color: {{ $noteColor }};">&#8377;
                                        {{ $fmt($debitNoteData['rebate_total']) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </div>
        @endif

        <!-- ===== Footer / Signature ===== -->
        <div class="footer-divider"></div>
        <div class="footer-gold"></div>

        <table class="signature-table">
            <tr>
                <td style="width: 55%; vertical-align: bottom; font-size: 6.4pt; color: #64748b;">
                    This is a computer-generated document.
                </td>
                <td style="width: 45%; vertical-align: bottom; text-align: right;">
                    <table class="sign-box">
                        @if(!empty($company->signature_base64))
                            <tr>
                                <td style="text-align: center; padding: 0 0 2px 0;">
                                    <img src="{{ $company->signature_base64 }}" style="max-height: 34px; max-width: 150px;"
                                        alt="Signature">
                                </td>
                            </tr>
                        @endif
                        <tr>
                            <td
                                style="border-top: 1.2pt solid #0b3558; padding-top: 3px; font-size: 7.6pt; font-weight: bold; color: #0b3558; text-align: center;">
                                For {{ $company->company_name }}
                            </td>
                        </tr>
                        <tr>
                            <td
                                style="font-size: 6.4pt; letter-spacing: 1px; color: #64748b; text-align: center; text-transform: uppercase;">
                                Authorised Signature
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

    </div>
</body>

</html>