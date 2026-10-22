<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock Transfer - {{ $transfer->transfer_number }}</title>
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
            padding: 5px 6px;
            border: 0.5pt solid #cbd5e1;
            font-size: 8pt;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-bold { font-weight: bold; }
        .total-row {
            background-color: #f1f5f9;
            font-weight: bold;
            border-top: 1.5pt solid #0b3558;
        }
        .notes-box {
            border: 0.5pt solid #cbd5e1;
            background-color: #f8fafc;
            padding: 6px;
            font-size: 7.5pt;
            margin-bottom: 10px;
            border-radius: 2px;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .signature-table td {
            width: 25%;
            text-align: center;
            font-size: 7.5pt;
            font-weight: bold;
            color: #334155;
            padding-top: 35px;
            border-top: 0.5pt dashed #94a3b8;
        }
    </style>
</head>
<body>
<div class="container">
    <table class="header-table">
        <tr>
            <td style="width: 65%;">
                <div class="company-title">{{ $company->company_name }}</div>
                <div class="company-sub">
                    @if($company->address){{ $company->address }}@endif
                    @if($company->city), {{ $company->city }}@endif
                    @if($company->state) - {{ $company->state }}@endif
                    @if($company->pincode) ({{ $company->pincode }})@endif
                </div>
                <div class="company-sub">
                    @if($company->gstin)<strong>GSTIN:</strong> {{ $company->gstin }} &nbsp;|&nbsp; @endif
                    @if($company->pan)<strong>PAN:</strong> {{ $company->pan }} &nbsp;|&nbsp; @endif
                    @if($company->phone)<strong>Phone:</strong> {{ $company->phone }}@endif
                </div>
            </td>
            <td class="doc-badge">
                <div class="doc-title">STOCK TRANSFER NOTE</div>
                <div style="font-size: 8pt; font-weight: bold; color: #64748b; margin-top: 2px;">INTER-GODOWN CHALLAN</div>
                <span class="doc-status">{{ $transfer->status ?: 'POSTED' }}</span>
            </td>
        </tr>
    </table>

    <table class="info-table">
        <tr>
            <td style="width: 25%;">
                <div class="info-label">Transfer Number</div>
                <div class="info-val" style="color: #0b3558;">{{ $transfer->transfer_number }}</div>
            </td>
            <td style="width: 25%;">
                <div class="info-label">Transfer Date</div>
                <div class="info-val">{{ \Carbon\Carbon::parse($transfer->date)->format('d/m/Y') }}</div>
            </td>
            <td style="width: 25%;">
                <div class="info-label">Vehicle Number</div>
                <div class="info-val">{{ $transfer->vehicle?->vehicle_number ?: ($transfer->vehicle_number ?: '—') }}</div>
            </td>
            <td style="width: 25%;">
                <div class="info-label">Driver Details</div>
                <div class="info-val">{{ $transfer->driver_name ?: '—' }}</div>
                @if($transfer->driver_mobile)<div style="font-size: 6.5pt; color: #64748b;">Mob: {{ $transfer->driver_mobile }}</div>@endif
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="info-label">Dispatched From (Source Godown)</div>
                <div class="info-val" style="font-size: 9pt; color: #b91c1c;">
                    {{ $transfer->fromBranch?->branch_name ?: ('Godown #' . $transfer->from_branch_id) }}
                </div>
                <div style="font-size: 7pt; color: #64748b;">
                    @if($transfer->fromBranch?->branch_code)Code: {{ $transfer->fromBranch->branch_code }} &nbsp;|&nbsp; @endif
                    @if($transfer->fromBranch?->city){{ $transfer->fromBranch->city }}@endif
                </div>
            </td>
            <td colspan="2">
                <div class="info-label">Received At (Destination Godown)</div>
                <div class="info-val" style="font-size: 9pt; color: #047857;">
                    {{ $transfer->toBranch?->branch_name ?: ('Godown #' . $transfer->to_branch_id) }}
                </div>
                <div style="font-size: 7pt; color: #64748b;">
                    @if($transfer->toBranch?->branch_code)Code: {{ $transfer->toBranch->branch_code }} &nbsp;|&nbsp; @endif
                    @if($transfer->toBranch?->city){{ $transfer->toBranch->city }}@endif
                </div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 30px;" class="text-center">#</th>
                <th class="text-left">Item / Commodity</th>
                <th class="text-left" style="width: 140px;">Lot Number</th>
                <th style="width: 90px;" class="text-right">Quantity</th>
                <th style="width: 60px;" class="text-center">Unit</th>
                <th class="text-left">Remarks / LR Reference</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-center">1</td>
                <td>
                    <strong>{{ $transfer->item?->name ?: ('Item #' . $transfer->item_id) }}</strong>
                    @if($transfer->item?->code)<span style="color: #64748b; font-size: 7pt;"> ({{ $transfer->item->code }})</span>@endif
                </td>
                <td class="font-bold" style="font-family: monospace;">{{ $transfer->lot_number ?: '—' }}</td>
                <td class="text-right font-bold" style="font-size: 9pt; color: #0b3558;">
                    {{ number_format((float)$transfer->quantity, 3) }}
                </td>
                <td class="text-center">
                    {{ $transfer->unit?->code ?: ($transfer->unit?->name ?: ($transfer->item?->unit?->code ?: 'MT')) }}
                </td>
                <td>{{ $transfer->remarks ?: '—' }}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="3" class="text-right uppercase">Total Transferred Qty:</td>
                <td class="text-right font-bold" style="font-size: 9pt;">
                    {{ number_format((float)$transfer->quantity, 3) }}
                </td>
                <td class="text-center">
                    {{ $transfer->unit?->code ?: ($transfer->unit?->name ?: ($transfer->item?->unit?->code ?: 'MT')) }}
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    @if($transfer->remarks)
        <div class="notes-box">
            <strong>Notes / Reference:</strong> {{ $transfer->remarks }}
        </div>
    @endif

    <table class="signature-table">
        <tr>
            <td>
                Prepared By<br>
                <span style="font-size: 6.5pt; font-weight: normal; color: #64748b;">
                    {{ $transfer->creator?->name ?: 'Store Keeper' }}
                </span>
            </td>
            <td>
                Dispatched By<br>
                <span style="font-size: 6.5pt; font-weight: normal; color: #64748b;">
                    Source Godown In-Charge
                </span>
            </td>
            <td>
                Transporter / Driver<br>
                <span style="font-size: 6.5pt; font-weight: normal; color: #64748b;">
                    {{ $transfer->driver_name ?: 'Driver Signature' }}
                </span>
            </td>
            <td>
                @if(!empty($company->signature_base64))
                    <div style="margin-bottom: 3px; text-align: center;">
                        <img src="{{ $company->signature_base64 }}" style="max-height: 36px; max-width: 130px; display: inline-block;" alt="Signature">
                    </div>
                @endif
                For {{ $company->company_name }}<br>
                <span style="font-size: 6.5pt; font-weight: normal;">
                    ({{ $company->authorized_signatory_title ?? 'Authorized Signatory' }})
                </span>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
