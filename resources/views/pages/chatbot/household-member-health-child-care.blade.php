@extends('layouts.app')

@section('title', 'Child Care - '.$memberName.' - LMLinga')

@section('body')
    @component('pages.chatbot.partials.household-member-health-shell', [
        'memberId' => $memberId,
        'memberName' => $memberName,
        'moduleTitle' => $moduleTitle,
    ])
        <article class="lml-chatbot-member-health__card">
            <section class="lml-chatbot-member-health__section" aria-labelledby="hh-birth-heading">
                <h2 id="hh-birth-heading" class="lml-chatbot-member-health__section-title">
                    <i class="bi bi-clipboard2-heart" aria-hidden="true"></i>
                    <span>Birth History</span>
                </h2>
                @if ($birthHistory === null)
                    <p class="lml-chatbot-member-health__empty" role="status">No birth history record available.</p>
                @else
                    <dl class="lml-chatbot-member-health__dl">
                        <div><dt>Birth weight</dt><dd>{{ filled($birthHistory['weight'] ?? null) ? $birthHistory['weight'].' kg' : '—' }}</dd></div>
                        <div><dt>Birth length</dt><dd>{{ filled($birthHistory['length'] ?? null) ? $birthHistory['length'].' cm' : '—' }}</dd></div>
                        <div><dt>Status</dt><dd>{{ filled($birthHistory['status'] ?? null) ? $birthHistory['status'] : '—' }}</dd></div>
                        <div><dt>CPAB</dt><dd>{{ filled($birthHistory['pcab'] ?? null) ? $birthHistory['pcab'] : '—' }}</dd></div>
                        <div><dt>Breastfeeding</dt><dd>{{ filled($birthHistory['breastfeeding_date_display'] ?? null) ? $birthHistory['breastfeeding_date_display'] : '—' }}</dd></div>
                    </dl>
                @endif
            </section>

            <section class="lml-chatbot-member-health__section" aria-labelledby="hh-imm-heading">
                <h2 id="hh-imm-heading" class="lml-chatbot-member-health__section-title">
                    <i class="bi bi-shield-plus" aria-hidden="true"></i>
                    <span>Child Immunization</span>
                </h2>
                @if (empty($immunizationDoses) && ! ($immunization['persisted'] ?? false))
                    <p class="lml-chatbot-member-health__empty" role="status">No child immunization record available.</p>
                @elseif (empty($immunizationDoses))
                    <p class="lml-chatbot-member-health__empty" role="status">No vaccine doses recorded.</p>
                @else
                    <dl class="lml-chatbot-member-health__dl">
                        @foreach ($immunizationDoses as $row)
                            <div><dt>{{ $row['label'] }}</dt><dd>{{ $row['value'] }}</dd></div>
                        @endforeach
                    </dl>
                    <p class="lml-chatbot-member-health__meta">
                        FIC: {{ ($immunization['fic']['completed'] ?? false) ? 'Completed' : 'Not completed' }}
                        · CIC: {{ ($immunization['cic']['completed'] ?? false) ? 'Completed' : 'Not completed' }}
                    </p>
                @endif
            </section>

            <section class="lml-chatbot-member-health__section" aria-labelledby="hh-sbi-heading">
                <h2 id="hh-sbi-heading" class="lml-chatbot-member-health__section-title">
                    <i class="bi bi-building" aria-hidden="true"></i>
                    <span>School-Based Immunization</span>
                </h2>
                @if (empty($schoolDoses) && ! ($school['persisted'] ?? false))
                    <p class="lml-chatbot-member-health__empty" role="status">No school-based immunization record available.</p>
                @elseif (empty($schoolDoses))
                    <p class="lml-chatbot-member-health__empty" role="status">No school vaccine doses recorded.</p>
                @else
                    <dl class="lml-chatbot-member-health__dl">
                        @foreach ($schoolDoses as $row)
                            <div><dt>{{ $row['label'] }}</dt><dd>{{ $row['value'] }}</dd></div>
                        @endforeach
                    </dl>
                @endif
            </section>

            <section class="lml-chatbot-member-health__section" aria-labelledby="hh-nut-heading">
                <h2 id="hh-nut-heading" class="lml-chatbot-member-health__section-title">
                    <i class="bi bi-egg-fried" aria-hidden="true"></i>
                    <span>Child Nutrition</span>
                </h2>
                @if (! ($nutrition['persisted'] ?? false))
                    <p class="lml-chatbot-member-health__empty" role="status">No child nutrition record available.</p>
                @else
                    <dl class="lml-chatbot-member-health__dl">
                        <div>
                            <dt>Length at birth</dt>
                            <dd>{{ filled($nutrition['newborn']['length'] ?? null) ? $nutrition['newborn']['length'].' cm' : '—' }}</dd>
                        </div>
                        <div>
                            <dt>Weight at birth</dt>
                            <dd>{{ filled($nutrition['newborn']['weight'] ?? null) ? $nutrition['newborn']['weight'].' kg' : '—' }}</dd>
                        </div>
                        <div>
                            <dt>Initiated breastfeeding</dt>
                            <dd>{{ filled($nutrition['newborn']['breastfeeding_date'] ?? null) ? $nutrition['newborn']['breastfeeding_date'] : '—' }}</dd>
                        </div>
                    </dl>
                @endif
            </section>

            <section class="lml-chatbot-member-health__section" aria-labelledby="hh-dew-heading">
                <h2 id="hh-dew-heading" class="lml-chatbot-member-health__section-title">
                    <i class="bi bi-capsule" aria-hidden="true"></i>
                    <span>Deworming</span>
                </h2>
                @if ($dewormingRows === [])
                    <p class="lml-chatbot-member-health__empty" role="status">No deworming records available.</p>
                @else
                    <ul class="lml-chatbot-member-health__list">
                        @foreach ($dewormingRows as $row)
                            <li>
                                <strong>{{ $row['year'] }} · Round {{ $row['round'] }}</strong>
                                <span>{{ $row['date_given_label'] ?? $row['date_given'] }}</span>
                                <span>SE: {{ $row['se_status'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </article>
    @endcomponent
@endsection
