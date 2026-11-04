<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">@include('pdf.partials.excel-style')</head><body>
@php
    $money = \App\Support\MoneyPresenter::class;
    $layout = app(\App\Services\FinancialPrintLayout::class);
    $sections = $layout->sections($snapshot);
    $totals = $snapshot['totals'];
    $final = $snapshot['stage'] === 'final';
    $comparison = $final && !empty($snapshot['initial_snapshot']);
    $material = $snapshot['type'] === 'MAT';
    $meta = $snapshot['metadata'];
    $columns = $final ? ($material ? 10 : 9) : ($material ? 6 : 5);
    $logoDataUri = $logoDataUri ?? app(\App\Services\BrandingAssetService::class)->logoDataUri();
@endphp
<table class="document-heading"><tr>
    <td style="width:38%">@if($logoDataUri)<img class="institutional-logo" src="{{ $logoDataUri }}" alt="FIEA Ecuador">@endif</td>
    <td class="document-reference"><h1>{{ $material ? 'MATERIALS' : 'IN-COUNTRY' }} {{ strtoupper($snapshot['stage']) }} INVOICE</h1>
        <table class="reference-fields"><tr><th>DATE</th><td>{{ $meta['document_date'] ?? '' }}</td></tr>
            <tr><th>INVOICE #</th><td>{{ $invoice->code }}</td></tr><tr><th>CODE</th><td>{{ $meta['project_code'] ?? '' }}</td></tr></table>
        @if(!in_array($invoice->status, ['approved','sent','paid'], true))<p class="draft-notice">DRAFT / BORRADOR</p>@endif
    </td>
</tr></table>
<table class="addresses"><tr><td style="width:42%"><strong>Engineers in Action</strong><br>6910 E 14th St.<br>Tulsa, OK 74112<br>Phone: (918)-437-0282</td>
    <td><strong>To:</strong> {{ $meta['invoice_name'] ?? '' }}<br>{{ $meta['address'] ?? '' }}<br>{{ $meta['phone'] ?? '' }}<br>{{ $meta['email'] ?? '' }}</td></tr></table>
<table class="project-strip"><tr><th style="width:44%">PROJECT</th><th style="width:28%">DATES OF TRAVEL</th><th>TEAM</th></tr>
    <tr><td>{{ $meta['project_name'] ?? '' }}</td><td>{{ $meta['starts_on'] ?? '' }} - {{ $meta['ends_on'] ?? '' }}</td><td>{{ $meta['team_name'] ?? '' }}</td></tr></table>
<table class="invoice-grid"><thead>
    <tr class="column-groups"><th style="width:{{ $material ? ($final ? 22 : 30) : ($final ? 32 : 42) }}%"></th>
        @if($material)<th style="width:{{ $final ? 16 : 20 }}%"></th>@endif
        <th style="width:{{ $material ? ($final ? 8 : 10) : ($final ? 8 : 12) }}%"></th>
        <th style="width:{{ $final ? ($material ? 10 : 12) : ($material ? 12 : 14) }}%" colspan="{{ $final ? 2 : 1 }}">QUANTITY</th>
        <th style="width:{{ $final ? ($material ? 16 : 18) : ($material ? 14 : 16) }}%" colspan="{{ $final ? 2 : 1 }}">COST ($)</th>
        <th style="width:{{ $final ? ($material ? 16 : 18) : ($material ? 14 : 16) }}%" colspan="{{ $final ? 2 : 1 }}">TOTAL COST ($)</th>
        @if($final)<th style="width:12%"></th>@endif</tr>
    <tr class="column-labels"><th style="width:{{ $material ? '25' : '35' }}%">DESCRIPTION{{ $material ? ' ENGLISH' : '' }}</th>
        @if($material)<th style="width:18%">DESCRIPTION SPANISH</th>@endif<th style="width:10%">UNIT</th>
        @for($group = 0; $group < 3; $group++) @if($final)<th>INITIAL</th><th>FINAL</th>@else<th>INITIAL</th>@endif @endfor
        @if($final)<th style="width:12%">RECEIPT #</th>@endif
    </tr></thead><tbody>
@foreach($sections as $section => $rows)
    @if($loop->first || $section === 'Fees')<tr class="fund-heading"><td colspan="{{ $columns }}">{{ $section === 'Fees' ? 'EIA SERVICES WODR' : 'EIA SERVICES DR' }}</td></tr>@endif
    <tr class="section-heading"><td colspan="{{ $columns }}">{{ strtoupper($section) }}</td></tr>
    @foreach($rows as $row)
        <tr class="expense-row"><td>{{ $row['line']['description_en'] ?? '' }}</td>
            @if($material)<td>{{ $row['line']['description_es'] ?? '' }}</td>@endif<td>{{ $row['line']['unit'] ?? '' }}</td>
            @foreach(['quantity', 'cost', 'amount'] as $field)
                @foreach($final ? ['initial','current'] : ['current'] as $stage)
                    <td class="number">@if($stage === 'initial' && !$comparison)—@else{{ $field === 'quantity' ? $row[$stage][$field] : $money::format(\App\Support\Decimal::money((string) $row[$stage][$field])) }}@endif</td>
                @endforeach
            @endforeach
            @if($final)<td class="receipt">{{ $row['receipt'] }}</td>@endif
        </tr>
    @endforeach
    <tr class="subtotal"><td colspan="{{ $columns - ($final ? 3 : 1) }}">SUBTOTAL</td>
        @if($final)<td class="number">{{ $comparison ? $money::format($layout->subtotal($rows, 'initial')) : '—' }}</td>@endif
        <td class="number">{{ $money::format($layout->subtotal($rows, 'current')) }}</td>@if($final)<td></td>@endif</tr>
@endforeach
    <tr class="invoice-total"><td colspan="{{ $columns - ($final ? 3 : 1) }}">INVOICE TOTALS</td>
        @if($final)<td class="number">{{ $comparison ? $money::format((string)($snapshot['initial_total'] ?? '0')) : '—' }}</td>@endif
        <td class="number">{{ $money::format((string)$totals['total']) }}</td>@if($final)<td></td>@endif</tr>
</tbody></table>
<table class="closing-block"><tr><td class="summary-column">
    <table class="summary-grid"><thead><tr><th>ONLY FOR EIA USE</th><th>INITIAL</th>@if($final)<th>FINAL</th><th>BALANCE</th>@endif</tr></thead><tbody>
    @foreach($sections as $section => $rows)
        <tr><td>Subtotal {{ $section }}</td><td class="number">{{ $final && !$comparison ? '—' : $money::format($layout->subtotal($rows, $final ? 'initial' : 'current')) }}</td>
            @if($final)<td class="number">{{ $money::format($layout->subtotal($rows, 'current')) }}</td><td class="number">{{ $money::format(\App\Support\Decimal::money(\App\Support\Decimal::sub($layout->subtotal($rows, 'current'), $layout->subtotal($rows, 'initial')))) }}</td>@endif</tr>
    @endforeach
    @foreach(['totalDr' => 'TOTAL EIA SERVICES DR', 'totalWodr' => 'TOTAL EIA SERVICES WODR', 'total' => 'TOTALS'] as $key => $label)
        <tr class="summary-total"><td>{{ $label }}</td><td class="number">{{ $final && !$comparison ? '—' : $money::format((string)($final ? ($snapshot['initial_snapshot']['totals'][$key] ?? '0') : $totals[$key])) }}</td>
            @if($final)<td class="number">{{ $money::format((string)$totals[$key]) }}</td><td class="number">{{ $money::format(\App\Support\Decimal::money(\App\Support\Decimal::sub((string)$totals[$key], (string)($snapshot['initial_snapshot']['totals'][$key] ?? '0')))) }}</td>@endif</tr>
    @endforeach
    </tbody></table>
</td><td class="terms-column">
    <table class="balance"><tr><th>BALANCE DUE{{ $final ? ' FINAL' : '' }}</th><td>{{ $money::format((string)$totals['balanceDue']) }}</td></tr></table>
    @if($final)<p class="payment-notice">IF BALANCE IS POSITIVE THEN PLEASE MAKE THE REQUIRED PAYMENT<br>IF BALANCE IS NEGATIVE THEN NO PAYMENT IS REQUIRED</p>
    @else<p class="payment-notice">INITIAL PAYMENT IS REQUIRED 14 DAYS AHEAD OF ARRIVAL DATE</p><p>Any adjustments for expenses will be made on the Final Invoice.</p>@endif
    <strong>PAYMENT METHODS:</strong>
    <p><strong>CHECK</strong> &nbsp; Make checks payable to: Engineers in Action<br>Please reference the invoice # on your remittance.<br>Mail to the EIA address at the top of the invoice.</p>
    <p><strong>ACH</strong><br>CREDIT CARD (Additional 3.5% fee)<br>PAYPAL (Additional 2% fee)</p>
    @foreach(array_diff_key($snapshot['payment_terms'] ?? [], array_flip(['lead_time', 'check', 'methods'])) as $term)
        @if(is_string($term))<p>{{ $term }}</p>@elseif(is_array($term))<p>{{ implode(' · ', $term) }}</p>@endif
    @endforeach
</td></tr></table>
</body></html>
