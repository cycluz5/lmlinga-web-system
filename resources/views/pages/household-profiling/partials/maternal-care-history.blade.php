@php
    use App\Support\DemoMaternalCare;
@endphp

<section class="lml-mc__panel" aria-labelledby="lml-mc-history-title" data-mc-history>
    <header class="lml-mc__panel-head">
        <div class="lml-mc__panel-titles">
            <h2 id="lml-mc-history-title" class="lml-mc__panel-title">Pregnancy History</h2>
            <p class="lml-mc__panel-subtitle">
                Previous pregnancy records for this member.
            </p>
        </div>
        <a
            href="{{ route('household-profiling.members.maternal-care.index', $routeParams) }}"
            class="lml-mc__btn lml-mc__btn--ghost lml-focus-ring"
        >
            Back to Overview
        </a>
    </header>

    @if ($history === [])
        <p class="lml-mc__history-empty" data-mc-history-empty>
            No Record Yet
        </p>
    @else
        <ul class="lml-mc__history-list" data-mc-history-list>
            @foreach ($history as $row)
                @php
                    $rowId = (string) ($row['id'] ?? '');
                    $rowStatus = (string) ($row['status'] ?? '');
                    $isTransOut = $rowStatus === 'transferred_out';
                    $isCompleted = $rowStatus === 'completed';
                    $outcome = (string) data_get($row, 'delivery.outcome', '');
                    $outcomeLabel = DemoMaternalCare::OUTCOMES[$outcome] ?? '—';
                    $eventDate = DemoMaternalCare::deliveryHistoryDate(
                        is_array(data_get($row, 'delivery')) ? $row['delivery'] : []
                    );
                    $statusLabel = $isTransOut ? 'Trans-Out' : ($isCompleted ? 'Completed' : 'Closed');
                    $statusNote = $isTransOut
                        ? 'Transferred out'
                        : ($eventDate !== ''
                            ? ((in_array($outcome, ['FD', 'AB'], true) ? 'Ended ' : 'Delivered ').DemoMaternalCare::formatDate($eventDate))
                            : ($isCompleted ? 'Pregnancy completed' : 'Record closed'));
                @endphp
                <li class="lml-mc__history-item" data-mc-history-item="{{ $rowId }}" data-mc-history-status="{{ $rowStatus }}" data-mc-history-date="{{ $eventDate }}">
                    @if ($rowId !== '')
                        <a
                            href="{{ route('household-profiling.members.maternal-care.history.show', $routeParams + ['pregnancyId' => $rowId]) }}"
                            class="lml-mc__history-link lml-focus-ring"
                            data-mc-history-open="{{ $rowId }}"
                        >
                            <div class="lml-mc__history-main">
                                <span class="lml-mc__history-title">
                                    Pregnancy {{ $row['number'] ?? '' }}
                                    @if (! empty($row['lmp']))
                                        (LMP: {{ $row['lmp_label'] ?? DemoMaternalCare::formatDate($row['lmp']) }})
                                    @endif
                                </span>
                                <span class="lml-mc__pill" data-mc-history-outcome>
                                    {{ $statusLabel }}{{ $outcome !== '' ? ' · '.$outcomeLabel : '' }}
                                </span>
                            </div>
                            <p class="lml-mc__history-meta">{{ $statusNote }} · View record</p>
                        </a>
                    @else
                        <div class="lml-mc__history-main">
                            <span class="lml-mc__history-title">Pregnancy {{ $row['number'] ?? '' }}</span>
                            <span class="lml-mc__pill">{{ $statusLabel }}</span>
                        </div>
                        <p class="lml-mc__history-meta">{{ $statusNote }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
