<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice?->code ?? 'FIEA Invoice' }}</title>
</head>
<body style="color:#1f2937;font-family:Arial,sans-serif;font-size:14px;line-height:1.55;">
    <div style="max-width:680px;">
        {!! nl2br(e($body)) !!}
    </div>
</body>
</html>
