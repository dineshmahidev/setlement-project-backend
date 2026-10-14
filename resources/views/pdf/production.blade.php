<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Production Batch - {{ $production->batch_number }}</title>
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
        .section-header {
            font-size: 8.5pt;
            font-weight: bold;
            color: #0b3558;
            text-transform: uppercase;
            margin: 8px 0 4px 0;
            letter-spacing: 0.3px;
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
                <div class="doc-title">MIXING / PRODUCTION BATCH</div>
                <div class="doc-status">{{ $production->status }}</div>
            </td>
        </tr>
    </table>

    <table class="info-table">
        <tr>
            <td style="width: 25%;">
                <div class="info-label">Batch No</div>
                <div class="info-val">{{ $production->batch_number }}</div>
            </td>
            <td style="width: 25%;">
                <div class="info-label">Date</div>
                <div class="info-val">{{ \Carbon\Carbon::parse($production->date)->format('d/m/Y') }}</div>
            </td>
            <td style="width: 25%;">
                <div class="info-label">Party / Vendor</div>
                <div class="info-val">{{ $production->party?->vendor_name ?: '—' }}</div>
            </td>
            <td style="width: 25%;">
                <div class="info-label">Destination Godown</div>
                <div class="info-val">{{ $production->destBranch?->branch_name ?: '—' }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="info-label">Source Godown</div>
                <div class="info-val">{{ $production->sourceBranch?->branch_name ?: '—' }}</div>
            </td>
            <td>
                <div class="info-label">Produced Item</div>
                <div class="info-val">{{ $production->outputItem?->name ?: '—' }}</div>
            </td>
            <td>
                <div class="info-label">Produced Lot No</div>
                <div class="info-val">{{ $production->output_lot_number ?: '—' }}</div>
            </td>
            <td>
                <div class="info-label">Status</div>
                <div class="info-val">{{ $production->status }}</div>
            </td>
        </tr>
    </table>

    {{-- 1. INPUT LOTS CONSUMED --}}
    <div class="section-header">1. Input Lots Consumed</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 35%;">Input Lot</th>
                <th style="width: 35%;">Item Name</th>
                <th class="text-right" style="width: 25%;">Consumed Quantity (MT)</th>
            </tr>
        </thead>
        <tbody>
            @php $totalInput = 0; @endphp
            @forelse($production->inputs as $idx => $in)
                @php $totalInput += (float)$in->quantity; @endphp
                <tr>
                    <td class="text-center">{{ $idx + 1 }}</td>
                    <td><strong>{{ $in->lot?->lot_number ?: '#'.$in->lot_id }}</strong></td>
                    <td>{{ $in->item?->name ?: ($in->lot?->item?->name ?: '—') }}</td>
                    <td class="text-right"><strong>{{ number_format((float)$in->quantity, 3) }}</strong></td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center" style="padding: 10px; color: #64748b;">No input lots recorded.</td>
                </tr>
            @endforelse
            <tr class="total-row">
                <td colspan="3" class="text-right">TOTAL INPUT QUANTITY:</td>
                <td class="text-right">{{ number_format($totalInput, 3) }} MT</td>
            </tr>
        </tbody>
    </table>

    {{-- 2. OUTPUT PRODUCED --}}
    <div class="section-header">2. Output Produced</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 45%;">Output Item</th>
                <th style="width: 25%;">Output Lot No</th>
                <th class="text-right" style="width: 25%;">Produced Quantity (MT)</th>
            </tr>
        </thead>
        <tbody>
            @php $totalOutput = 0; @endphp
            @forelse($production->outputs as $idx => $out)
                @php $totalOutput += (float)$out->quantity; @endphp
                <tr>
                    <td class="text-center">{{ $idx + 1 }}</td>
                    <td><strong>{{ $out->item?->name ?: '—' }}</strong></td>
                    <td>{{ $out->lot?->lot_number ?: ($production->output_lot_number ?: '—') }}</td>
                    <td class="text-right"><strong>{{ number_format((float)$out->quantity, 3) }}</strong></td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center" style="padding: 10px; color: #64748b;">No output recorded.</td>
                </tr>
            @endforelse
            <tr class="total-row">
                <td colspan="3" class="text-right">TOTAL OUTPUT QUANTITY:</td>
                <td class="text-right">{{ number_format($totalOutput, 3) }} MT</td>
            </tr>
        </tbody>
    </table>

    @php
        $wastage = $totalInput - $totalOutput;
    @endphp
    @if($wastage != 0)
    <div style="margin-bottom: 10px; padding: 4px 8px; background-color: #f8fafc; border: 0.5pt solid #cbd5e1; font-size: 7.5pt;">
        <strong>Process Variance / Wastage:</strong> {{ number_format($wastage, 3) }} MT
        @if($totalInput > 0)
            ({{ number_format(($wastage / $totalInput) * 100, 2) }}%)
        @endif
    </div>
    @endif

    @if($production->notes)
    <div class="notes-box">
        <strong>Narration / Remarks:</strong> {{ $production->notes }}
    </div>
    @endif

    <table class="signature-table">
        <tr>
            <td>
                Prepared By: {{ $production->creator?->name ?: 'Admin' }}<br>
                <small style="color: #64748b;">Operator Signature</small>
            </td>
            <td>
                Checked By: {{ $production->confirmer?->name ?: 'Manager' }}<br>
                <small style="color: #64748b;">Production Incharge</small>
            </td>
            <td>
                @if(!empty($company->signature_base64))
                    <div style="margin-bottom: 2px;">
                        <img src="{{ $company->signature_base64 }}" style="max-height: 38px; max-width: 140px; display: inline-block;" alt="Signature">
                    </div>
                @endif
                For {{ $company->company_name }}<br>
                <small style="color: #64748b;">{{ $company->authorized_signatory_title ?? 'Authorized Signatory' }}</small>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
