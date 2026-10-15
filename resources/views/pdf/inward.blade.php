<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inward Stock Receipt - {{ $inward->entry_number }}</title>
    <style>
        @page {
            size: A4 portrait;
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
            font-size: 16pt;
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
            font-size: 13pt;
            font-weight: bold;
            color: #0b3558;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .doc-status {
            display: inline-block;
            padding: 2px 8px;
            background-color: #0b3558;
            color: #ffffff;
            font-weight: bold;
            font-size: 8pt;
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
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row td {
            background-color: #f1f5f9;
            font-weight: bold;
            font-size: 8pt;
            border-top: 1.5pt solid #0b3558;
            border-bottom: 1.5pt solid #0b3558;
        }
        .notes-box {
            border: 0.5pt solid #cbd5e1;
            background-color: #f8fafc;
            padding: 5px 8px;
            margin-bottom: 15px;
            font-size: 7.5pt;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 25px;
        }
        .signature-table td {
            width: 33.33%;
            text-align: center;
            vertical-align: bottom;
            font-size: 7.5pt;
            font-weight: bold;
            color: #334155;
            padding-top: 35px;
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
                                <strong>GSTIN:</strong> {{ $company->gstin ?: '—' }} | <strong>Phone:</strong> {{ $company->phone ?: '—' }}@if($company->phone_2) / {{ $company->phone_2 }}@endif | <strong>Email:</strong> {{ $company->email ?: '—' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="doc-badge">
                <div class="doc-title">INWARD RECEIPT</div>
            </td>
        </tr>
    </table>

    <table class="info-table">
        <tr>
            <td style="width: 25%;">
                <div class="info-label">Inward Entry No</div>
                <div class="info-val">{{ $inward->entry_number }}</div>
            </td>
            <td style="width: 25%;">
                <div class="info-label">Date</div>
                <div class="info-val">{{ \Carbon\Carbon::parse($inward->date)->format('d-m-Y') }}</div>
            </td>
            <td style="width: 25%;">
                <div class="info-label">Godown / Branch</div>
                <div class="info-val">{{ $inward->branch?->branch_name ?: 'Main Branch' }}</div>
            </td>
            <td style="width: 25%;">
                <div class="info-label">Bill No</div>
                <div class="info-val">{{ $inward->load_reference ?: '—' }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="info-label">Supplier / Party</div>
                <div class="info-val">{{ $inward->party?->vendor_name ?: '—' }}</div>
                @if($inward->party?->vendor_code)<div style="font-size: 6.5pt; color: #64748b;">Code: {{ $inward->party->vendor_code }}</div>@endif
                @if($inward->party?->phone)<div style="font-size: 6.5pt; color: #64748b;">Mob: {{ $inward->party->phone }}@if($inward->party?->phone_2) / {{ $inward->party->phone_2 }}@endif</div>@endif
            </td>
            <td>
                <div class="info-label">Vehicle Number</div>
                <div class="info-val">{{ $inward->vehicle_number ?: '—' }}</div>
            </td>
            <td>
                <div class="info-label">Driver Details</div>
                <div class="info-val">{{ $inward->driver_name ?: '—' }}</div>
                @if($inward->driver_mobile)<div style="font-size: 6.5pt; color: #64748b;">Mob: {{ $inward->driver_mobile }}</div>@endif
            </td>
            <td>
                <div class="info-label">Created By</div>
                <div class="info-val">{{ $inward->creator?->name ?: 'Administrator' }}</div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 30px;" class="text-center">#</th>
                <th class="text-left">Item Description</th>
                <th class="text-left">Lot Number</th>
                <th style="width: 60px;" class="text-right">Qty</th>
                <th style="width: 45px;" class="text-center">Unit</th>
                <th style="width: 45px;" class="text-right">Oil %</th>
                <th style="width: 65px;" class="text-right">Rate (₹)</th>
                <th style="width: 65px;" class="text-right">Addl Chg</th>
                <th style="width: 80px;" class="text-right">Amount (₹)</th>
            </tr>
        </thead>
        <tbody>
            @php $totalQty = 0; $totalAmt = 0; @endphp
            @foreach($inward->items as $idx => $it)
                @php
                    $qty = (float)$it->quantity;
                    $amt = (float)$it->amount;
                    $totalQty += $qty;
                    $totalAmt += $amt;
                @endphp
                <tr>
                    <td class="text-center">{{ $idx + 1 }}</td>
                    <td>
                        <strong>{{ $it->item?->name ?: 'Item #'.$it->item_id }}</strong>
                        @if($it->item?->code)<span style="color: #64748b; font-size: 6.5pt;"> ({{ $it->item->code }})</span>@endif
                    </td>
                    <td>{{ $it->lot_number ?: '—' }}</td>
                    <td class="text-right font-bold">{{ number_format($qty, 3) }}</td>
                    <td class="text-center">{{ $it->unit?->unit_name ?: ($it->unit?->symbol ?: 'MT') }}</td>
                    <td class="text-right">{{ $it->oil_pct ? number_format($it->oil_pct, 2).'%' : '—' }}</td>
                    <td class="text-right">{{ $it->rate ? number_format($it->rate, 2) : '—' }}</td>
                    <td class="text-right">{{ $it->additional_charges ? number_format($it->additional_charges, 2) : '—' }}</td>
                    <td class="text-right font-bold">{{ number_format($amt, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="3" class="text-right">TOTAL</td>
                <td class="text-right">{{ number_format($totalQty, 3) }}</td>
                <td colspan="4"></td>
                <td class="text-right">₹ {{ number_format($totalAmt > 0 ? $totalAmt : $inward->total_amount, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    @if($inward->remarks || $inward->notes)
        <div class="notes-box">
            <strong>Narration:</strong> {{ $inward->remarks ?: $inward->notes }}
        </div>
    @endif

    <table class="signature-table" style="margin-top: 18px;">
        <tr>
            <td style="vertical-align: bottom;">Prepared By</td>
            <td style="vertical-align: bottom;">Verified By / Godown Keeper</td>
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
