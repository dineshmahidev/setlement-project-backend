<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settlement Bill & Debit Note - {{ $settlement->settlement_number }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 4mm 6mm 4mm 6mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: 'DejaVu Sans', 'Helvetica Neue', Arial, sans-serif;
            font-size: 7.5pt;
            line-height: 1.22;
            color: #1a202c;
            margin: 0;
            padding: 0;
            background-color: #ffffff;
        }
        
        /* Unified Document Card Container */
        .page-container {
            border: 1.5pt solid #0b3558;
            padding: 5px 8px;
            background-color: #ffffff;
        }

        /* Top Company Header */
        .company-header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2px;
        }
        .company-logo-cell {
            width: 58px;
            vertical-align: middle;
            padding-right: 6px;
        }
        .company-name-cell {
            vertical-align: middle;
            padding-left: 5px;
        }
        .company-title {
            font-size: 15pt;
            font-weight: bold;
            color: #0b3558;
            letter-spacing: 0.5px;
            margin: 0;
            line-height: 1.05;
            text-transform: uppercase;
        }
        .company-tagline {
            font-size: 7pt;
            font-weight: bold;
            color: #b37d14;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin: 1px 0 0 0;
        }
        .company-subtag {
            font-size: 6.2pt;
            color: #4a5568;
            margin: 0;
            font-style: italic;
        }
        .company-contact-cell {
            text-align: right;
            vertical-align: middle;
            font-size: 6.8pt;
            color: #2d3748;
            line-height: 1.25;
        }
        .header-divider {
            height: 1.2pt;
            background-color: #0b3558;
            margin-top: 2px;
            margin-bottom: 3px;
        }

        /* Banner Bars */
        .banner-bar {
            background-color: #0b3558;
            color: #ffffff;
            text-align: center;
            font-weight: bold;
            font-size: 8.8pt;
            letter-spacing: 1.5px;
            padding: 2.2px 0;
            text-transform: uppercase;
            margin-bottom: 3px;
            border-radius: 1.5px;
        }
        .banner-line {
            display: inline-block;
            width: 25px;
            height: 1px;
            background-color: #d69e2e;
            vertical-align: middle;
            margin: 0 4px;
        }

        /* Structured Tables */
        table.grid-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3px;
        }
        table.grid-table th, table.grid-table td {
            border: 0.6pt solid #cbd5e0;
            padding: 1.8px 4px;
            font-size: 7.2pt;
            vertical-align: middle;
        }
        .bg-light-cream {
            background-color: #fdfbf7;
        }
        .bg-head-cream {
            background-color: #f7eedb;
            font-weight: bold;
            color: #0b3558;
            font-size: 7.4pt;
        }
        .section-label {
            font-size: 7.5pt;
            font-weight: bold;
            color: #0b3558;
            margin-bottom: 1px;
        }
        .vendor-name-lg {
            font-size: 8.8pt;
            font-weight: bold;
            color: #0b3558;
            margin-bottom: 1px;
            text-transform: uppercase;
        }
        .text-right { text-align: right !important; }
        .text-center { text-align: center !important; }
        .text-bold { font-weight: bold !important; }
        .text-navy { color: #0b3558 !important; }

        /* Highlight Net Payable */
        .net-payable-row td {
            background-color: #faedcd !important;
            font-weight: bold !important;
            font-size: 8.2pt !important;
            color: #0b3558 !important;
            padding: 2.2px 4px !important;
        }

        /* Dual Column Splits */
        .two-panel-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2px;
        }
        .two-panel-table td {
            vertical-align: top;
            padding: 0;
        }

        .remarks-box {
            font-size: 6.8pt;
            font-weight: bold;
            color: #0b3558;
            margin-top: 2px;
        }
        .signature-box {
            margin-top: 6px;
            display: table;
            width: 100%;
        }
    </style>
</head>
<body>

<div class="page-container">

    <!-- Top Company Header -->
    <table class="company-header-table">
        <tr>
            <td class="company-logo-cell">
                @php
                    $logoBase64 = $company->logo_base64;
                @endphp
                @if($logoBase64)
                    <img src="{{ $logoBase64 }}" style="max-height: 52px; max-width: 58px; object-fit: contain; vertical-align: middle;" alt="Logo">
                @else
                    <svg width="36" height="36" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="50" cy="50" r="48" fill="#fdfbf7" stroke="#b37d14" stroke-width="2.5"/>
                        <path d="M50 15 C50 35 30 45 30 65 C30 75 40 85 50 85 C60 85 70 75 70 65 C70 45 50 35 50 15 Z" fill="#0b3558"/>
                        <path d="M50 20 C50 38 36 48 38 65 C40 73 45 80 50 80 C55 80 60 73 62 65 C64 48 50 38 50 20 Z" fill="#b37d14"/>
                        <path d="M50 28 L50 82" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                        <path d="M42 42 Q50 48 58 42" stroke="#ffffff" stroke-width="2" fill="none"/>
                        <path d="M40 54 Q50 60 60 54" stroke="#ffffff" stroke-width="2" fill="none"/>
                    </svg>
                @endif
            </td>
            <td class="company-name-cell">
                <div class="company-title">{{ $company->company_name }}</div>
                <div class="company-tagline">{{ $company->tagline }}</div>
                <div class="company-subtag">{{ $company->sub_tagline }}</div>
            </td>
            <td class="company-contact-cell">
                <div>{{ collect([$company->address, $company->city, $company->state, $company->pincode])->filter()->implode(', ') }}</div>
                <div><strong>GSTIN :</strong> <span class="text-bold">{{ $company->gstin ?: '-' }}</span></div>
                <div><strong>State :</strong> {{ $company->state ?: '-' }} &nbsp;|&nbsp; <strong>State Code :</strong> {{ $company->state_code ?: '33' }}</div>
                <div><strong>Phone :</strong> {{ $company->phone ?: '-' }}@if($company->phone_2) / {{ $company->phone_2 }}@endif @if($company->email)&nbsp;|&nbsp; <strong>Email :</strong> {{ $company->email }}@endif</div>
            </td>
        </tr>
    </table>

    <div class="header-divider"></div>

    <!-- ========================================================================= -->
    <!-- SECTION 1: SETTLEMENT BILL                                                -->
    <!-- ========================================================================= -->
    <div class="banner-bar">
        <span class="banner-line"></span>
        SETTLEMENT BILL
        <span class="banner-line"></span>
    </div>

    <!-- Vendor Details & Bill Header Grid -->
    <table class="grid-table bg-light-cream">
        <tr>
            <!-- Left Vendor Info (50%) -->
            <td style="width: 50%; vertical-align: top; padding: 3px 5px;">
                <div class="section-label">Vendor details</div>
                <div class="vendor-name-lg">{{ $settlement->vendor->vendor_name ?? '-' }}</div>
                <div style="font-size: 7pt; color: #2d3748; line-height: 1.25;">
                    {{ $settlement->vendor->address ?? '' }}<br>
                    {{ $settlement->vendor->city ?? '' }} {{ $settlement->vendor->pincode ? ', ' . $settlement->vendor->pincode : '' }}<br>
                    <strong>GSTIN :</strong> <span class="text-bold">{{ $settlement->vendor->gstin ?? '-' }}</span>
                    @if(!empty($settlement->vendor->phone))
                        &nbsp;|&nbsp; <strong>Phone :</strong> {{ $settlement->vendor->phone }}@if(!empty($settlement->vendor->phone_2)) / {{ $settlement->vendor->phone_2 }}@endif
                    @endif
                </div>
                <div style="margin-top: 3px; padding-top: 2px; border-top: 0.6pt solid #cbd5e0; font-size: 7.5pt;">
                    <strong>Product &nbsp;&nbsp;&nbsp;&nbsp;</strong> <span class="text-bold text-navy">{{ $settlement->product->product_name ?? '-' }}</span>
                </div>
            </td>

            <!-- Right Key-Value Metrics (50%) -->
            <td style="width: 50%; vertical-align: top; padding: 0;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold; width: 45%;">Lot Number</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">{{ $settlement->lot_number ?: '-' }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">Bill No & Date</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">
                            {{ $settlement->bill_number ?: ($settlement->invoice?->invoice_number ?: '68') }} & {{ $settlement->bill_date ? date('d/m/Y', strtotime($settlement->bill_date)) : ($settlement->invoice?->invoice_date ? date('d/m/Y', strtotime($settlement->invoice->invoice_date)) : date('d/m/Y', strtotime($settlement->settlement_date))) }}
                        </td>
                    </tr>
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">Vehicle Number</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">{{ $settlement->vehicle_number ?: '-' }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">Cont.Unit Rate</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">
                            {{ number_format((float)$settlement->contract_unit_rate, 2) }} {{ $settlement->rate_basis === 'per_10kg' ? 'PP Bag' : ($settlement->rate_basis === 'per_mt' ? 'Per MT' : 'Per Kg') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">Final Unit Rate</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">{{ number_format((float)$settlement->final_unit_rate, 2) }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">Final Weight (MT)</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">{{ number_format((float)$settlement->final_weight_mt, 3) }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">Jute Bag</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.6px 4px;">{{ number_format((float)$settlement->jute_bag, 3) }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; border-right: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">PP Bag</td>
                        <td style="border: none; padding: 1.6px 4px;">{{ number_format((float)$settlement->pp_bag, 3) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Settlement QC Tests (Left 35%) & Financials (Right 65%) -->
    <table class="two-panel-table">
        <tr>
            <!-- Left: QC Test Particulars -->
            <td style="width: 35%; vertical-align: top;">
                <table class="grid-table">
                    <thead>
                        <tr class="bg-head-cream">
                            <th colspan="2">QC Test Particulars</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="width: 60%; font-weight: bold;">Oil Content</td>
                            <td style="width: 40%; font-weight: bold; text-align: center;">{{ $settlement->oil_content ? number_format((float)$settlement->oil_content, 2) . ' %' : '-' }}</td>
                        </tr>
                        <tr>
                            <td>FFA</td>
                            <td class="text-center">{{ $settlement->ffa ? number_format((float)$settlement->ffa, 2) : '-' }}</td>
                        </tr>
                        <tr>
                            <td>SS (Sand Silica)</td>
                            <td class="text-center">{{ $settlement->ss ? number_format((float)$settlement->ss, 2) : '-' }}</td>
                        </tr>
                        <tr>
                            <td>Fibre</td>
                            <td class="text-center">{{ $settlement->fibre ? number_format((float)$settlement->fibre, 2) : '-' }}</td>
                        </tr>
                        <tr>
                            <td>Moisture</td>
                            <td class="text-center">{{ $settlement->moisture ? number_format((float)$settlement->moisture, 2) : '-' }}</td>
                        </tr>
                        <tr>
                            <td>IV</td>
                            <td class="text-center">{{ $settlement->iv ? number_format((float)$settlement->iv, 3) : '-' }}</td>
                        </tr>
                        <tr>
                            <td>Calcium</td>
                            <td class="text-center">{{ $settlement->calcium ? number_format((float)$settlement->calcium, 2) : '0' }}</td>
                        </tr>
                        <tr>
                            <td>Protein</td>
                            <td class="text-center">{{ $settlement->protein ? number_format((float)$settlement->protein, 2) : '0' }}</td>
                        </tr>
                    </tbody>
                </table>
            </td>

            <td style="width: 1.5%;"></td>

            <!-- Right: Financial Accounting Summary -->
            <td style="width: 63.5%; vertical-align: top;">
                <table class="grid-table">
                    <tbody>
                        <tr class="bg-light-cream">
                            <td style="width: 55%; font-weight: bold;">Total Value</td>
                            <td style="width: 45%;" class="text-right text-bold">{{ number_format((float)$settlement->bran_value, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Bag Amount</td>
                            <td class="text-right">{{ number_format((float)$settlement->bag_amount, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Bran Value</td>
                            <td class="text-right text-bold">{{ number_format((float)$settlement->bran_value, 2) }}</td>
                        </tr>
                        <tr>
                            <td>CGST ({{ number_format((float)$settlement->cgst_rate, 2) }}%)</td>
                            <td class="text-right">{{ number_format((float)$settlement->cgst_amount, 2) }}</td>
                        </tr>
                        <tr>
                            <td>SGST ({{ number_format((float)$settlement->sgst_rate, 2) }}%)</td>
                            <td class="text-right">{{ number_format((float)$settlement->sgst_amount, 2) }}</td>
                        </tr>
                        <tr>
                            <td>IGST ({{ number_format((float)$settlement->igst_rate, 2) }}%)</td>
                            <td class="text-right">{{ number_format((float)$settlement->igst_amount, 2) }}</td>
                        </tr>
                        <tr style="background-color: #f1f5f9; font-weight: bold; border-top: 1px solid #cbd5e1; border-bottom: 1px solid #cbd5e1;">
                            <td>Sub Total (Bran Value + GST)</td>
                            <td class="text-right">{{ number_format((float)$settlement->subtotal_amount, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Loading charges</td>
                            <td class="text-right">{{ number_format((float)$settlement->loading_charges, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Unloading Charges</td>
                            <td class="text-right">{{ number_format((float)$settlement->unloading_charges, 2) }}</td>
                        </tr>
                        <tr>
                            <td>TDS Deduction @if(!empty($settlement->tds_rate) && (float)$settlement->tds_rate > 0)({{ number_format((float)$settlement->tds_rate, 2) }}%)@endif</td>
                            <td class="text-right">{{ number_format((float)$settlement->tds, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Advance Paid</td>
                            <td class="text-right">{{ number_format((float)$settlement->advance_paid, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Lorry Freight</td>
                            <td class="text-right">{{ number_format((float)$settlement->lorry_freight, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Other Charges</td>
                            <td class="text-right">{{ number_format((float)$settlement->other_charges, 2) }}</td>
                        </tr>
                        <tr class="net-payable-row">
                            <td class="text-bold text-navy">Net Payable</td>
                            <td class="text-right text-bold text-navy">{{ number_format((float)$settlement->net_payable, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <!-- ========================================================================= -->
    <!-- SECTION 2: DEBIT / CREDIT NOTE (ALWAYS IN SAME SINGLE PDF PAGE)           -->
    <!-- ========================================================================= -->
    <div style="margin-top: 4px;">
        <div class="banner-bar">
            <span class="banner-line"></span>
            {{ $debitNoteData['note_type_label'] ?? 'DEBIT NOTE' }}
            <span class="banner-line"></span>
        </div>

        <!-- Debit/Credit Note Header Grid -->
        <table class="grid-table bg-light-cream">
            <tr>
                <!-- Left Vendor Info (50%) -->
                <td style="width: 50%; vertical-align: top; padding: 3px 5px;">
                    <div class="section-label">Vendor details</div>
                    <div class="vendor-name-lg">{{ $settlement->vendor->vendor_name ?? '-' }}</div>
                    <div style="font-size: 7pt; color: #2d3748; line-height: 1.25;">
                        {{ $settlement->vendor->address ?? '' }}<br>
                        {{ $settlement->vendor->city ?? '' }} {{ $settlement->vendor->pincode ? ', ' . $settlement->vendor->pincode : '' }}<br>
                        <strong>GSTIN :</strong> <span class="text-bold">{{ $settlement->vendor->gstin ?? '-' }}</span>
                    </div>
                </td>

                <!-- Right Note Metadata (50%) -->
                <td style="width: 50%; vertical-align: top; padding: 0;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold; width: 45%;">{{ $debitNoteData['doc_number_label'] ?? 'DebitNote No:' }}</td>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">{{ $debitNoteData['debit_note_number'] }}</td>
                        </tr>
                        <tr>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">Date</td>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">{{ date('d/m/Y', strtotime($debitNoteData['date'])) }}</td>
                        </tr>
                        <tr>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">InvoiceNo</td>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">{{ $debitNoteData['invoice_number'] }}</td>
                        </tr>
                        <tr>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">InvoiceDate</td>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">{{ date('d/m/Y', strtotime($debitNoteData['invoice_date'])) }}</td>
                        </tr>
                        <tr>
                            <td style="border: none; border-right: 0.6pt solid #cbd5e0; padding: 1.6px 4px; font-weight: bold;">Lot No</td>
                            <td style="border: none; padding: 1.6px 4px; font-weight: bold;">{{ $debitNoteData['lot_number'] ?: '-' }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Note Line Items Table -->
        <table class="grid-table">
            <thead>
                <tr class="bg-head-cream">
                    <th style="width: 8%;" class="text-center">S.No</th>
                    <th style="width: 67%;">Particulars</th>
                    <th style="width: 25%;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-center text-bold">1</td>
                    <td>{{ $debitNoteData['particulars_label'] ?? 'Rebate (Oil Content & Quality Adjustment Difference)' }}</td>
                    <td class="text-right text-bold">{{ number_format((float)$debitNoteData['rebate_amount'], 2) }}</td>
                </tr>
            </tbody>
        </table>

        <!-- Note Financial Summary (Dual Column Split) -->
        <table class="two-panel-table">
            <tr>
                <!-- Left Summary (Invoice Breakdown) -->
                <td style="width: 49%; vertical-align: top;">
                    <table class="grid-table">
                        <tbody>
                            <tr>
                                <td style="width: 55%; font-weight: bold;">Party Invoice</td>
                                <td class="text-right text-bold">{{ number_format((float)$debitNoteData['invoice_value'], 2) }}</td>
                            </tr>
                            <tr>
                                <td>CGST</td>
                                <td class="text-right">{{ number_format((float)$debitNoteData['inv_cgst'], 2) }}</td>
                            </tr>
                            <tr>
                                <td>SGST</td>
                                <td class="text-right">{{ number_format((float)$debitNoteData['inv_sgst'], 2) }}</td>
                            </tr>
                            <tr>
                                <td>IGST</td>
                                <td class="text-right">{{ number_format((float)$debitNoteData['inv_igst'], 2) }}</td>
                            </tr>
                            <tr class="bg-light-cream">
                                <td class="text-bold text-navy">Total Party Invoice</td>
                                <td class="text-right text-bold text-navy">{{ number_format((float)$debitNoteData['inv_total'], 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </td>

                <td style="width: 2%;"></td>

                <!-- Right Summary (Note Tax Summary) -->
                <td style="width: 49%; vertical-align: top;">
                    <table class="grid-table">
                        <tbody>
                            <tr class="bg-light-cream">
                                <td style="width: 55%;" class="text-bold">{{ ($debitNoteData['transaction_type'] ?? 'debit') === 'credit' ? 'Total (Credit)' : 'Total (Debit)' }}</td>
                                <td class="text-right text-bold">{{ number_format((float)$debitNoteData['rebate_amount'], 2) }}</td>
                            </tr>
                            <tr>
                                <td>CGST</td>
                                <td class="text-right">{{ number_format((float)$debitNoteData['rebate_cgst'], 2) }}</td>
                            </tr>
                            <tr>
                                <td>SGST</td>
                                <td class="text-right">{{ number_format((float)$debitNoteData['rebate_sgst'], 2) }}</td>
                            </tr>
                            <tr>
                                <td>IGST</td>
                                <td class="text-right">{{ number_format((float)$debitNoteData['rebate_igst'], 2) }}</td>
                            </tr>
                            <tr class="net-payable-row">
                                <td class="text-bold text-navy">Total</td>
                                <td class="text-right text-bold text-navy">{{ number_format((float)$debitNoteData['rebate_total'], 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </table>

        <div class="remarks-box">
            <strong>Remarks:</strong> {{ $debitNoteData['remarks'] ?: 'Auto-generated ' . ($debitNoteData['note_type_label'] ?? 'Debit Note') . ' posted to vendor ledger' }}
        </div>
    </div>

    <!-- Footer Signature -->
    <div class="signature-box" style="margin-top: 10px;">
        <div style="display: table-cell; width: 45%; font-size: 7.5pt; font-weight: bold; color: #0b3558; vertical-align: bottom;">
            AUTHORISED SIGNATURE
        </div>
        <div style="display: table-cell; width: 55%; text-align: right; vertical-align: bottom;">
            <div style="display: inline-block; width: 180px; text-align: center;">
                @if(!empty($company->signature_base64))
                    <div style="text-align: center; margin-bottom: 2px;">
                        <img src="{{ $company->signature_base64 }}" style="max-height: 38px; max-width: 150px; display: inline-block;" alt="Signature">
                    </div>
                @endif
                <div style="border-top: 1.2pt solid #0b3558; padding-top: 2px; font-size: 7.5pt; font-weight: bold; color: #0b3558;">
                    For {{ $company->company_name }}
                </div>
            </div>
        </div>
    </div>

</div>

</body>
</html>
