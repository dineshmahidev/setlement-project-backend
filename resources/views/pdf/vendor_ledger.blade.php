<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vendor Ledger - {{ $vendor?->vendor_name ?? $company->company_name }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm 10mm 8mm 10mm;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', 'Helvetica Neue', Arial, sans-serif;
            font-size: 8pt;
            line-height: 1.25;
            color: #1a202c;
            margin: 0;
            padding: 0;
            background-color: #ffffff;
        }
        .container {
            border: 1.5pt solid #0b3558;
            padding: 10px 14px;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            border-bottom: 2pt solid #0b3558;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }
        .company-title {
            font-size: 15pt;
            font-weight: bold;
            color: #0b3558;
            text-transform: uppercase;
            margin: 0;
        }
        .company-sub {
            font-size: 7.5pt;
            color: #4a5568;
            margin-top: 2px;
        }
        .doc-badge {
            text-align: right;
            vertical-align: middle;
        }
        .doc-title {
            font-size: 12pt;
            font-weight: bold;
            color: #0b3558;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .doc-period {
            display: inline-block;
            padding: 2px 8px;
            background-color: #0b3558;
            color: #ffffff;
            font-weight: bold;
            font-size: 7.5pt;
            border-radius: 3px;
            margin-top: 3px;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            background-color: #f8fafc;
            border: 0.5pt solid #cbd5e1;
        }
        .info-table td {
            padding: 4px 6px;
            font-size: 7.5pt;
            border: 0.5pt solid #e2e8f0;
            vertical-align: top;
        }
        .info-label {
            color: #64748b;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 7pt;
        }
        .info-val {
            color: #0f172a;
            font-weight: bold;
            font-size: 8pt;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .items-table th {
            background-color: #0b3558;
            color: #ffffff;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            padding: 5px 6px;
            border: 0.5pt solid #0b3558;
        }
        .items-table td {
            padding: 4px 6px;
            font-size: 7.5pt;
            border: 0.5pt solid #cbd5e1;
        }
        .items-table tr:nth-child(odd) td {
            background-color: #f1f5f9;
        }
        .items-table tr:nth-child(even) td {
            background-color: #ffffff;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row td {
            background-color: #e2e8f0 !important;
            font-weight: bold;
            font-size: 8pt;
            border-top: 1.5pt solid #0b3558;
            border-bottom: 1.5pt solid #0b3558;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 25px;
        }
        .signature-table td {
            width: 50%;
            text-align: center;
            vertical-align: bottom;
            font-size: 7.5pt;
            font-weight: bold;
            color: #334155;
            padding-top: 30px;
            border-top: 1pt dotted #94a3b8;
        }
    </style>
</head>
<body>
<div class="container">
    @php
        $logoBase64 = $company->logo_base64;
    @endphp
    <table class="header-table">
        <tr>
            <td style="vertical-align: middle;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        @if($logoBase64)
                            <td style="width: 58px; vertical-align: middle; padding-right: 10px;">
                                <img src="{{ $logoBase64 }}" style="max-height: 50px; max-width: 58px; object-fit: contain;" alt="Logo">
                            </td>
                        @else
                            <td style="width: 58px; vertical-align: middle; padding-right: 10px;">
                                
                            </td>
                        @endif
                        <td style="vertical-align: middle;">
                            <div class="company-title">{{ $company->company_name }}</div>
                            <div class="company-sub">
                                {{ $company->address ? $company->address.', ' : '' }}{{ $company->city }} {{ $company->state ? '— '.$company->state : '' }} {{ $company->pincode ? '('.$company->pincode.')' : '' }}<br>
                                <strong>GSTIN:</strong> {{ $company->gstin ?: '—' }} | <strong>Phone:</strong> {{ $company->phone ?: '—' }}@if($company->phone_2) / {{ $company->phone_2 }}@endif | <strong>Email:</strong> {{ $company->email ?: '—' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="doc-badge">
                <div class="doc-title">LEDGER STATEMENT</div>
                <div class="doc-period">
                    @if(!empty($fromDate) && !empty($toDate))
                        {{ \Carbon\Carbon::parse($fromDate)->format('d-m-Y') }} to {{ \Carbon\Carbon::parse($toDate)->format('d-m-Y') }}
                    @elseif(!empty($fromDate))
                        From {{ \Carbon\Carbon::parse($fromDate)->format('d-m-Y') }}
                    @elseif(!empty($toDate))
                        Up to {{ \Carbon\Carbon::parse($toDate)->format('d-m-Y') }}
                    @else
                        All Dates
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="info-table">
        <tr>
            <td style="width: 35%;">
                <div class="info-label">Party / Vendor Name</div>
                @if($vendor)
                    <div class="info-val">{{ $vendor->vendor_name }} ({{ $vendor->vendor_code }})</div>
                    @if($vendor->contact_person)<div style="font-size: 6.5pt; color: #64748b;">Attn: {{ $vendor->contact_person }}</div>@endif
                    @if($vendor->phone)<div style="font-size: 6.5pt; color: #64748b;">Mob: {{ $vendor->phone }}@if($vendor->phone_2) / {{ $vendor->phone_2 }}@endif</div>@endif
                @else
                    <div class="info-val">All Vendors (Company Statement)</div>
                @endif
            </td>
            <td style="width: 35%;">
                <div class="info-label">GSTIN / PAN</div>
                <div class="info-val">{{ $vendor ? ($vendor->gstin ?: '—').' / '.($vendor->pan ?: '—') : ($company->gstin ?: '—') }}</div>
            </td>
            <td style="width: 30%;">
                <div class="info-label">Opening Balance</div>
                <div class="info-val">₹ {{ number_format((float) ($vendor->opening_balance ?? 0), 2) }}</div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 30px;" class="text-center">#</th>
                <th style="width: 70px;">Date</th>
                <th style="width: 90px;">Doc No</th>
                <th style="width: 80px;">Type</th>
                <th>Particulars / Narration</th>
                <th style="width: 85px;" class="text-right">Debit (₹)</th>
                <th style="width: 85px;" class="text-right">Credit (₹)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $idx => $e)
                @php
                    $d = (float)$e->debit;
                    $c = (float)$e->credit;
                    $typeLabel = ($d > 0) ? 'DEBIT NOTE' : 'CREDIT NOTE';
                @endphp
                <tr>
                    <td class="text-center">{{ $idx + 1 }}</td>
                    <td>{{ \Carbon\Carbon::parse($e->transaction_date)->format('d-m-Y') }}</td>
                    <td style="font-weight: bold; font-family: monospace;">{{ $e->document_number ?: '—' }}</td>
                    <td><span style="font-size: 6.5pt; text-transform: uppercase; font-weight: bold;">{{ $typeLabel }}</span></td>
                    <td>
                        {{ $e->description ?: '—' }}
                        @if($e->lot_number)<span style="color: #64748b; font-size: 6.5pt;"> (Lot: {{ $e->lot_number }})</span>@endif
                        @if(!$vendor && $e->vendor)<span style="color: #64748b; font-size: 6.5pt; display: block;">Vendor: {{ $e->vendor->vendor_name }} ({{ $e->vendor->vendor_code }})</span>@endif
                    </td>
                    <td class="text-right">{{ $d > 0 ? number_format($d, 2) : '—' }}</td>
                    <td class="text-right">{{ $c > 0 ? number_format($c, 2) : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center" style="padding: 15px; color: #64748b;">
                        No transactions found for the selected period.
                    </td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="5" class="text-right">STATEMENT TOTALS:</td>
                <td class="text-right">₹ {{ number_format((float)$totalDebit, 2) }}</td>
                <td class="text-right">₹ {{ number_format((float)$totalCredit, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <table class="signature-table" style="margin-top: 18px;">
        <tr>
            <td style="vertical-align: bottom;">Prepared By / Verified By</td>
            <td style="vertical-align: bottom;">
                @if(!empty($company->signature_base64))
                    <div style="margin-bottom: 3px; text-align: center;">
                        <img src="{{ $company->signature_base64 }}" style="max-height: 38px; max-width: 140px; display: inline-block;" alt="Signature">
                    </div>
                @endif
                For {{ $company->company_name }}<br><span style="font-size: 6.5pt; font-weight: normal;">({{ $company->authorized_signatory_title ?? 'Authorized Signatory' }})</span>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
