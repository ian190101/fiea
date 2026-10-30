<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#17374d}
.sheet{page-break-after:always}h1{font-size:16px;margin:0 0 8px}
table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border:1px solid #a8bdc9;padding:3px;word-wrap:break-word}
th{background:#e7eff3}.right{text-align:right}
</style></head><body>
@foreach($sheets as $sheet)
<section class="sheet">
@php $snapshot=$sheet['snapshot']; $money=\App\Support\MoneyPresenter::class; @endphp
<h1>Draft Budget {{ $sheet['type'] }} · {{ strtoupper($sheet['stage']) }}</h1>
@if($snapshot['preview_only']??false)<p>BORRADOR COMPARATIVO: Initial todavía no aprobado. No constituye una factura emitida.</p>@endif
<p>{{ $snapshot['metadata']['project_name'] ?? '' }} · {{ $snapshot['metadata']['team_name'] ?? '' }}</p>
<p>{{ $sheet['days'][0] }} – {{ end($sheet['days']) }} · {{ $snapshot['metadata']['starts_on'] ?? '' }} / {{ $snapshot['metadata']['ends_on'] ?? '' }}</p>
<table><thead><tr><th style="width:23%">Description / Descripción</th><th>Unit</th><th>Cost</th>
@foreach($sheet['days'] as $day)<th>{{ $day }}</th>@endforeach
<th>Amount</th><th>Receipt</th></tr></thead><tbody>
@foreach($snapshot['lines'] as $line)
<tr><td>{{ $line['description_en'] ?? '' }}<br>{{ $line['description_es'] ?? '' }}</td>
<td>{{ $line['unit'] ?? '' }}</td>
<td class="right">{{ $money::format((string)$line['unit_cost']) }}</td>
@foreach($sheet['days'] as $day)
@php $cell=collect($line['cells']??[])->firstWhere('date',$day); @endphp
<td class="right">{{ $cell['quantity'] ?? '' }}</td>
@endforeach
<td class="right">{{ $money::format(\App\Support\Decimal::money((string)($line['raw_amount']??'0'))) }}</td>
<td>{{ implode(', ',array_unique(array_filter([$line['receipt_number'] ?? null,...array_column($line['cells'] ?? [],'receipt_number')]))) }}</td></tr>
@endforeach
</tbody></table>
<p>DR {{ $money::format((string)$snapshot['totals']['totalDr']) }} ·
WODR {{ $money::format((string)$snapshot['totals']['totalWodr']) }} ·
TOTAL {{ $money::format((string)$snapshot['totals']['total']) }}</p>
</section>
@endforeach
</body></html>
