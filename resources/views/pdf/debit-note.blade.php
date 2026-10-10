<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Debit Note {{ $note->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; margin: 0; }
        .page { padding: 18px; }
        .company-header { border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 10px; text-align: center; }
        .company-header h1 { margin: 0 0 4px 0; font-size: 18px; }
        h2.doc-title { text-align: center; font-size: 13px; text-transform: uppercase; border: 1px solid #111; padding: 4px; background: #f3f3f3; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #999; padding: 4px 6px; font-size: 10px; }
        th { background: #f0f0f0; text-align: left; }
        .right { text-align: right; }
        .label { font-weight: bold; }
        .two-col { display: flex; gap: 10px; }
        .two-col > div { flex: 1; }
        .totals { width: 45%; margin-left: auto; }
        .net { font-weight: bold; background: #f7f7f7; }
        .sign-row { display: flex; justify-content: space-between; margin-top: 40px; }
        .sign-box { text-align: center; width: 40%; border-top: 1px solid #111; padding-top: 4px; font-size: 10px; }
        @page { margin: 15mm 12mm; }
    </style>
</head>
<body>
<div class="page">
    <div class="company-header">
        <h1>{{ $settings['company_name'] ?? 'S.V.M.A. AGRO PRODUCTS' }}</h1>
        <div>
            @if(!empty($settings['address'])){{ $settings['address'] }}<br>@endif
            @if(!empty($settings['gstin']))GSTIN: {{ $settings['gstin'] }}@endif
            @if(!empty($settings['cin'])) &nbsp; CIN: {{ $settings['cin'] }}@endif
        </div>
    </div>

    <h2 class="doc-title">Debit Note</h2>

    <div class="two-col">
        <div>
            <table>
                <tr><th colspan="2">Vendor Details</th></tr>
                <tr><td class="label" width="40%">Vendor</td><td>{{ $note->vendor?->name }}</td></tr>
                <tr><td class="label">Address</td><td>{{ $note->vendor?->address }}</td></tr>
                <tr><td class="label">GST</td><td>{{ $note->vendor?->gst_number }}</td></tr>
                <tr><td class="label">Particulars</td><td>{{ $note->particulars }}</td></tr>
            </table>
        </div>
        <div>
            <table>
                <tr><th colspan="2">Note Details</th></tr>
                <tr><td class="label" width="45%">Debit Note No</td><td>{{ $note->number }}</td></tr>
                <tr><td class="label">Date</td><td>{{ $note->date?->format('d-m-Y') }}</td></tr>
                <tr><td class="label">Invoice No</td><td>{{ $note->invoice_number }}</td></tr>
                <tr><td class="label">Invoice Date</td><td>{{ $note->invoice_date?->format('d-m-Y') }}</td></tr>
                <tr><td class="label">Lot Number</td><td>{{ $note->lot_number }}</td></tr>
                <tr><td class="label">Branch</td><td>{{ $note->branch?->name }}</td></tr>
                <tr><td class="label">Status</td><td>{{ $note->status }}</td></tr>
            </table>
        </div>
    </div>

    <table class="totals">
        <tr><td class="label">Rebate</td><td class="right">{{ number_format((float)$note->rebate, 2) }}</td></tr>
        <tr><td class="label">Invoice Value</td><td class="right">{{ number_format((float)$note->invoice_value, 2) }}</td></tr>
        @if($note->gst_type === 'CGST_SGST')
        <tr><td class="label">CGST</td><td class="right">{{ number_format((float)$note->cgst_amount, 2) }}</td></tr>
        <tr><td class="label">SGST</td><td class="right">{{ number_format((float)$note->sgst_amount, 2) }}</td></tr>
        @elseif($note->gst_type === 'IGST')
        <tr><td class="label">IGST</td><td class="right">{{ number_format((float)$note->igst_amount, 2) }}</td></tr>
        @endif
        <tr class="net"><td>Total</td><td class="right">₹ {{ number_format((float)$note->total, 2) }}</td></tr>
    </table>

    <div class="sign-row">
        <div class="sign-box">Prepared By</div>
        <div class="sign-box">
            @if(!empty($settings['signature']))<div>{{ $settings['signature'] }}</div>@endif
            Authorised Signatory
        </div>
    </div>
</div>
</body>
</html>
