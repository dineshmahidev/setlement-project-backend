<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Current Items Stock Report</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 6mm 8mm 6mm 8mm;
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
        .kpi-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .kpi-box {
            border: 0.5pt solid #cbd5e1;
            background-color: #f8fafc;
            padding: 5px 8px;
            text-align: center;
        }
        .kpi-label {
            font-size: 6.5pt;
            color: #64748b;
            font-weight: bold;
            text-transform: uppercase;
        }
        .kpi-val {
            font-size: 11pt;
            font-weight: bold;
            color: #0b3558;
            margin-top: 1px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .items-table th {
            background-color: #0b3558;
            color: #ffffff;
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
            padding: 6px 8px;
            border: 0.5pt solid #0b3558;
        }
        .items-table td {
            padding: 5.5px 8px;
            font-size: 8pt;
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
            font-size: 9pt;
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
            font-size: 7.5pt;
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
                <div class="doc-title">CURRENT ITEMS STOCK REPORT</div>
                <div class="doc-period">
                    @if(!empty($dateFrom) && !empty($dateTo))
                        {{ \Carbon\Carbon::parse($dateFrom)->format('d-m-Y') }} to {{ \Carbon\Carbon::parse($dateTo)->format('d-m-Y') }}
                    @elseif(!empty($dateFrom))
                        From {{ \Carbon\Carbon::parse($dateFrom)->format('d-m-Y') }}
                    @elseif(!empty($dateTo))
                        Up to {{ \Carbon\Carbon::parse($dateTo)->format('d-m-Y') }}
                    @else
                        Live Inventory Status
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="filter-row">
        <strong>Duration / Period:</strong> 
        @if(!empty($dateFrom) && !empty($dateTo))
            <span style="color: #0b3558; font-weight: bold;">{{ \Carbon\Carbon::parse($dateFrom)->format('d-m-Y') }} to {{ \Carbon\Carbon::parse($dateTo)->format('d-m-Y') }}</span>
        @elseif(!empty($dateFrom))
            <span style="color: #0b3558; font-weight: bold;">From {{ \Carbon\Carbon::parse($dateFrom)->format('d-m-Y') }}</span>
        @elseif(!empty($dateTo))
            <span style="color: #0b3558; font-weight: bold;">Up to {{ \Carbon\Carbon::parse($dateTo)->format('d-m-Y') }}</span>
        @else
            <span style="color: #0b3558; font-weight: bold;">All Movements (Cumulative)</span>
        @endif
        &nbsp;|&nbsp;
        <strong>Godown:</strong> {{ $branchName ?: 'All Godowns' }} &nbsp;|&nbsp;
        <strong>Item / Commodity:</strong> {{ $itemName ?: 'All Items' }} &nbsp;|&nbsp;
        <strong>Generated on:</strong> {{ date('d-m-Y H:i') }}
    </div>

    {{-- Highlighted Summary Metric Cards --}}
    <table class="kpi-table">
        <tr>
            <td class="kpi-box" style="width: 25%;">
                <div class="kpi-label">TOTAL COMMODITIES</div>
                <div class="kpi-val">{{ count($rows) }}</div>
                <div style="font-size: 6.5pt; color: #64748b;">Active Products Listed</div>
            </td>
            <td class="kpi-box" style="width: 25%;">
                <div class="kpi-label">TOTAL STOCK IN</div>
                <div class="kpi-val" style="color: #047857;">{{ number_format((float)$totIn, 3) }}</div>
                <div style="font-size: 6.5pt; color: #64748b;">Total Received / Added</div>
            </td>
            <td class="kpi-box" style="width: 25%;">
                <div class="kpi-label">TOTAL STOCK OUT</div>
                <div class="kpi-val" style="color: #b91c1c;">{{ number_format((float)$totOut, 3) }}</div>
                <div style="font-size: 6.5pt; color: #64748b;">Total Dispatched / Issued</div>
            </td>
            <td class="kpi-box" style="width: 25%; background-color: #eff6ff; border: 1pt solid #93c5fd;">
                <div class="kpi-label" style="color: #0b3558;">TOTAL AVAILABLE STOCK</div>
                <div class="kpi-val" style="color: #0b3558; font-size: 12pt;">{{ number_format((float)$totAvailable, 3) }}</div>
                <div style="font-size: 6.5pt; color: #1e40af; font-weight: bold;">Net Available Balance</div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 35px;" class="text-center">#</th>
                <th>Item / Commodity</th>
                <th style="width: 120px;" class="text-right">In</th>
                <th style="width: 120px;" class="text-right">Out</th>
                <th style="width: 130px;" class="text-right">Available</th>
                <th style="width: 90px;" class="text-center">Unit</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $idx => $r)
                @php
                    $inQty = (float)($r['total_in'] ?? 0);
                    $outQty = (float)($r['total_out'] ?? 0);
                    $availQty = (float)($r['available'] ?? 0);
                @endphp
                <tr>
                    <td class="text-center">{{ $idx + 1 }}</td>
                    <td style="font-weight: bold; color: #0b3558;">
                        {{ $r['item_name'] ?? ($r['name'] ?? '—') }}
                        @if(!empty($r['item_code']))
                            <span style="font-family: monospace; font-size: 7pt; color: #64748b;">({{ $r['item_code'] }})</span>
                        @endif
                    </td>
                    <td class="text-right font-bold" style="color: #047857;">{{ $inQty > 0 ? number_format($inQty, 3) : '—' }}</td>
                    <td class="text-right font-bold" style="color: #b91c1c;">{{ $outQty > 0 ? number_format($outQty, 3) : '—' }}</td>
                    <td class="text-right font-bold" style="color: #0b3558; background-color: #f0fdf4;">{{ number_format($availQty, 3) }}</td>
                    <td class="text-center" style="font-weight: bold; text-transform: uppercase;">{{ $r['unit'] ?: '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center" style="padding: 18px; color: #64748b;">
                        No stock records found for the selected filters.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if(count($rows) > 0)
            <tfoot>
                <tr class="total-row">
                    <td colspan="2" class="text-right font-bold" style="padding-right: 12px;">TOTAL / POSITION:</td>
                    <td class="text-right font-bold" style="color: #047857;">{{ number_format((float)$totIn, 3) }}</td>
                    <td class="text-right font-bold" style="color: #b91c1c;">{{ number_format((float)$totOut, 3) }}</td>
                    <td class="text-right font-bold" style="color: #0b3558; background-color: #dbeafe !important; font-size: 10pt;">{{ number_format((float)$totAvailable, 3) }}</td>
                    <td class="text-center font-bold" style="color: #475569;">—</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <table class="signature-table" style="margin-top: 20px;">
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
