{{-- SR-05 term history (append-only events). --}}
@if (count($history) === 0)
    <p class="lml-language-management__muted">No history yet.</p>
@else
    <ol class="lml-term-history" data-lml-term-history>
        @foreach ($history as $event)
            <li class="lml-term-history__item lml-term-history__item--{{ $event['action'] }}">
                <p class="lml-term-history__line">
                    <span class="fw-semibold">{{ $event['action_label'] }}</span>
                    @if ($event['version_no'])
                        <span class="lml-term-history__version">version {{ $event['version_no'] }}</span>
                    @endif
                    — {{ $event['actor'] }} ({{ $event['actor_role'] }}), {{ $event['at'] }}
                </p>
                @if ($event['note'])
                    <p class="lml-term-history__note">{{ $event['note'] }}</p>
                @endif
            </li>
        @endforeach
    </ol>
@endif
