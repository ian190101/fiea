<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->code }}</title>
    <style>
        @page {
            margin: 36px 36px 42px;
        }

        body {
            color: #1f2937;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.35;
        }

        h1, h2, p {
            margin: 0;
        }

        .header {
            border-bottom: 3px solid {{ $brand['primary'] ?? '#2563eb' }};
            padding-bottom: 14px;
        }

        .header-table, .summary, .meta, table.lines {
            width: 100%;
        }

        .header-logo {
            text-align: right;
            width: 110px;
        }

        .header-logo img {
            max-height: 54px;
            max-width: 110px;
        }

        .brand {
            color: {{ $brand['primary'] ?? '#2563eb' }};
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 1.2px;
            text-transform: uppercase;
        }

        h1 {
            color: #111827;
            font-size: 26px;
            margin-top: 4px;
        }

        .muted {
            color: #6b7280;
        }

        .meta {
            border-spacing: 0 12px;
            margin-top: 18px;
        }

        .meta td {
            border: 1px solid #d1d5db;
            padding: 12px;
            vertical-align: top;
            width: 50%;
        }

        .grid {
            display: table;
            margin-top: 18px;
            width: 100%;
        }

        .grid-row {
            display: table-row;
        }

        .grid-cell {
            display: table-cell;
            padding-right: 14px;
            vertical-align: top;
            width: 50%;
        }

        .label {
            color: #6b7280;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: .6px;
            text-transform: uppercase;
        }

        .value {
            color: #111827;
            font-size: 12px;
            font-weight: bold;
            margin-top: 2px;
        }

        .summary {
            margin-top: 14px;
        }

        .summary td {
            border: 1px solid #d1d5db;
            padding: 10px;
        }

        .summary .amount {
            font-size: 16px;
            font-weight: bold;
            text-align: right;
        }

        .note {
            background: #f8fafc;
            border-left: 4px solid {{ $brand['accent'] ?? '#f59e0b' }};
            color: #475569;
            margin-top: 14px;
            padding: 10px 12px;
        }

        .section-title {
            color: #111827;
            font-size: 15px;
            margin-bottom: 8px;
            margin-top: 22px;
        }

        table.lines {
            border-collapse: collapse;
        }

        table.lines th {
            background: #eff6ff;
            border: 1px solid #cbd5e1;
            color: {{ $brand['primary'] ?? '#2563eb' }};
            font-size: 9px;
            padding: 7px;
            text-align: left;
            text-transform: uppercase;
        }

        table.lines td {
            border: 1px solid #d1d5db;
            padding: 7px;
            vertical-align: top;
        }

        .right {
            text-align: right;
        }

        .fund {
            border-radius: 4px;
            color: #ffffff;
            display: inline-block;
            font-size: 9px;
            font-weight: bold;
            padding: 2px 6px;
        }

        .fund-dr {
            background: {{ $brand['primary'] ?? '#2563eb' }};
        }

        .fund-wodr {
            background: {{ $brand['secondary'] ?? '#0f766e' }};
        }

        .footer {
            border-top: 1px solid #d1d5db;
            bottom: -22px;
            color: #6b7280;
            font-size: 9px;
            left: 0;
            padding-top: 8px;
            position: fixed;
            right: 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                <td>
                    <p class="brand">FIEA Invoice</p>
                    <h1>{{ $invoice->type }} {{ ucfirst($invoice->stage) }} Invoice</h1>
                    <p class="muted">{{ $invoice->code }} - Generated on {{ $generatedAt->format('F j, Y') }}</p>
                </td>
                @if($logoDataUri)
                    <td class="header-logo">
                        <img src="{{ $logoDataUri }}" alt="FIEA">
                    </td>
                @endif
            </tr>
        </table>
    </div>

    <table class="meta">
        <tr>
            <td>
                <p class="label">Project</p>
                <p class="value">{{ $invoice->tripPhase?->project?->name }}</p>
                <p class="muted">{{ $invoice->tripPhase?->project?->code }} - {{ $invoice->tripPhase?->project?->country?->name }}@if($invoice->tripPhase?->project?->community), {{ $invoice->tripPhase?->project?->community?->name }}@endif</p>
            </td>
            <td>
                <p class="label">Invoice Details</p>
                <p class="value">{{ $invoice->type }} / {{ ucfirst($invoice->stage) }}</p>
                <p class="muted">Status: {{ ucfirst(str_replace('_', ' ', $invoice->status)) }} - Accounting: {{ ucfirst(str_replace('_', ' ', $invoice->accounting_status)) }}</p>
            </td>
        </tr>
        <tr>
            <td>
                <p class="label">Travel Phase</p>
                <p class="value">{{ $invoice->tripPhase?->phase }}</p>
                <p class="muted">{{ $invoice->tripPhase?->starts_on?->format('M j, Y') }} - {{ $invoice->tripPhase?->ends_on?->format('M j, Y') }}</p>
            </td>
            <td>
                <p class="label">Team / Contact</p>
                <p class="value">{{ $invoice->tripPhase?->team?->name }}</p>
                <p class="muted">{{ $invoice->contactPerson?->full_name ?? 'No contact selected' }}@if($invoice->contactPerson?->email) - {{ $invoice->contactPerson->email }}@endif</p>
            </td>
        </tr>
        <tr>
            <td>
                <p class="label">Dates</p>
                <p class="value">Sent: {{ $invoice->sent_at?->format('M j, Y') ?? 'Not sent' }}</p>
                <p class="muted">Paid: {{ $invoice->paid_at?->format('M j, Y') ?? 'Not paid' }}</p>
            </td>
            <td>
                <p class="label">Accounting Review</p>
                <p class="value">{{ $invoice->accountingReviewedBy?->name ?? 'Not reviewed' }}</p>
                <p class="muted">{{ $invoice->accounting_reviewed_at?->format('M j, Y') ?? 'Pending review' }}</p>
            </td>
        </tr>
    </table>

    <table class="summary">
        <tr>
            <td>
                <p class="label">DR Total</p>
                <p class="amount">${{ number_format((float) $invoice->total_dr, 2) }}</p>
            </td>
            <td>
                <p class="label">WODR Total</p>
                <p class="amount">${{ number_format((float) $invoice->total_wodr, 2) }}</p>
            </td>
            <td>
                <p class="label">Grand Total</p>
                <p class="amount">${{ number_format((float) $invoice->grand_total, 2) }}</p>
            </td>
            <td>
                <p class="label">Balance Conciliation</p>
                <p class="amount">${{ number_format((float) $invoice->balance_conciliation, 2) }}</p>
            </td>
        </tr>
    </table>

    @if($invoice->accounting_note)
        <p class="note"><strong>Accounting note:</strong> {{ $invoice->accounting_note }}</p>
    @else
        <p class="note">This invoice is prepared in English for FIEA financial reporting and United States review. Amounts reflect actual expenses registered for this travel phase.</p>
    @endif

    <div class="grid">
        <div class="grid-row">
            <div class="grid-cell">
                <h2 class="section-title">Fund Summary</h2>
                <table class="lines">
                    <thead>
                        <tr>
                            <th>Fund</th>
                            <th class="right">Actual Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($fundSummary as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td class="right">${{ number_format($row['total'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="grid-cell">
                <h2 class="section-title">Category Summary</h2>
                <table class="lines">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="right">Actual Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($categorySummary as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td class="right">${{ number_format($row['total'], 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="muted">No category totals available.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <h2 class="section-title">Actual Expenses</h2>
    <table class="lines">
        <thead>
            <tr>
                <th style="width: 31%;">Description</th>
                <th style="width: 21%;">Category</th>
                <th style="width: 10%;">Fund</th>
                <th style="width: 12%;">Unit</th>
                <th class="right" style="width: 10%;">Unit Cost</th>
                <th class="right" style="width: 7%;">Qty</th>
                <th class="right" style="width: 9%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($expenses as $expense)
                <tr>
                    <td>{{ $expense->description }}</td>
                    <td>{{ $expense->expenseCategory?->name }}</td>
                    <td>
                        <span class="fund {{ $expense->fund_type === 'DR' ? 'fund-dr' : 'fund-wodr' }}">
                            {{ $expense->fund_type }}
                        </span>
                    </td>
                    <td>{{ $expense->unit ?: '-' }}</td>
                    <td class="right">${{ number_format((float) $expense->final_unit_cost, 2) }}</td>
                    <td class="right">{{ number_format((float) $expense->final_quantity, 2) }}</td>
                    <td class="right">${{ number_format((float) $expense->real_total, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="muted">No actual expenses have been registered for this invoice travel phase.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        FIEA Invoice - official document for United States reporting.
    </div>
</body>
</html>
