<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Debit Note - {{ $debitNote->debit_note_number }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 15mm 15mm 15mm 15mm;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #1f2937;
            margin: 0;
            padding: 0;
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #b91c1c;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .company-title {
            font-size: 18px;
            font-weight: bold;
            color: #111827;
            margin: 0 0 4px 0;
            text-transform: uppercase;
        }
        .company-subtitle {
            font-size: 10px;
            color: #4b5563;
            margin: 0;
        }
        .doc-badge {
            background-color: #b91c1c;
            color: #ffffff;
            font-size: 14px;
            font-weight: bold;
            padding: 6px 14px;
            text-align: center;
            border-radius: 4px;
            display: inline-block;
            letter-spacing: 1px;
        }
        .section-box {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 8px 10px;
            margin-bottom: 10px;
            background-color: #f9fafb;
        }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #991b1b;
            text-transform: uppercase;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 3px;
            margin-bottom: 6px;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        table.data-table th, table.data-table td {
            border: 1px solid #d1d5db;
            padding: 6px 10px;
            text-align: left;
            font-size: 11px;
        }
        table.data-table th {
            background-color: #f3f4f6;
            color: #374151;
            font-weight: 600;
        }
        .text-right {
            text-align: right !important;
        }
        .text-center {
            text-align: center !important;
        }
        .font-bold {
            font-weight: bold;
        }
        .footer-table {
            width: 100%;
            margin-top: 40px;
        }
        .sign-box {
            text-align: center;
            width: 200px;
            border-top: 1px dashed #9ca3af;
            padding-top: 5px;
            margin-top: 45px;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    @php
        $logoBase64 = $company->logo_base64;
    @endphp
    <table class="header-table">
        <tr>
            <td style="width: 65%; vertical-align: top;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        @if($logoBase64)
                        <td style="width: 58px; vertical-align: top; padding-right: 10px;">
                            <img src="{{ $logoBase64 }}" style="max-height: 52px; max-width: 58px; object-fit: contain;" alt="Logo">
                        </td>
                        @endif
                        <td style="vertical-align: top;">
                            <div class="company-title">{{ $company->company_name }}</div>
                            <div class="company-subtitle">
                                {{ collect([$company->address, $company->city, $company->pincode, $company->state])->filter()->implode(', ') }}<br>
                                <strong>GSTIN:</strong> {{ $company->gstin ?: '-' }} | <strong>PAN:</strong> {{ $company->pan ?: '-' }}<br>
                                <strong>Phone:</strong> {{ $company->phone ?: '-' }}@if($company->phone_2) / {{ $company->phone_2 }}@endif | <strong>Email:</strong> {{ $company->email ?: '-' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 35%; text-align: right; vertical-align: top;">
                <div class="doc-badge">{{ strtoupper($debitNote->transaction_type) }} NOTE</div>
                <div style="font-size: 10.5px; margin-top: 6px; color: #4b5563;">
                    <strong>Note No:</strong> <span style="font-size: 12px; color: #111827; font-weight: bold;">{{ $debitNote->debit_note_number }}</span><br>
                    <strong>Date:</strong> {{ date('d-M-Y', strtotime($debitNote->date)) }}<br>
                    <strong>Branch:</strong> {{ $debitNote->branch->branch_name ?? '-' }}
                </div>
            </td>
        </tr>
    </table>

    <!-- Info Grid -->
    <table style="width: 100%; margin-bottom: 12px;" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width: 49%; vertical-align: top;">
                <div class="section-box">
                    <div class="section-title">To (Party Details)</div>
                    <strong>{{ $debitNote->vendor->vendor_name }}</strong><br>
                    @if($debitNote->vendor->business_name) <em>({{ $debitNote->vendor->business_name }})</em><br> @endif
                    {{ $debitNote->vendor->address }}<br>
                    {{ $debitNote->vendor->city }} {{ $debitNote->vendor->pincode ? '- ' . $debitNote->vendor->pincode : '' }}, {{ $debitNote->vendor->state }}<br>
                    <strong>GSTIN:</strong> {{ $debitNote->vendor->gstin ?? 'N/A' }} | <strong>PAN:</strong> {{ $debitNote->vendor->pan ?? 'N/A' }}<br>
                    <strong>Phone:</strong> {{ $debitNote->vendor->phone ?? 'N/A' }}@if(!empty($debitNote->vendor->phone_2)) / {{ $debitNote->vendor->phone_2 }}@endif
                </div>
            </td>
            <td style="width: 2%;"></td>
            <td style="width: 49%; vertical-align: top;">
                <div class="section-box">
                    <div class="section-title">Invoice & Reference Details</div>
                    <table style="width: 100%; font-size: 10.5px;">
                        <tr><td><strong>Original Invoice No:</strong></td><td>{{ $debitNote->invoice_number ?? '-' }}</td></tr>
                        <tr><td><strong>Invoice Date:</strong></td><td>{{ $debitNote->invoice_date ? date('d-M-Y', strtotime($debitNote->invoice_date)) : '-' }}</td></tr>
                        <tr><td><strong>Lot Number:</strong></td><td>{{ $debitNote->lot_number ?? '-' }}</td></tr>
                        <tr><td><strong>Settlement Bill Ref:</strong></td><td>{{ $debitNote->settlement?->settlement_number ?? '-' }}</td></tr>
                        <tr><td><strong>Nature of Reason:</strong></td><td><strong>{{ $debitNote->reason }}</strong></td></tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <!-- Items / Particulars Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 8%;" class="text-center">S.No</th>
                <th style="width: 62%;">Particulars / Description</th>
                <th style="width: 30%;" class="text-right">Amount (Rs.)</th>
            </tr>
        </thead>
        <tbody>
            @if($debitNote->items && count($debitNote->items) > 0)
                @foreach($debitNote->items as $idx => $item)
                <tr>
                    <td class="text-center">{{ $idx + 1 }}</td>
                    <td>{{ $item->particulars }}</td>
                    <td class="text-right font-bold">Rs. {{ number_format($item->amount, 2) }}</td>
                </tr>
                @endforeach
            @else
                <tr>
                    <td class="text-center">1</td>
                    <td>
                        <strong>{{ $debitNote->reason }}</strong> on account of quality test variation / settlement differences
                        @if($debitNote->remarks)<br><span style="color: #6b7280; font-size: 9.5px;">{{ $debitNote->remarks }}</span>@endif
                    </td>
                    <td class="text-right font-bold">Rs. {{ number_format($debitNote->taxable_amount, 2) }}</td>
                </tr>
            @endif
            
            <tr style="border-top: 2px solid #9ca3af; background-color: #f9fafb;">
                <td colspan="2" class="text-right font-bold">Taxable Amount:</td>
                <td class="text-right font-bold">Rs. {{ number_format($debitNote->taxable_amount, 2) }}</td>
            </tr>
            @if($debitNote->cgst_amount > 0 || $debitNote->sgst_amount > 0)
            <tr>
                <td colspan="2" class="text-right">CGST ({{ number_format($debitNote->cgst_rate, 2) }}%):</td>
                <td class="text-right">Rs. {{ number_format($debitNote->cgst_amount, 2) }}</td>
            </tr>
            <tr>
                <td colspan="2" class="text-right">SGST ({{ number_format($debitNote->sgst_rate, 2) }}%):</td>
                <td class="text-right">Rs. {{ number_format($debitNote->sgst_amount, 2) }}</td>
            </tr>
            @endif
            @if($debitNote->igst_amount > 0)
            <tr>
                <td colspan="2" class="text-right">IGST ({{ number_format($debitNote->igst_rate, 2) }}%):</td>
                <td class="text-right">Rs. {{ number_format($debitNote->igst_amount, 2) }}</td>
            </tr>
            @endif
            <tr style="background-color: #111827; color: #ffffff; font-weight: bold; font-size: 12px;">
                <td colspan="2" class="text-right"><strong>TOTAL {{ strtoupper($debitNote->transaction_type) }} NOTE AMOUNT:</strong></td>
                <td class="text-right"><strong>Rs. {{ number_format($debitNote->total_amount, 2) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <div style="font-size: 10px; color: #4b5563; margin-top: 10px;">
        <strong>Accounting Impact:</strong> We have debited/credited your ledger account with <strong>Rs. {{ number_format($debitNote->total_amount, 2) }}</strong> against invoice #{{ $debitNote->invoice_number ?? 'N/A' }}.
    </div>

    <!-- Signatures -->
    <table class="footer-table">
        <tr>
            <td style="width: 50%; vertical-align: bottom; font-size: 9.5px; color: #4b5563;">
                Generated electronically on {{ date('d-M-Y H:i') }}
            </td>
            <td style="width: 50%; text-align: right; vertical-align: bottom;">
                <div style="font-weight: bold; font-size: 10px; margin-bottom: 4px;">
                    For {{ $company->company_name }}
                </div>
                @if(!empty($company->signature_base64))
                    <div style="text-align: right; margin-bottom: 2px;">
                        <img src="{{ $company->signature_base64 }}" style="max-height: 40px; max-width: 150px; display: inline-block;" alt="Signature">
                    </div>
                @else
                    <div style="height: 32px;"></div>
                @endif
                <div class="sign-box" style="margin-left: auto;">
                    {{ $company->authorized_signatory_title ?? 'Authorised Signatory' }}
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
