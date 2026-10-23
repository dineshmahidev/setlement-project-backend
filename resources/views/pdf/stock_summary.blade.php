<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Godown & Party Stock Report</title>
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
        .kpi-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .kpi-box {
            border: 0.5pt solid #cbd5e1;
            background-color: #f8fafc;
            padding: 6px 8px;
            text-align: center;
        }
        .kpi-label {
            font-size: 6.5pt;
            color: #64748b;
            font-weight: bold;
            text-transform: uppercase;
        }
        .kpi-val {
            font-size: 10pt;
            font-weight: bold;
            color: #0b3558;
            margin-top: 2px;
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
            margin-top: 20px;
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
                        @else
                            <td style="width: 58px; vertical-align: middle; padding-right: 10px;">
                                
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
                <div class="doc-title">GODOWN & PARTY STOCK REPORT</div>
                <div class="doc-period">
                    @if(!empty($dateFrom) && !empty($dateTo))
                        {{ \Carbon\Carbon::parse($dateFrom)->format('d-m-Y') }} to {{ \Carbon\Carbon::parse($dateTo)->format('d-m-Y') }}
                    @elseif(!empty($dateFrom))
                        From {{ \Carbon\Carbon::parse($dateFrom)->format('d-m-Y') }}
                    @elseif(!empty($dateTo))
                        Up to {{ \Carbon\Carbon::parse($dateTo)->format('d-m-Y') }}
                    @else
                        Cumulative Total
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="kpi-table">
        <tr>
            <td class="kpi-box" style="width: 25%;">
                <div class="kpi-label">Total Import (Inlet)</div>
                <div class="kpi-val" style="color: #047857;">{{ number_format((float)($summary['total_inlet_mt'] ?? 0), 3) }} MT</div>
                <div style="font-size: 6.5pt; color: #64748b;">{{ (int)($summary['total_inlet_bags'] ?? 0) }} Bags</div>
            </td>
            <td class="kpi-box" style="width: 25%;">
                <div class="kpi-label">Total Export (Outlet)</div>
                <div class="kpi-val" style="color: #b91c1c;">{{ number_format((float)($summary['total_outlet_mt'] ?? 0), 3) }} MT</div>
                <div style="font-size: 6.5pt; color: #64748b;">{{ (int)($summary['total_outlet_bags'] ?? 0) }} Bags</div>
            </td>
            <td class="kpi-box" style="width: 25%;">
                <div class="kpi-label">Remaining Balance</div>
                <div class="kpi-val" style="color: #0b3558;">{{ number_format((float)($summary['net_holding_mt'] ?? 0), 3) }} MT</div>
                <div style="font-size: 6.5pt; color: #64748b;">{{ (int)($summary['net_holding_bags'] ?? 0) }} Bags Balance</div>
            </td>
            <td class="kpi-box" style="width: 25%;">
                <div class="kpi-label">Godowns / Parties</div>
                <div class="kpi-val">{{ (int)($summary['active_godowns'] ?? 0) }} / {{ (int)($summary['active_parties'] ?? 0) }}</div>
                <div style="font-size: 6.5pt; color: #64748b;">Active Stock Accounts</div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 30px;" class="text-center">#</th>
                <th>Godown / Branch</th>
                <th>Vendor / Party</th>
                <th>Item / Commodity</th>
                <th style="width: 75px;" class="text-right">Import (MT)</th>
                <th style="width: 75px;" class="text-right">Export (MT)</th>
                <th style="width: 85px;" class="text-right">Holding Stock</th>
                <th style="width: 65px;" class="text-right">Holding Bags</th>
            </tr>
        </thead>
        <tbody>
            @forelse($parties as $idx => $p)
                <tr>
                    <td class="text-center">{{ $idx + 1 }}</td>
                    <td style="font-weight: bold;">{{ $p['branch_name'] }}</td>
                    <td>
                        {{ $p['vendor_name'] }}
                        @if(!empty($p['vendor_code']))<span style="color: #64748b; font-size: 6.5pt;"> ({{ $p['vendor_code'] }})</span>@endif
                    </td>
                    <td>{{ $p['product_name'] }}</td>
                    <td class="text-right font-bold" style="color: #047857;">{{ number_format((float)$p['inlet_mt'], 3) }}</td>
                    <td class="text-right font-bold" style="color: #b91c1c;">{{ number_format((float)$p['outlet_mt'], 3) }}</td>
                    <td class="text-right font-bold" style="color: #0b3558;">{{ number_format((float)$p['holding_mt'], 3) }} MT</td>
                    <td class="text-right">{{ (int)$p['holding_bags'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center" style="padding: 15px; color: #64748b;">
                        No stock holding data found matching the selected filters.
                    </td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="4" class="text-right">GRAND TOTAL:</td>
                <td class="text-right">{{ number_format((float)($summary['total_inlet_mt'] ?? 0), 3) }}</td>
                <td class="text-right">{{ number_format((float)($summary['total_outlet_mt'] ?? 0), 3) }}</td>
                <td class="text-right">{{ number_format((float)($summary['net_holding_mt'] ?? 0), 3) }} MT</td>
                <td class="text-right">{{ (int)($summary['net_holding_bags'] ?? 0) }} Bags</td>
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
