<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
@php $brand = $brand ?? ['primary' => '#3960a3', 'secondary' => '#f78e3c', 'accent' => '#007145']; @endphp
@include('pdf.partials.excel-style')
<style>
.draft-grid { font-size: 7px; }
.draft-grid td,.draft-grid th { padding: 1.2px 2px; }
.draft-sheet .institutional-logo { width: 68px; height: 68px; }
.draft-project { width: 68%; font-size: 8px; margin-bottom: 9px; }
.draft-project td,.draft-project th { padding: 1px 3px; }
.draft-project th { width: 33%; text-align: left; background: {{ $brand['primary'] }}; color: white; }
.draft-project td { border-bottom: 1px solid #ddd; }
.draft-summary { width: 43%; margin-top: 9px; font-size: 8px; page-break-inside: avoid; }
.draft-summary th,.draft-summary td { border: .5px solid #333; padding: 2.5px; }
</style></head><body>
@php
    $money = \App\Support\MoneyPresenter::class;
    $decimal = \App\Support\Decimal::class;
    $logoDataUri = $logoDataUri ?? app(\App\Services\BrandingAssetService::class)->logoDataUri();
@endphp
@foreach($sheets as $sheet)
@php $snapshot = $sheet['snapshot']; $meta = $snapshot['metadata']; $days = $sheet['type'] === 'IC' ? $sheet['days'] : []; $totalColumns = 6 + count($days) * 2; @endphp
<section class="draft-sheet">
    <table class="document-heading"><tr><td style="width:16%">@if($logoDataUri)<img class="institutional-logo" src="{{ $logoDataUri }}" alt="FIEA Ecuador">@endif</td>
        <td style="padding-top:16px"><strong style="color:{{ $brand['primary'] }}">FUNDACION INGENIEROS EN ACCION</strong><br>Abelardo Moncayo Oe3-145 y Av. América<br>Phone: (593) 5012558<br>Quito, Ecuador</td>
        <td style="width:42%;padding-top:16px"><h1>DRAFT BUDGET</h1><p style="text-align:right">{{ $sheet['type'] }} {{ strtoupper($sheet['stage']) }}</p></td></tr></table>
    <table class="draft-project"><tr><th style="width:33%">PROJECT INFORMATION</th><td></td></tr>
        <tr><th>TEAM</th><td>{{ $meta['team_name'] ?? '' }}</td></tr><tr><th>PROJECT</th><td>{{ $meta['project_name'] ?? '' }}</td></tr>
        <tr><th>NUMBER OF VOLUNTEERS</th><td>{{ $meta['volunteer_count'] ?? '' }}</td></tr>
        <tr><th>NUMBER OF EIA STAFF</th><td>{{ $meta['staff_count'] ?? '' }}</td></tr>
        <tr><th>DATES OF TRAVEL</th><td>{{ $meta['starts_on'] ?? '' }} - {{ $meta['ends_on'] ?? '' }}</td></tr>
        <tr><th>INVOICE NAME</th><td>{{ $meta['invoice_name'] ?? '' }}</td></tr>
        <tr><th>ADDRESS</th><td>{{ $meta['address'] ?? '' }}</td></tr>
        <tr><th>PHONE / EMAIL</th><td>{{ $meta['phone'] ?? '' }} / {{ $meta['email'] ?? '' }}</td></tr>
    </table>
    @if($snapshot['preview_only'] ?? false)<p class="draft-notice">BORRADOR COMPARATIVO: Initial todavía no aprobado. No constituye una factura emitida.</p>@endif
    <table class="invoice-grid draft-grid"><thead>
        <tr class="column-groups"><th style="width:{{ $days ? 22 : 38 }}%"></th><th style="width:{{ $days ? 6 : 10 }}%"></th><th style="width:{{ $days ? 6 : 14 }}%"></th>
            @foreach($days as $day)<th style="width:{{ 50 / max(1,count($days)) }}%" colspan="2">{{ $day }}</th>@endforeach
            <th style="width:{{ $days ? 16 : 38 }}%" colspan="3">FULL TRIP TOTAL</th></tr>
        <tr class="column-labels"><th style="width:23%">DESCRIPTION</th><th style="width:6%">UNIT</th><th style="width:6%">{{ strtoupper($sheet['stage']) }} COST</th>
            @foreach($days as $day)<th>Qty</th><th>Total</th>@endforeach<th>Qty</th><th>$USD</th><th>RECEIPT</th></tr>
    </thead><tbody>
    @php $previousSection = null; @endphp
    @foreach($snapshot['lines'] as $line)
        @php $section = $line['section'] ?? 'EIA EXPENSES'; @endphp
        @if($previousSection !== $section)<tr class="section-heading"><td colspan="{{ $totalColumns }}">{{ strtoupper($section) }}</td></tr>@php $previousSection = $section; @endphp @endif
        <tr class="expense-row"><td>{{ $line['description_en'] ?? '' }}@if(!empty($line['description_es']))<br>{{ $line['description_es'] }}@endif</td>
            <td>{{ $line['unit'] ?? '' }}</td><td class="number">{{ $money::format((string)$line['unit_cost']) }}</td>
            @foreach($days as $day)
                @php $cell = collect($line['cells'] ?? [])->firstWhere('date', $day); @endphp
                <td class="number">{{ $cell['quantity'] ?? '-' }}</td>
                <td class="number">{{ $cell ? $money::format($decimal::money($cell['raw_amount'] ?? $decimal::mul((string)$cell['quantity'], (string)$line['unit_cost']))) : '-' }}</td>
            @endforeach
            <td class="number">{{ $line['quantity'] ?? $decimal::quantity($decimal::sum(array_column($line['cells'] ?? [], 'quantity'))) }}</td>
            <td class="number">{{ $money::format($decimal::money((string)($line['raw_amount'] ?? '0'))) }}</td>
            <td class="receipt">{{ implode(', ', array_unique(array_filter([$line['receipt_number'] ?? null, ...array_column($line['cells'] ?? [], 'receipt_number')]))) }}</td>
        </tr>
    @endforeach
    </tbody></table>
    <table class="draft-summary">
        <tr class="subtotal"><th>ADDITIONAL COSTS / {{ strtoupper($sheet['stage']) }}</th><th>$USD</th></tr>
        @foreach(['serviceFee' => 'Service fee', 'bankFee' => 'Bank Fees', 'contingency' => 'Contingency (10%)'] as $key => $label)
            @if(isset($snapshot['totals'][$key]))<tr><td>{{ $label }}</td><td class="number">{{ $money::format((string)$snapshot['totals'][$key]) }}</td></tr>@endif
        @endforeach
        <tr><th>TOTAL EIA SERVICES DR</th><td class="number">{{ $money::format((string)$snapshot['totals']['totalDr']) }}</td></tr>
        <tr><th>TOTAL EIA SERVICES WODR</th><td class="number">{{ $money::format((string)$snapshot['totals']['totalWodr']) }}</td></tr>
        <tr class="invoice-total"><th>TOTAL DRAFT BUDGET</th><td class="number">{{ $money::format((string)$snapshot['totals']['total']) }}</td></tr>
    </table>
</section>
@endforeach
</body></html>
