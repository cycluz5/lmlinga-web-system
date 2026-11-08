@extends('layouts.app')

@section('title', 'Risk Assessment - '.$memberName.' - LMLinga')

@section('body')
    @component('pages.chatbot.partials.household-member-health-shell', [
        'memberId' => $memberId,
        'memberName' => $memberName,
        'moduleTitle' => $moduleTitle,
    ])
        <article class="lml-chatbot-member-health__card">
            <section class="lml-chatbot-member-health__section" aria-labelledby="hh-ra-heading">
                <h2 id="hh-ra-heading" class="lml-chatbot-member-health__section-title">
                    <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                    <span>Assessment History</span>
                </h2>

                @if ($rows === [])
                    <p class="lml-chatbot-member-health__empty" role="status">No risk assessment records available.</p>
                @else
                    <ul class="lml-chatbot-member-health__history">
                        @foreach ($rows as $row)
                            <li class="lml-chatbot-member-health__history-item">
                                <div class="lml-chatbot-member-health__history-head">
                                    <strong>{{ $row['id'] ?? 'Assessment' }}</strong>
                                    <span>{{ filled($row['conducted_at'] ?? null) ? $row['conducted_at'] : 'Date not recorded' }}</span>
                                </div>
                                <dl class="lml-chatbot-member-health__dl lml-chatbot-member-health__dl--compact">
                                    <div>
                                        <dt>Blood pressure</dt>
                                        <dd>{{ filled($row['bp_reading'] ?? null) ? $row['bp_reading'] : '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt>BMI</dt>
                                        <dd>{{ filled($row['bmi_label'] ?? null) ? $row['bmi_label'] : '—' }}</dd>
                                    </div>
                                    @if (filled($row['height_cm'] ?? null) || filled($row['weight_kg'] ?? null))
                                        <div>
                                            <dt>Height / Weight</dt>
                                            <dd>
                                                {{ filled($row['height_cm'] ?? null) ? $row['height_cm'].' cm' : '—' }}
                                                /
                                                {{ filled($row['weight_kg'] ?? null) ? $row['weight_kg'].' kg' : '—' }}
                                            </dd>
                                        </div>
                                    @endif
                                </dl>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </article>
    @endcomponent
@endsection
