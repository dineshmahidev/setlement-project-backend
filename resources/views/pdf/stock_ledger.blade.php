<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock Ledger Report</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 6mm 8mm 6mm 8mm;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', 'Helvetica Neue', Arial, sans-serif;
            font-size: 7.5pt;
            line-height: 1.25;
            color: #1a202c;
            margin: 0;
            padding: 0;
            background-color: #ffffff;
        }
        .container {
            border: 1.5pt solid #0b3558;
            padding: 8px 12px;
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
            font-size: 7pt;
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
        .filter-row {
            margin-bottom: 8px;
            font-size: 7.5pt;
            color: #334155;
            background-color: #f8fafc;
            padding: 4px 8px;
            border: 0.5pt solid #cbd5e1;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .items-table th {
            background-color: #0b3558;
            color: #ffffff;
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
            padding: 4px 5px;
            border: 0.5pt solid #0b3558;
        }
        .items-table td {
            padding: 3.5px 5px;
            font-size: 7pt;
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
            font-size: 7.5pt;
            border-top: 1.5pt solid #0b3558;
            border-bottom: 1.5pt solid #0b3558;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .signature-table td {
            width: 50%;
            text-align: center;
            vertical-align: bottom;
            font-size: 7pt;
            font-weight: bold;
            color: #334155;
            padding-top: 25px;
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
                        @endif
                        <td style="vertical-align: middle;">
                            <div class="company-title">{{ $company->company_name }}</div>
                            <div class="company-sub">
                                {{ $company->address ? $company->address.', ' : '' }}{{ $company->city }} {{ $company->state ? '— '.$company->state : '' }} {{ $company->pincode ? '('.$company->pincode.')' : '' }}<br>
                                <strong>GSTIN:</strong> {{ $company->gstin ?: '—' }} | <strong>Phone:</strong> {{ $company->phone ?: '—' }}@if($company->phone_2) / {{ $company->phone_2 }}@endif @if($company->email)| <strong>Email:</strong> {{ $company->email }}@endif
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="doc-badge">
                <div class="doc-title">STOCK INVENTORY LEDGER</div>
                <div class="doc-period">
                    @if(!empty($dateFrom) && !empty($dateTo))
                        {{ \Carbon\Carbon::parse($dateFrom)->format('d-m-Y') }} to {{ \Carbon\Carbon::parse($dateTo)->format('d-m-Y') }}
                    @elseif(!empty($dateFrom))
                        From {{ \Carbon\Carbon::parse($dateFrom)->format('d-m-Y') }}
                    @elseif(!empty($dateTo))
                        Up to {{ \Carbon\Carbon::parse($dateTo)->format('d-m-Y') }}
                    @else
                        All Movement History
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="filter-row">
        <strong>Godown:</strong> {{ $branchName ?: 'All Godowns' }} &nbsp;|&nbsp;
        <strong>Item / Commodity:</strong> {{ $itemName ?: 'All Items' }} &nbsp;|&nbsp;
        <strong>Generated on:</strong> {{ date('d-m-Y H:i') }}
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 25px;" class="text-center">#</th>
                <th style="width: 55px;">Date</th>
                <th style="width: 75px;">Txn #</th>
                <th>Godown</th>
                <th>Item / Commodity</th>
                <th style="width: 55px;">Type</th>
                <th style="width: 55px;" class="text-right">Opening</th>
                <th style="width: 45px;" class="text-right">Stock In</th>
                <th style="width: 45px;" class="text-right">Stock Out</th>
                <th style="width: 45px;" class="text-right">T.In</th>
                <th style="width: 45px;" class="text-right">T.Out</th>
                <th style="width: 45px;" class="text-right">Adj</th>
                <th style="width: 55px;" class="text-right">Closing</th>
                <th style="width: 60px;">Lot #</th>
                <th style="width: 65px;">Vehicle</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $idx => $r)
                <tr>
                    <td class="text-center">{{ $idx + 1 }}</td>
                    <td>{{ \Carbon\Carbon::parse($r['date'])->format('d-m-Y') }}</td>
                    <td style="font-family: monospace; font-weight: bold;">{{ $r['transaction_number'] }}</td>
                    <td>{{ $r['branch'] ?: '—' }}</td>
                    <td>{{ $r['item'] ?: '—' }}</td>
                    <td style="font-size: 6.5pt; text-transform: uppercase;">{{ $r['type'] }}</td>
                    <td class="text-right">{{ number_format((float)($r['opening_balance'] ?? 0), 3) }}</td>
                    <td class="text-right font-bold" style="color: #047857;">{{ (float)($r['stock_in'] ?? 0) > 0 ? number_format((float)$r['stock_in'], 3) : '—' }}</td>
                    <td class="text-right font-bold" style="color: #b91c1c;">{{ (float)($r['stock_out'] ?? 0) > 0 ? number_format((float)$r['stock_out'], 3) : '—' }}</td>
                    <td class="text-right">{{ (float)($r['transfer_in'] ?? 0) > 0 ? number_format((float)$r['transfer_in'], 3) : '—' }}</td>
                    <td class="text-right">{{ (float)($r['transfer_out'] ?? 0) > 0 ? number_format((float)$r['transfer_out'], 3) : '—' }}</td>
                    <td class="text-right">{{ (float)($r['adjustment'] ?? 0) != 0 ? number_format((float)$r['adjustment'], 3) : '—' }}</td>
                    <td class="text-right font-bold" style="color: #0b3558;">{{ number_format((float)($r['closing_balance'] ?? 0), 3) }}</td>
                    <td style="font-family: monospace; font-size: 6.5pt;">{{ $r['lot'] ?: '—' }}</td>
                    <td>{{ $r['vehicle'] ?: '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="15" class="text-center" style="padding: 15px; color: #64748b;">
                        No stock ledger records found for this duration and filters.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="signature-table" style="margin-top: 18px;">
        <tr>
            <td style="vertical-align: bottom;">Godown Incharge / Storekeeper</td>
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
