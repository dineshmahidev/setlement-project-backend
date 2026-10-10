<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; margin: 0; }
        .page { padding: 16px; }
        h1 { font-size: 15px; text-align: center; border-bottom: 2px solid #111; padding-bottom: 6px; }
        .meta { text-align: center; font-size: 9px; color: #555; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 5px; font-size: 9px; word-break: break-word; }
        th { background: #f0f0f0; text-align: left; position: sticky; top: 0; }
        tr:nth-child(even) td { background: #fafafa; }
        @page { margin: 12mm; }
    </style>
</head>
<body>
<div class="page">
    <h1>{{ $title }}</h1>
    <div class="meta">
        {{ $settings['company_name'] ?? 'LAXMI TRADERS' }}<br>
        Generated: {{ $generatedAt }} &nbsp;|&nbsp; Rows: {{ count($rows) }}
    </div>

    @if(count($rows))
    <table>
        <thead>
            <tr>
                @foreach(array_keys((array)$rows[0]) as $h)
                    <th>{{ $h }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    @foreach((array)$row as $cell)
                        <td>{{ is_scalar($cell) || $cell === null ? $cell : json_encode($cell) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
    @else
        <p style="text-align:center">No data found.</p>
    @endif
</div>
</body>
</html>
