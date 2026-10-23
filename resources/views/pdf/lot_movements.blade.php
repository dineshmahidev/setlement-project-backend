<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Lot Statement - {{ $lot->lot_number }}</title>
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
        .lot-info-row {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .lot-info-row td {
            border: 0.5pt solid #cbd5e1;
            background-color: #f8fafc;
            padding: 4px 8px;
            font-size: 7.5pt;
        }
        .lot-info-label {
            font-size: 6.5pt;
            color: #64748b;
            font-weight: bold;
            text-transform: uppercase;
            display: block;
        }
        .lot-info-val {
            font-size: 9pt;
            font-weight: bold;
            color: #0b3558;
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
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            padding: 6px 6px;
            border: 0.5pt solid #0b3558;
        }
        .items-table td {
            padding: 5px 6px;
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
        .in-color { color: #047857; font-weight: bold; }
        .out-color { color: #b91c1c; font-weight: bold; }
        .total-row td {
            background-color: #e2e8f0 !important;
            font-weight: bold;
            font-size: 8.5pt;
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
                <div class="doc-title">LOT STATEMENT</div>
                <div class="doc-period">
                    @if(!empty($dateFrom) && !empty($dateTo))
                        {{ \Carbon\Carbon::parse($dateFrom)->format('d-m-Y') }} to {{ \Carbon\Carbon::parse($dateTo)->format('d-m-Y') }}
                    @elseif(!empty($dateFrom))
                        From {{ \Carbon\Carbon::parse($dateFrom)->format('d-m-Y') }}
                    @elseif(!empty($dateTo))
                        Up to {{ \Carbon\Carbon::parse($dateTo)->format('d-m-Y') }}
                    @else
                        Full History
                    @endif
                </div>
            </td>
        </tr>
    </table>

    {{-- Lot identification strip --}}
    <table class="lot-info-row">
        <tr>
            <td style="width: 16%;">
                <span class="lot-info-label">Lot Number</span>
                <span class="lot-info-val" style="font-family: monospace;">{{ $lot->lot_number }}</span>
            </td>
            <td style="width: 24%;">
                <span class="lot-info-label">Item / Commodity</span>
                <span class="lot-info-val">{{ $lot->item?->name ?: '—' }}@if($lot->item?->code) <span style="font-family: monospace; font-size: 7pt; color: #64748b;">({{ $lot->item->code }})</span>@endif</span>
            </td>
            <td style="width: 18%;">
                <span class="lot-info-label">Godown</span>
                <span class="lot-info-val">{{ $lot->branch?->branch_name ?: '—' }}</span>
            </td>
            <td style="width: 12%;">
                <span class="lot-info-label">Oil %</span>
                <span class="lot-info-val">{{ $lot->oil_pct != null ? number_format((float)$lot->oil_pct, 2).'%' : '—' }}</span>
            </td>
            <td style="width: 15%;">
                <span class="lot-info-label">Unit Cost</span>
                <span class="lot-info-val">₹{{ number_format((float)$lot->unit_cost, 2) }}</span>
            </td>
            <td style="width: 15%;">
                <span class="lot-info-label">Current Status</span>
                <span class="lot-info-val" style="color: {{ $lot->status === 'OPEN' ? '#047857' : '#b91c1c' }};">{{ $lot->status }}</span>
            </td>
        </tr>
    </table>

    {{-- Summary KPI cards --}}
    <table class="kpi-table">
        <tr>
            <td class="kpi-box" style="width: 20%;">
                <div class="kpi-label">OPENING BALANCE</div>
                <div class="kpi-val">{{ number_format((float)$opening, 3) }}</div>
                <div style="font-size: 6.5pt; color: #64748b;">{{ $dateFrom ? 'As on '.\Carbon\Carbon::parse($dateFrom)->subDay()->format('d-m-Y') : 'All movements included' }}</div>
            </td>
            <td class="kpi-box" style="width: 20%;">
                <div class="kpi-label">STOCK IN (PERIOD)</div>
                <div class="kpi-val" style="color: #047857;">{{ number_format((float)$totIn, 3) }}</div>
                <div style="font-size: 6.5pt; color: #64748b;">{{ $rows->count() ? $rows->where('direction', 'IN')->count().' entries' : 'No entries' }}</div>
            </td>
            <td class="kpi-box" style="width: 20%;">
                <div class="kpi-label">STOCK OUT (PERIOD)</div>
                <div class="kpi-val" style="color: #b91c1c;">{{ number_format((float)$totOut, 3) }}</div>
                <div style="font-size: 6.5pt; color: #64748b;">{{ $rows->count() ? $rows->where('direction', 'OUT')->count().' entries' : 'No entries' }}</div>
            </td>
            <td class="kpi-box" style="width: 20%; background-color: #f0fdf4; border: 1pt solid #86efac;">
                <div class="kpi-label" style="color: #047857;">CLOSING BALANCE</div>
                <div class="kpi-val" style="color: #047857; font-size: 12pt;">{{ number_format((float)$closing, 3) }}</div>
                <div style="font-size: 6.5pt; color: #047857; font-weight: bold;">Stock as on period end</div>
            </td>
            <td class="kpi-box" style="width: 20%; background-color: #fffbeb; border: 1pt solid #fcd34d;">
                <div class="kpi-label" style="color: #b37d14;">LOT STOCK VALUE</div>
                <div class="kpi-val" style="color: #b37d14; font-size: 12pt;">{{ number_format((float)$lot->stock_value, 2) }}</div>
                <div style="font-size: 6.5pt; color: #92400e; font-weight: bold;">Live valuation</div>
            </td>
        </tr>
    </table>

    {{-- Entry particulars (movement rows) --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 28px;" class="text-center">#</th>
                <th style="width: 75px;">Date</th>
                <th style="width: 90px;">Type</th>
                <th style="width: 45px;" class="text-center">Dir</th>
                <th style="width: 80px;" class="text-right">In Qty</th>
                <th style="width: 80px;" class="text-right">Out Qty</th>
                <th style="width: 85px;" class="text-right">Balance</th>
                <th style="width: 75px;" class="text-right">Rate</th>
                <th style="width: 85px;" class="text-right">Amount</th>
                <th>Party / Customer</th>
                <th style="width: 110px;">Reference</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $idx => $r)
                <tr>
                    <td class="text-center">{{ $idx + 1 }}</td>
                    <td style="font-weight: bold;">{{ \Carbon\Carbon::parse($r['date'])->format('d-m-Y') }}</td>
                    <td style="font-weight: bold; color: #0b3558;">{{ $r['type'] }}</td>
                    <td class="text-center">
                        <span style="font-weight: bold; padding: 1px 5px; border-radius: 2px; {{ $r['direction'] === 'IN' ? 'background-color: #dcfce7; color: #047857;' : 'background-color: #fee2e2; color: #b91c1c;' }}">
                            {{ $r['direction'] }}
                        </span>
                    </td>
                    <td class="text-right in-color">{{ $r['in_qty'] > 0 ? number_format($r['in_qty'], 3) : '—' }}</td>
                    <td class="text-right out-color">{{ $r['out_qty'] > 0 ? number_format($r['out_qty'], 3) : '—' }}</td>
                    <td class="text-right font-bold" style="color: #0b3558; background-color: #f0fdf4;">{{ number_format($r['balance'], 3) }}</td>
                    <td class="text-right">{{ $r['rate'] != null ? number_format((float)$r['rate'], 2) : '—' }}</td>
                    <td class="text-right">{{ $r['amount'] != null ? number_format((float)$r['amount'], 2) : '—' }}</td>
                    <td>{{ $r['party_name'] ?: '—' }}</td>
                    <td style="font-family: monospace; font-size: 7pt;">{{ $r['source_reference'] ?: $r['reference_type'] ?: '—' }}</td>
                    <td style="font-size: 6.5pt; color: #475569;">{{ \Illuminate\Support\Str::limit($r['remarks'] ?? '', 60) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="text-center" style="padding: 18px; color: #64748b;">
                        No stock movements recorded for this lot{{ ($dateFrom || $dateTo) ? ' in the selected duration' : '' }}.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if($rows->count() > 0)
            <tfoot>
                <tr class="total-row">
                    <td colspan="4" class="text-right font-bold" style="padding-right: 12px;">PERIOD TOTALS ({{ $rows->count() }} entries):</td>
                    <td class="text-right font-bold" style="color: #047857;">{{ number_format((float)$totIn, 3) }}</td>
                    <td class="text-right font-bold" style="color: #b91c1c;">{{ number_format((float)$totOut, 3) }}</td>
                    <td class="text-right font-bold" style="color: #0b3558; background-color: #dbeafe !important; font-size: 9.5pt;">{{ number_format((float)$closing, 3) }}</td>
                    <td colspan="5"></td>
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
