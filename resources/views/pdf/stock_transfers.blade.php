<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock Transfers Report</title>
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
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            padding: 5px 6px;
            border: 0.5pt solid #0b3558;
        }
        .items-table td {
            padding: 4.5px 6px;
            font-size: 7.5pt;
            border: 0.5pt solid #cbd5e1;
        }
        .items-table tr:nth-child(odd) td {
            background-color: #f8fafc;
        }
        .items-table tr:nth-child(even) td {
            background-color: #ffffff;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row td {
            background-color: #e2e8f0 !important;
            font-weight: bold;
            font-size: 8.5pt;
            border-top: 1.5pt solid #0b3558;
            border-bottom: 1.5pt solid #0b3558;
        }
        .highlight-cell {
            background-color: #dbeafe !important;
            color: #1e40af;
            font-weight: bold;
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
        .status-badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 2px;
            font-size: 6.5pt;
            font-weight: bold;
            background-color: #dcfce7;
            color: #166534;
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
                <div class="doc-title">STOCK TRANSFERS REPORT</div>
                <div class="doc-period">
                    @if(!empty($dateFrom) && !empty($dateTo))
                        {{ \Carbon\Carbon::parse($dateFrom)->format('d-m-Y') }} to {{ \Carbon\Carbon::parse($dateTo)->format('d-m-Y') }}
                    @elseif(!empty($dateFrom))
                        From {{ \Carbon\Carbon::parse($dateFrom)->format('d-m-Y') }}
                    @elseif(!empty($dateTo))
                        Up to {{ \Carbon\Carbon::parse($dateTo)->format('d-m-Y') }}
                    @else
                        All Posted Transfers
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
            <span style="color: #0b3558; font-weight: bold;">All Movements</span>
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
                <div class="kpi-label">TOTAL TRANSFERS</div>
                <div class="kpi-val">{{ count($transfers) }}</div>
                <div style="font-size: 6.5pt; color: #64748b;">Posted Records</div>
            </td>
            <td class="kpi-box" style="width: 25%;">
                <div class="kpi-label">TOTAL QUANTITY TRANSFERRED</div>
                <div class="kpi-val" style="color: #0284c7;">{{ number_format((float)$totQuantity, 3) }}</div>
                <div style="font-size: 6.5pt; color: #64748b;">Cumulative Inter-Godown Qty</div>
            </td>
            <td class="kpi-box" style="width: 25%;">
                <div class="kpi-label">SOURCE GODOWNS</div>
                <div class="kpi-val">{{ count($transfers->pluck('from_branch_id')->filter()->unique()) }}</div>
                <div style="font-size: 6.5pt; color: #64748b;">Transfer Origins</div>
            </td>
            <td class="kpi-box" style="width: 25%;">
                <div class="kpi-label">DESTINATION GODOWNS</div>
                <div class="kpi-val">{{ count($transfers->pluck('to_branch_id')->filter()->unique()) }}</div>
                <div style="font-size: 6.5pt; color: #64748b;">Transfer Destinations</div>
            </td>
        </tr>
    </table>

    {{-- Detailed Transfers Table --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 25px;" class="text-center">#</th>
                <th style="width: 65px;" class="text-center">Date</th>
                <th style="width: 85px;">Transfer No</th>
                <th style="width: 100px;">From Godown</th>
                <th style="width: 100px;">To Godown</th>
                <th>Item / Commodity</th>
                <th style="width: 70px;" class="text-right">Quantity</th>
                <th style="width: 45px;" class="text-center">Unit</th>
                <th style="width: 80px;">Vehicle</th>
                <th style="width: 70px;">Lot No</th>
                <th style="width: 110px;">Remarks</th>
                <th style="width: 50px;" class="text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transfers as $idx => $t)
                @php
                    $unitStr = $t->unit?->code ?: ($t->unit?->name ?: ($t->item?->unit?->code ?: ($t->item?->unit?->name ?: '—')));
                    $vehStr = $t->vehicle?->vehicle_number ?: ($t->vehicle?->number ?: '—');
                @endphp
                <tr>
                    <td class="text-center" style="color: #64748b;">{{ $idx + 1 }}</td>
                    <td class="text-center">{{ $t->date ? \Carbon\Carbon::parse($t->date)->format('d-m-Y') : '—' }}</td>
                    <td style="font-weight: bold; color: #0b3558;">{{ $t->transfer_number }}</td>
                    <td>{{ $t->fromBranch?->name ?: ($t->fromBranch?->branch_name ?: '#'.$t->from_branch_id) }}</td>
                    <td>{{ $t->toBranch?->name ?: ($t->toBranch?->branch_name ?: '#'.$t->to_branch_id) }}</td>
                    <td style="font-weight: 500;">{{ $t->item?->name ?: '#'.$t->item_id }}</td>
                    <td class="text-right" style="font-weight: bold;">{{ number_format((float)$t->quantity, 3) }}</td>
                    <td class="text-center">{{ $unitStr }}</td>
                    <td>{{ $vehStr }}</td>
                    <td>{{ $t->lot_number ?: '—' }}</td>
                    <td style="color: #475569; font-size: 7pt;">{{ $t->remarks ?: '—' }}</td>
                    <td class="text-center">
                        <span class="status-badge">{{ $t->status ?: 'POSTED' }}</span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="text-center" style="padding: 20px; color: #64748b; font-style: italic;">
                        No stock transfers recorded for the selected period / criteria.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if(count($transfers) > 0)
        <tfoot>
            <tr class="total-row">
                <td colspan="6" class="text-right" style="font-weight: bold; text-transform: uppercase;">
                    TOTAL / NET TRANSFERRED ({{ count($transfers) }} Records):
                </td>
                <td class="text-right highlight-cell">{{ number_format((float)$totQuantity, 3) }}</td>
                <td colspan="5"></td>
            </tr>
        </tfoot>
        @endif
    </table>

    <table class="signature-table">
        <tr>
            <td>Prepared By</td>
            <td>Authorized Signatory</td>
        </tr>
    </table>
</div>
</body>
</html>
