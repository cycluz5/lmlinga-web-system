@extends('layouts.app')

@section('title', 'Maternal Care - '.$memberName.' - LMLinga')

@section('body')
    @component('pages.chatbot.partials.household-member-health-shell', [
        'memberId' => $memberId,
        'memberName' => $memberName,
        'moduleTitle' => $moduleTitle,
    ])
        <article class="lml-chatbot-member-health__card">
            <section class="lml-chatbot-member-health__section" aria-labelledby="hh-mat-active-heading">
                <h2 id="hh-mat-active-heading" class="lml-chatbot-member-health__section-title">
                    <i class="bi bi-balloon-heart" aria-hidden="true"></i>
                    <span>Active Pregnancy</span>
                </h2>

                @if ($active === null)
                    <p class="lml-chatbot-member-health__empty" role="status">No active maternal care record.</p>
                @else
                    <dl class="lml-chatbot-member-health__dl">
                        @foreach ([
                            'Pregnancy number' => $active['pregnancy_number'] ?? $active['pregnancyNumber'] ?? null,
                            'Status' => $active['status'] ?? $active['pregnancy_status'] ?? null,
                            'LMP' => $active['lmp'] ?? $active['last_menstrual_period'] ?? null,
                            'EDC' => $active['edc'] ?? $active['expected_date_of_confinement'] ?? null,
                        ] as $label => $value)
                            <div>
                                <dt>{{ $label }}</dt>
                                <dd>{{ filled($value) ? $value : '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </section>

            <section class="lml-chatbot-member-health__section" aria-labelledby="hh-mat-history-heading">
                <h2 id="hh-mat-history-heading" class="lml-chatbot-member-health__section-title">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    <span>Pregnancy History</span>
                </h2>

                @if ($history === [])
                    <p class="lml-chatbot-member-health__empty" role="status">No previous maternal care records.</p>
                @else
                    <ul class="lml-chatbot-member-health__history">
                        @foreach ($history as $row)
                            <li class="lml-chatbot-member-health__history-item">
                                <div class="lml-chatbot-member-health__history-head">
                                    <strong>
                                        Pregnancy
                                        {{ $row['pregnancy_number'] ?? $row['pregnancyNumber'] ?? '' }}
                                    </strong>
                                    <span>{{ $row['status'] ?? $row['pregnancy_status'] ?? 'Recorded' }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </article>
    @endcomponent
@endsection
