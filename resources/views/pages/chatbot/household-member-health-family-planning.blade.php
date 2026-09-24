@extends('layouts.app')

@section('title', 'Family Planning - '.$memberName.' - LMLinga')

@section('body')
    @component('pages.chatbot.partials.household-member-health-shell', [
        'memberId' => $memberId,
        'memberName' => $memberName,
        'moduleTitle' => $moduleTitle,
    ])
        <article class="lml-chatbot-member-health__card">
            <section class="lml-chatbot-member-health__section" aria-labelledby="hh-fp-heading">
                <h2 id="hh-fp-heading" class="lml-chatbot-member-health__section-title">
                    <i class="bi bi-people" aria-hidden="true"></i>
                    <span>Visit History</span>
                </h2>

                @if ($rows === [])
                    <p class="lml-chatbot-member-health__empty" role="status">No family planning visit records available.</p>
                @else
                    <ul class="lml-chatbot-member-health__history">
                        @foreach ($rows as $row)
                            <li class="lml-chatbot-member-health__history-item">
                                <div class="lml-chatbot-member-health__history-head">
                                    <strong>{{ $row['id'] ?? 'Visit' }}</strong>
                                    <span>{{ filled($row['visited_at'] ?? null) ? $row['visited_at'] : 'Date not recorded' }}</span>
                                </div>
                                <dl class="lml-chatbot-member-health__dl lml-chatbot-member-health__dl--compact">
                                    <div>
                                        <dt>Commodities</dt>
                                        <dd>{{ filled($row['commodities_label'] ?? null) ? $row['commodities_label'] : '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt>Remarks</dt>
                                        <dd>{{ filled($row['remarks'] ?? null) ? $row['remarks'] : '—' }}</dd>
                                    </div>
                                </dl>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </article>
    @endcomponent
@endsection
