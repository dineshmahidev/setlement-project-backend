<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settlement Bill & {{ $debitNoteData['note_type_label'] ?? 'DEBIT NOTE' }} - {{ $settlement->settlement_number }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 6mm 8mm 6mm 8mm;
        }
        * {
            box-sizing: border-box;
        }
        html, body {
            margin: 0;
            padding: 0;
            font-family: 'DejaVu Sans', 'Helvetica Neue', Arial, sans-serif;
            font-size: 7pt;
            line-height: 1.2;
            color: #1a202c;
            background-color: #ffffff;
        }
        
        /* Outer Border Margin Container - Exact match to Inward PDF */
        .container {
            border: 1.5pt solid #0b3558;
            padding: 6px 10px;
            background-color: #ffffff;
            page-break-inside: avoid;
        }

        /* Top Company Header */
        .company-header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2px;
        }
        .company-logo-cell {
            width: 55px;
            vertical-align: middle;
            padding-right: 6px;
        }
        .company-name-cell {
            vertical-align: middle;
            padding-left: 4px;
        }
        .company-title {
            font-size: 14pt;
            font-weight: bold;
            color: #0b3558;
            letter-spacing: 0.5px;
            margin: 0;
            line-height: 1.05;
            text-transform: uppercase;
        }
        .company-tagline {
            font-size: 6.8pt;
            font-weight: bold;
            color: #b37d14;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            margin: 1px 0 0 0;
        }
        .company-subtag {
            font-size: 6pt;
            color: #4a5568;
            margin: 0;
            font-style: italic;
        }
        .company-contact-cell {
            text-align: right;
            vertical-align: middle;
            font-size: 6.4pt;
            color: #2d3748;
            line-height: 1.2;
        }
        
        /* Clean Header Divider Line */
        .header-divider {
            height: 1.5pt;
            background-color: #0b3558;
            margin-top: 3px;
            margin-bottom: 4px;
        }

        /* Clean Footer Divider Line */
        .footer-divider {
            height: 1.2pt;
            background-color: #0b3558;
            margin-top: 5px;
            margin-bottom: 4px;
        }

        /* Banner Bars */
        .banner-bar {
            background-color: #0b3558;
            color: #ffffff;
            text-align: center;
            font-weight: bold;
            font-size: 8pt;
            letter-spacing: 1.2px;
            padding: 2px 0;
            text-transform: uppercase;
            margin-bottom: 2.5px;
            border-radius: 1px;
        }
        .banner-line {
            display: inline-block;
            width: 22px;
            height: 1px;
            background-color: #d69e2e;
            vertical-align: middle;
            margin: 0 4px;
        }

        /* Structured Tables */
        table.grid-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2.5px;
        }
        table.grid-table th, table.grid-table td {
            border: 0.5pt solid #cbd5e0;
            padding: 1.8px 4px;
            font-size: 6.9pt;
            vertical-align: middle;
        }
        .bg-light-cream {
            background-color: #fdfbf7;
        }
        .bg-head-cream {
            background-color: #f7eedb;
            font-weight: bold;
            color: #0b3558;
            font-size: 7.1pt;
        }
        .section-label {
            font-size: 7.1pt;
            font-weight: bold;
            color: #0b3558;
            margin-bottom: 1px;
        }
        .vendor-name-lg {
            font-size: 8.4pt;
            font-weight: bold;
            color: #0b3558;
            margin-bottom: 1px;
            text-transform: uppercase;
        }
        .text-right { text-align: right !important; }
        .text-center { text-align: center !important; }
        .text-bold { font-weight: bold !important; }
        .text-navy { color: #0b3558 !important; }

        /* Highlight Net Payable & Note Total */
        .net-payable-row td {
            background-color: #faedcd !important;
            font-weight: bold !important;
            font-size: 7.8pt !important;
            color: #0b3558 !important;
            padding: 2px 4px !important;
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
            font-size: 6.7pt;
            font-weight: bold;
            color: #0b3558;
            margin-top: 2px;
            margin-bottom: 2px;
            background-color: #f8fafc;
            border: 0.5pt solid #cbd5e1;
            padding: 2.5px 6px;
        }
        
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
            border: none;
        }
    </style>
</head>
<body>

<div class="container">

    <!-- Top Company Header -->
    <table class="company-header-table">
        <tr>
            <td class="company-logo-cell">
                @php
                    $logoBase64 = $company->logo_base64;
                @endphp
                @if($logoBase64)
                    <img src="{{ $logoBase64 }}" style="max-height: 50px; max-width: 58px; object-fit: contain; vertical-align: middle;" alt="Logo">
                @else
                    
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
            <td style="width: 50%; vertical-align: top; padding: 2.5px 5px;">
                <div class="section-label">Vendor details</div>
                <div class="vendor-name-lg">{{ $settlement->vendor->vendor_name ?? '-' }}</div>
                <div style="font-size: 6.9pt; color: #2d3748; line-height: 1.25;">
                    {{ $settlement->vendor->address ?? '' }}<br>
                    {{ $settlement->vendor->city ?? '' }} {{ $settlement->vendor->pincode ? ', ' . $settlement->vendor->pincode : '' }}<br>
                    <strong>GSTIN :</strong> <span class="text-bold">{{ $settlement->vendor->gstin ?? '-' }}</span>
                    @if(!empty($settlement->vendor->phone))
                        &nbsp;|&nbsp; <strong>Phone :</strong> {{ $settlement->vendor->phone }}@if(!empty($settlement->vendor->phone_2)) / {{ $settlement->vendor->phone_2 }}@endif
                    @endif
                </div>
                <div style="margin-top: 2.5px; padding-top: 2px; border-top: 0.6pt solid #cbd5e0; font-size: 7.2pt;">
                    <strong>Product &nbsp;&nbsp;&nbsp;&nbsp;</strong> <span class="text-bold text-navy">{{ $settlement->product->product_name ?? '-' }}</span>
                </div>
            </td>

            <!-- Right Key-Value Metrics (50%) -->
            <td style="width: 50%; vertical-align: top; padding: 0;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold; width: 45%;">Lot Number</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">{{ $settlement->lot_number ?: '-' }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">Bill No & Date</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">
                            {{ $settlement->bill_number ?: ($settlement->invoice?->invoice_number ?: '68') }} & {{ $settlement->bill_date ? date('d/m/Y', strtotime($settlement->bill_date)) : ($settlement->invoice?->invoice_date ? date('d/m/Y', strtotime($settlement->invoice->invoice_date)) : date('d/m/Y', strtotime($settlement->settlement_date))) }}
                        </td>
                    </tr>
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">Vehicle Number</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">{{ $settlement->vehicle_number ?: '-' }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">Cont.Unit Rate</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">
                            {{ number_format((float)$settlement->contract_unit_rate, 2) }}
                        </td>
                    </tr>
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">Final Unit Rate</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">{{ number_format((float)$settlement->final_unit_rate, 2) }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">Final Weight (MT)</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">{{ number_format((float)$settlement->final_weight_mt, 3) }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">Jute Bag</td>
                        <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.4px 4px;">{{ number_format((float)$settlement->jute_bag, 3) }}</td>
                    </tr>
                    <tr>
                        <td style="border: none; border-right: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">PP Bag</td>
                        <td style="border: none; padding: 1.4px 4px;">{{ number_format((float)$settlement->pp_bag, 3) }}</td>
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
                            <td style="width: 40%; font-weight: bold; text-align: center;">{{ $settlement->oil_content ? (is_numeric($settlement->oil_content) ? number_format((float)$settlement->oil_content, 2) . ' %' : $settlement->oil_content) : '-' }}</td>
                        </tr>
                        <tr>
                            <td>FFA</td>
                            <td class="text-center">{{ $settlement->ffa ? (is_numeric($settlement->ffa) ? number_format((float)$settlement->ffa, 2) : $settlement->ffa) : '-' }}</td>
                        </tr>
                        <tr>
                            <td>SS (Sand Silica)</td>
                            <td class="text-center">{{ $settlement->ss ? (is_numeric($settlement->ss) ? number_format((float)$settlement->ss, 2) : $settlement->ss) : '-' }}</td>
                        </tr>
                        <tr>
                            <td>Fibre</td>
                            <td class="text-center">{{ $settlement->fibre ? (is_numeric($settlement->fibre) ? number_format((float)$settlement->fibre, 2) : $settlement->fibre) : '-' }}</td>
                        </tr>
                        <tr>
                            <td>Moisture</td>
                            <td class="text-center">{{ $settlement->moisture ? (is_numeric($settlement->moisture) ? number_format((float)$settlement->moisture, 2) : $settlement->moisture) : '-' }}</td>
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

    <!-- Settlement Remarks (Positioned below Net Payable & above Debit/Credit Note) -->
    <div class="remarks-box">
        <strong>Remarks:</strong> {{ $settlement->remarks ?: ($debitNoteData['remarks'] ?: 'Auto-generated ' . ($debitNoteData['note_type_label'] ?? 'Debit Note') . ' posted to vendor ledger') }}
    </div>

    <!-- ========================================================================= -->
    <!-- SECTION 2: DEBIT / CREDIT NOTE (ALWAYS IN SAME SINGLE PDF PAGE)           -->
    <!-- ========================================================================= -->
    <div style="margin-top: 2.5px;">
        <div class="banner-bar">
            <span class="banner-line"></span>
            {{ $debitNoteData['note_type_label'] ?? 'CREDIT NOTE' }}
            <span class="banner-line"></span>
        </div>

        <!-- Debit/Credit Note Header Grid -->
        <table class="grid-table bg-light-cream">
            <tr>
                <!-- Left Vendor Info (50%) -->
                <td style="width: 50%; vertical-align: top; padding: 2.5px 5px;">
                    <div class="section-label">Vendor details</div>
                    <div class="vendor-name-lg">{{ $settlement->vendor->vendor_name ?? '-' }}</div>
                    <div style="font-size: 6.9pt; color: #2d3748; line-height: 1.25;">
                        {{ $settlement->vendor->address ?? '' }}<br>
                        {{ $settlement->vendor->city ?? '' }} {{ $settlement->vendor->pincode ? ', ' . $settlement->vendor->pincode : '' }}<br>
                        <strong>GSTIN :</strong> <span class="text-bold">{{ $settlement->vendor->gstin ?? '-' }}</span>
                    </div>
                </td>

                <!-- Right Note Metadata (50%) -->
                <td style="width: 50%; vertical-align: top; padding: 0;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold; width: 45%;">{{ $debitNoteData['doc_number_label'] ?? 'CreditNote No:' }}</td>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">{{ $debitNoteData['debit_note_number'] }}</td>
                        </tr>
                        <tr>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">Date</td>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">{{ date('d/m/Y', strtotime($debitNoteData['date'])) }}</td>
                        </tr>
                        <tr>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">InvoiceNo</td>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">{{ $debitNoteData['invoice_number'] }}</td>
                        </tr>
                        <tr>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; border-right: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">InvoiceDate</td>
                            <td style="border: none; border-bottom: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">{{ date('d/m/Y', strtotime($debitNoteData['invoice_date'])) }}</td>
                        </tr>
                        <tr>
                            <td style="border: none; border-right: 0.6pt solid #cbd5e0; padding: 1.4px 4px; font-weight: bold;">Lot No</td>
                            <td style="border: none; padding: 1.4px 4px; font-weight: bold;">{{ $debitNoteData['lot_number'] ?: '-' }}</td>
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
                    <th style="width: 25%;" class="text-right">Amount (₹)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-center text-bold">1</td>
                    <td>{{ $debitNoteData['particulars_label'] ?? 'Rebate (Oil Content & Quality Adjustment Difference)' }}</td>
                    <td class="text-right text-bold">₹ {{ number_format((float)$debitNoteData['rebate_amount'], 2) }}</td>
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
                                <td class="text-right text-bold">₹ {{ number_format((float)$debitNoteData['invoice_value'], 2) }}</td>
                            </tr>
                            <tr>
                                <td>CGST</td>
                                <td class="text-right">₹ {{ number_format((float)$debitNoteData['inv_cgst'], 2) }}</td>
                            </tr>
                            <tr>
                                <td>SGST</td>
                                <td class="text-right">₹ {{ number_format((float)$debitNoteData['inv_sgst'], 2) }}</td>
                            </tr>
                            <tr>
                                <td>IGST</td>
                                <td class="text-right">₹ {{ number_format((float)$debitNoteData['inv_igst'], 2) }}</td>
                            </tr>
                            <tr class="bg-light-cream">
                                <td class="text-bold text-navy">Total Party Invoice</td>
                                <td class="text-right text-bold text-navy">₹ {{ number_format((float)$debitNoteData['inv_total'], 2) }}</td>
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
                                <td class="text-right text-bold">₹ {{ number_format((float)$debitNoteData['rebate_amount'], 2) }}</td>
                            </tr>
                            <tr>
                                <td>CGST</td>
                                <td class="text-right">₹ {{ number_format((float)$debitNoteData['rebate_cgst'], 2) }}</td>
                            </tr>
                            <tr>
                                <td>SGST</td>
                                <td class="text-right">₹ {{ number_format((float)$debitNoteData['rebate_sgst'], 2) }}</td>
                            </tr>
                            <tr>
                                <td>IGST</td>
                                <td class="text-right">₹ {{ number_format((float)$debitNoteData['rebate_igst'], 2) }}</td>
                            </tr>
                            <tr class="net-payable-row">
                                <td class="text-bold text-navy">Total</td>
                                <td class="text-right text-bold text-navy">₹ {{ number_format((float)$debitNoteData['rebate_total'], 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <!-- Clean Footer Divider Line -->
    <div class="footer-divider"></div>

    <!-- Footer Signature -->
    <table class="signature-table">
        <tr>
            <td style="width: 45%; vertical-align: bottom; font-size: 7.2pt; font-weight: bold; color: #0b3558; border: none; padding: 0;">
                AUTHORISED SIGNATURE
            </td>
            <td style="width: 55%; text-align: right; vertical-align: bottom; border: none; padding: 0;">
                <table style="width: 175px; margin-left: auto; border-collapse: collapse; border: none;">
                    @if(!empty($company->signature_base64))
                        <tr>
                            <td style="text-align: center; border: none; padding: 0 0 2px 0;">
                                <img src="{{ $company->signature_base64 }}" style="max-height: 32px; max-width: 140px; display: inline-block;" alt="Signature">
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td style="border: none; border-top: 1.2pt solid #0b3558; padding-top: 2px; font-size: 7.2pt; font-weight: bold; color: #0b3558; text-align: center;">
                            For {{ $company->company_name }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

</div>

</body>
</html>
