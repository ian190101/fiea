@php $brand = $brand ?? ['primary' => '#3960a3', 'secondary' => '#f78e3c', 'accent' => '#007145']; @endphp
<style>
@page { margin: 30px 32px; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 8px; color: #171717; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
td, th { vertical-align: top; padding: 2px 3px; overflow-wrap: break-word; }
h1 { color: {{ $brand['primary'] }}; font-size: 17px; margin: 0 0 7px; text-align: right; }
.document-heading { border-bottom: 2px solid {{ $brand['secondary'] }}; margin-bottom: 7px; }
.institutional-logo { width: 92px; height: 92px; object-fit: contain; }
.document-reference { padding-top: 16px; }
.reference-fields { margin-left: auto; width: 78%; font-size: 9px; }
.reference-fields th { color: {{ $brand['primary'] }}; text-align: left; width: 32%; }
.draft-notice { text-align: right; color: {{ $brand['accent'] }}; font-weight: bold; }
.addresses { font-size: 9px; margin-bottom: 8px; }
.project-strip { margin-bottom: 10px; font-size: 8px; }
.project-strip th, .column-labels, .subtotal { background: {{ $brand['secondary'] }}; color: #171717; font-weight: bold; }
.project-strip td { border: 1px solid {{ $brand['secondary'] }}; text-align: center; }
.column-groups, .fund-heading, .invoice-total { background: {{ $brand['primary'] }}; color: white; font-weight: bold; }
.column-groups th, .column-labels th { text-align: center; vertical-align: middle; }
.invoice-grid { font-size: 8px; }
.invoice-grid td, .invoice-grid th { border: .45px solid #222; padding: 1.5px 3px; }
.invoice-grid thead { display: table-header-group; }
.invoice-grid tr { page-break-inside: avoid; }
.expense-row:nth-child(even) { background: #fff2e7; }
.section-heading { background: #fae2cd; font-weight: bold; }
.number { text-align: right; white-space: nowrap; }
.receipt { font-size: 7px; word-wrap: break-word; }
.closing-block { margin-top: 16px; page-break-inside: avoid; }
.summary-column { width: 47%; padding: 0 16px 0 0; }
.terms-column { width: 53%; font-size: 8px; padding: 0 0 0 12px; }
.summary-grid { font-size: 7px; }
.summary-grid td, .summary-grid th { border: .4px solid #444; padding: 3px; }
.summary-grid thead, .summary-total { background: {{ $brand['primary'] }}; color: white; }
.summary-total { font-weight: bold; }
.balance { margin-bottom: 12px; font-size: 10px; }
.balance th { width: 52%; text-align: right; padding: 5px; }
.balance td { border: 2px solid {{ $brand['accent'] }}; text-align: right; padding: 5px; font-weight: bold; }
.payment-notice { font-weight: bold; }
.draft-sheet { page-break-before: always; }
.draft-sheet:first-child { page-break-before: auto; }
</style>
