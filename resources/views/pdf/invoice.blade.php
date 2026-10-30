<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#193247}
header{border-bottom:2px solid #1d5a77;padding-bottom:8px;margin-bottom:12px}
h1{font-size:20px;margin:0} h2{font-size:13px;margin-top:18px}
table{border-collapse:collapse;width:100%;margin-top:10px}th,td{border:1px solid #b8c8d2;padding:4px;text-align:left}
th{background:#e8f1f5} .money{text-align:right;white-space:nowrap}.muted{color:#536b77}
.page-break{page-break-before:always}
</style></head><body>
@php $money=\App\Support\MoneyPresenter::class; $totals=$snapshot['totals']; @endphp
<header><h1>{{ $snapshot['type'] }} {{ strtoupper($snapshot['stage']) }} INVOICE</h1>
<p>{{ $invoice->code }} @if(!in_array($invoice->status,['approved','sent','paid'],true)) — BORRADOR @endif</p></header>
<p><strong>Project / Proyecto:</strong> {{ $snapshot['metadata']['project_name'] ?? '' }}</p>
<p><strong>Team / Equipo:</strong> {{ $snapshot['metadata']['team_name'] ?? '' }}</p>
<p><strong>Dates / Fechas:</strong> {{ $snapshot['metadata']['starts_on'] ?? '' }} – {{ $snapshot['metadata']['ends_on'] ?? '' }}</p>
<p><strong>Contact:</strong> {{ $snapshot['metadata']['invoice_name'] ?? '' }} · {{ $snapshot['metadata']['email'] ?? '' }}</p>
<p><strong>Address / Dirección:</strong> {{ $snapshot['metadata']['address'] ?? '' }} · {{ $snapshot['metadata']['phone'] ?? '' }}</p>
<p><strong>Code / Código:</strong> {{ $snapshot['metadata']['project_code'] ?? '' }} · <strong>Date / Fecha:</strong> {{ $snapshot['metadata']['document_date'] ?? '' }}</p>
<h2>Financial detail / Detalle financiero</h2>
<table><thead><tr><th>Description / Descripción</th><th>Unit</th><th>Qty</th><th>Unit cost</th><th>Receipt</th><th>Amount</th></tr></thead><tbody>
@foreach($snapshot['lines'] as $line)
<tr><td>{{ $line['description_en'] ?? '' }} / {{ $line['description_es'] ?? '' }}</td>
<td>{{ $line['unit'] ?? '' }}</td><td>{{ $line['quantity'] ?? \App\Support\Decimal::quantity(\App\Support\Decimal::sum(array_column($line['cells'] ?? [],'quantity'))) }}</td>
<td class="money">{{ $money::format((string)($line['unit_cost'] ?? '0')) }}</td>
<td>{{ $line['receipt_number'] ?? '' }}</td>
<td class="money">{{ $money::format(\App\Support\Decimal::money((string)($line['raw_amount'] ?? '0'))) }}</td></tr>
@foreach($line['cells'] ?? [] as $cell)
@if(!empty($cell['receipt_number']))
<tr><td class="muted">{{ $cell['date'] ?? '' }}</td><td></td><td>{{ $cell['quantity'] ?? '' }}</td><td></td>
<td>{{ $cell['receipt_number'] }}</td><td></td></tr>
@endif
@endforeach
@endforeach
</tbody></table>
<h2>Totals / Totales</h2>
<table><tbody>
<tr><th>DR</th><td class="money">{{ $money::format((string)$totals['totalDr']) }}</td></tr>
<tr><th>WODR</th><td class="money">{{ $money::format((string)$totals['totalWodr']) }}</td></tr>
@if(isset($totals['serviceFee']))<tr><th>{{ $snapshot['type']==='IC'?'Arranged fee':'Material fee' }}</th><td class="money">{{ $money::format((string)$totals['serviceFee']) }}</td></tr>@endif
@if(isset($totals['bankFee']))<tr><th>Bank fee</th><td class="money">{{ $money::format((string)$totals['bankFee']) }}</td></tr>@endif
@if(isset($totals['contingency']))<tr><th>Contingency</th><td class="money">{{ $money::format((string)$totals['contingency']) }}</td></tr>@endif
<tr><th>Grand total</th><td class="money">{{ $money::format((string)$totals['total']) }}</td></tr>
</tbody></table>
@if($snapshot['stage']==='final' && !empty($snapshot['initial_snapshot']))
<div class="page-break"><h2>Initial / Etapa inicial</h2>
<p>Initial total: {{ $money::format((string)$snapshot['initial_total']) }}</p>
<table><thead><tr><th>Description</th><th>Qty</th><th>Amount</th></tr></thead><tbody>
@foreach($snapshot['initial_snapshot']['lines'] ?? [] as $line)
<tr><td>{{ $line['description_en'] ?? '' }}</td><td>{{ $line['quantity'] ?? \App\Support\Decimal::quantity(\App\Support\Decimal::sum(array_column($line['cells'] ?? [],'quantity'))) }}</td>
<td class="money">{{ $money::format(\App\Support\Decimal::money((string)($line['raw_amount'] ?? '0'))) }}</td></tr>
@endforeach
</tbody></table></div>
@endif
<p><strong>BALANCE DUE FINAL:</strong> {{ $money::format((string)$totals['balanceDue']) }}</p>
@if($snapshot['stage']==='final')
<p>IF BALANCE IS POSITIVE THEN PLEASE MAKE THE REQUIRED PAYMENT</p>
<p>IF BALANCE IS NEGATIVE THEN NO PAYMENT IS REQUIRED</p>
@endif
@foreach($snapshot['payment_terms'] ?? [] as $term)
@if(is_string($term))<p>{{ $term }}</p>@elseif(is_array($term))<p>{{ implode(' · ',$term) }}</p>@endif
@endforeach
</body></html>
