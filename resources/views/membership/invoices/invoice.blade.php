<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #222;
        }

        .header {
            width: 100%;
            margin-bottom: 25px;
        }

        .title {
            font-size: 24px;
            font-weight: bold;
        }

        .muted {
            color: #666;
        }

        .section {
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f1f1f1;
            text-align: left;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 8px;
        }

        .right {
            text-align: right;
        }

        .no-border td {
            border: none;
        }

        .summary td {
            padding: 6px 8px;
        }
    </style>
</head>
<body>
    <table class="header no-border">
        <tr>
            <td>
                <div class="title">Tax Invoice</div>
                <div class="muted">{{ config('app.name', 'Holiplaces') }}</div>
            </td>
            <td class="right">
                <strong>Invoice No:</strong> {{ $invoice->invoice_number }}<br>
                <strong>Date:</strong> {{ optional($invoice->invoice_date)->format('d M Y') }}
            </td>
        </tr>
    </table>

    <div class="section">
        <strong>Bill To</strong><br>
        {{ $invoice->billing_name ?? $userName }}<br>
        @if($invoice->billing_email)
            {{ $invoice->billing_email }}<br>
        @endif
        @if($invoice->billing_phone)
            {{ $invoice->billing_phone }}<br>
        @endif
        @if($invoice->billing_gst_number)
            GST: {{ $invoice->billing_gst_number }}<br>
        @endif
        @if($invoice->billing_address)
            {{ $invoice->billing_address }}<br>
        @endif
        {{ collect([$invoice->billing_city, $invoice->billing_state, $invoice->billing_country, $invoice->billing_pincode])->filter()->implode(', ') }}
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="right">Taxable Amount</th>
                    <th class="right">GST</th>
                    <th class="right">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $description }}</td>
                    <td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->taxable_amount, 2) }}</td>
                    <td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->gst_amount, 2) }}</td>
                    <td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->total_amount, 2) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section">
        <table class="summary">
            <tr>
                <td class="right"><strong>Taxable Amount</strong></td>
                <td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->taxable_amount, 2) }}</td>
            </tr>
            <tr>
                <td class="right"><strong>CGST</strong></td>
                <td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->cgst_amount, 2) }}</td>
            </tr>
            <tr>
                <td class="right"><strong>SGST</strong></td>
                <td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->sgst_amount, 2) }}</td>
            </tr>
            <tr>
                <td class="right"><strong>IGST</strong></td>
                <td class="right">{{ $invoice->currency }} {{ number_format((float) $invoice->igst_amount, 2) }}</td>
            </tr>
            <tr>
                <td class="right"><strong>Total</strong></td>
                <td class="right"><strong>{{ $invoice->currency }} {{ number_format((float) $invoice->total_amount, 2) }}</strong></td>
            </tr>
        </table>
    </div>

    <p class="muted">
        This is a system generated invoice.
    </p>
</body>
</html>