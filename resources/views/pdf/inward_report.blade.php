<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inward Stock Receipts Report</title>
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
            font-size: 13pt;
            font-weight: bold;
            color: #0b3558;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .doc-period {
            display: inline-block;
            padding: 2.5px 8px;
            background-color: #0b3558;
            color: #ffffff;
            font-weight: bold;
            font-size: 7.5pt;
            border-radius: 3px;
            margin-top: 4px;
        }
        .filter-meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            background-color: #f8fafc;
            border: 0.5pt solid #cbd5e1;
        }
        .filter-meta-table td {
            padding: 4px 8px;
            font-size: 7pt;
            border: 0.5pt solid #e2e8f0;
        }
        .meta-label {
            color: #64748b;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 6.5pt;
        }
        .meta-val {
            color: #0f172a;
            font-weight: bold;
            font-size: 8pt;
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        .report-table th {
            background-color: #0b3558;
            color: #ffffff;
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
            padding: 5px 6px;
            border: 0.5pt solid #0b3558;
            letter-spacing: 0.3px;
        }
        .report-table td {
            padding: 4px 6px;
            font-size: 7.5pt;
            border: 0.5pt solid #cbd5e1;
            vertical-align: middle;
        }
        .report-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row td {
            background-color: #e2e8f0;
            font-weight: bold;
            font-size: 8pt;
            border-top: 1.5pt solid #0b3558;
            border-bottom: 1.5pt solid #0b3558;
        }
        .footer {
            margin-top: 12px;
            padding-top: 6px;
            border-top: 0.5pt solid #cbd5e1;
            font-size: 6.5pt;
            color: #64748b;
            width: 100%;
        }
    </style>
</head>
<body>
<div class="container">
    @php
        $logoBase64 = $company->logo_base64 ?? null;
        $totQty = 0;
        $totAmt = 0;
        foreach ($entries as $e) {
            $totQty += (float) $e->items->sum('quantity');
            $totAmt += (float) ($e->total_amount ?: 0);
        }
    @endphp

    <table class="header-table">
        <tr>
            <td style="vertical-align: middle;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        @if($logoBase64)
                        <td style="width: 58px; vertical-align: middle; padding-right: 10px;">
                            <img src="{{ $logoBase64 }}" style="max-height: 48px; max-width: 58px; object-fit: contain;" alt="Logo">
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
                <div class="doc-title">INWARD RECEIPTS REPORT</div>
                <div class="doc-period">
                    @if($fromDate && $toDate)
                        PERIOD: {{ \Carbon\Carbon::parse($fromDate)->format('d-m-Y') }} TO {{ \Carbon\Carbon::parse($toDate)->format('d-m-Y') }}
                    @elseif($fromDate)
                        FROM: {{ \Carbon\Carbon::parse($fromDate)->format('d-m-Y') }}
                    @elseif($toDate)
                        TO: {{ \Carbon\Carbon::parse($toDate)->format('d-m-Y') }}
                    @else
                        ALL DATES
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="filter-meta-table">
        <tr>
            <td style="width: 25%;">
                <div class="meta-label">Selected Godown</div>
                <div class="meta-val">{{ $branchName ?: 'All Godowns' }}</div>
            </td>
            <td style="width: 25%;">
                <div class="meta-label">Selected Party</div>
                <div class="meta-val">{{ $partyName ?: 'All Parties' }}</div>
            </td>
            <td style="width: 25%;" class="text-right">
                <div class="meta-label">Total Load Receipts</div>
                <div class="meta-val" style="color: #0b3558;">{{ count($entries) }} Entries</div>
            </td>
            <td style="width: 25%;" class="text-right">
                <div class="meta-label">Cumulative Quantity</div>
                <div class="meta-val" style="color: #047857;">{{ number_format($totQty, 3) }} MT</div>
            </td>
        </tr>
    </table>

    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 28px;" class="text-center">#</th>
                <th style="width: 65px;" class="text-center">Date</th>
                <th style="width: 80px;">Entry No</th>
                <th style="width: 75px;">Bill No</th>
                <th>Supplier / Party</th>
                <th style="width: 110px;">Godown</th>
                <th>Items & Lots Received</th>
                <th style="width: 75px;" class="text-right">Total Wt (MT)</th>
                <th style="width: 80px;" class="text-right">Total Amount (₹)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $idx => $entry)
                @php
                    $eQty = (float) $entry->items->sum('quantity');
                    $itemsDesc = $entry->items->map(function($it) {
                        $nm = $it->item?->name ?: 'Item';
                        $lt = $it->lot_number ? " [{$it->lot_number}]" : '';
                        $q = number_format((float)$it->quantity, 3);
                        return "{$nm}{$lt} ({$q} MT)";
                    })->implode(', ');
                @endphp
                <tr>
                    <td class="text-center">{{ $idx + 1 }}</td>
                    <td class="text-center">{{ \Carbon\Carbon::parse($entry->date)->format('d-m-Y') }}</td>
                    <td style="font-family: monospace; font-weight: bold; color: #0b3558;">{{ $entry->entry_number }}</td>
                    <td style="font-family: monospace; font-weight: 600;">{{ $entry->load_reference ?: ($entry->bill_no ?: '—') }}</td>
                    <td style="font-weight: 600;">{{ $entry->party?->vendor_name ?: '—' }}</td>
                    <td>{{ $entry->branch?->branch_name ?: 'Main Godown' }}</td>
                    <td style="font-size: 7pt; color: #334155;">{{ $itemsDesc ?: '—' }}</td>
                    <td class="text-right font-bold" style="color: #047857;">{{ number_format($eQty, 3) }}</td>
                    <td class="text-right font-bold" style="color: #0b3558;">{{ (float)$entry->total_amount > 0 ? number_format((float)$entry->total_amount, 2) : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center" style="padding: 16px; color: #94a3b8; font-weight: bold;">
                        No inward receipts found matching the selected duration and filters.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if(count($entries) > 0)
        <tfoot>
            <tr class="total-row">
                <td colspan="7" class="text-right" style="padding-right: 12px; font-weight: bold;">TOTAL SUMMARY ({{ count($entries) }} ENTRIES):</td>
                <td class="text-right" style="color: #047857; font-weight: bold;">{{ number_format($totQty, 3) }} MT</td>
                <td class="text-right" style="color: #0b3558; font-weight: bold;">{{ $totAmt > 0 ? '₹ '.number_format($totAmt, 2) : '—' }}</td>
            </tr>
        </tfoot>
        @endif
    </table>

    <table class="footer">
        <tr>
            <td style="width: 50%;">Generated on: {{ date('d-m-Y h:i A') }} | {{ $company->company_name }} Inventory System</td>
            <td style="width: 50%; text-align: right;">Authorized Signatory: _________________________</td>
        </tr>
    </table>
</div>
</body>
</html>
