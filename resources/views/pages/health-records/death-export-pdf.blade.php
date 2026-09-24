<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Death Records</title>
    <style>
        body {
            margin: 0;
            padding: 18px;
            color: #1f2937;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.4;
        }
        h1 {
            margin: 0 0 4px;
            color: #0b3d0b;
            font-size: 18px;
        }
        .meta {
            margin: 0 0 12px;
            color: #4b5563;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #d1d5db;
            padding: 6px 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #7ed39a;
            color: #0b3d0b;
            font-size: 11px;
        }
        .empty {
            margin: 16px 0 0;
            color: #6b7280;
        }
        .name {
            font-weight: 700;
        }
        .meta-line {
            color: #6b7280;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <h1>Death Records</h1>
    <p class="meta">
        Generated {{ \App\Support\DisplayDate::formatDateTime($generatedAt) }}
        · {{ count($rows) }} record(s)
        @if (($filterLabels ?? []) !== [])
            · Filters: {{ implode(' · ', $filterLabels) }}
        @endif
    </p>

    @if ($rows === [])
        <p class="empty">No death records match the selected filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Age</th>
                    <th>Cause of Death</th>
                    <th>Date of Death</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>
                            <div class="name">{{ $row['full_name'] }}</div>
                            @if (($row['member_id'] ?? '') !== '' || ($row['sex'] ?? '') !== '')
                                <div class="meta-line">
                                    {{ implode(' · ', array_filter([
                                        (string) ($row['sex'] ?? ''),
                                        (string) ($row['member_id'] ?? ''),
                                    ])) }}
                                </div>
                            @endif
                        </td>
                        <td>{{ $row['age'] }}</td>
                        <td>{{ $row['cause_of_death'] }}</td>
                        <td>{{ $row['date_of_death'] }}</td>
                        <td>{{ $row['status_label'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
