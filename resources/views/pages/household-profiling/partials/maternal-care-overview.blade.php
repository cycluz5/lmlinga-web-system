@php
    use App\Support\DemoMaternalCare;

    $services = [
        [
            'key' => 'prenatal',
            'label' => 'Prenatal Visits',
            'icon' => 'bi-calendar2-week',
            'route' => 'household-profiling.members.maternal-care.prenatal',
        ],
        [
            'key' => 'immunizations',
            'label' => 'Immunizations',
            'icon' => 'bi-shield-plus',
            'route' => 'household-profiling.members.maternal-care.immunizations',
        ],
        [
            'key' => 'supplementations',
            'label' => 'Supplementation',
            'icon' => 'bi-capsule',
            'route' => 'household-profiling.members.maternal-care.supplementations',
        ],
        [
            'key' => 'laboratory',
            'label' => 'Lab Screening',
            'icon' => 'bi-eyedropper',
            'route' => 'household-profiling.members.maternal-care.laboratory',
        ],
        [
            'key' => 'delivery',
            'label' => 'Delivery & Outcome',
            'icon' => 'bi-balloon-heart',
            'route' => 'household-profiling.members.maternal-care.delivery',
        ],
        [
            'key' => 'postnatal',
            'label' => 'Postnatal Care',
            'icon' => 'bi-heart-pulse',
            'route' => 'household-profiling.members.maternal-care.postnatal',
        ],
    ];
@endphp

<section class="lml-mc__panel" aria-labelledby="lml-mc-overview-title" data-mc-overview>
    <div class="lml-mc__overview-tools">
        <a
            href="{{ route('household-profiling.members.maternal-care.history', $routeParams) }}"
            class="lml-mc__text-link lml-focus-ring"
            data-mc-history-link
        >
            Pregnancy History
        </a>
        <a
            href="{{ route('household-profiling.members.maternal-care.trans-out', $routeParams) }}"
            class="lml-mc__text-link lml-focus-ring"
            data-mc-trans-out-link
        >
            Trans-Out
        </a>
    </div>

    <header class="lml-mc__panel-head">
        <div class="lml-mc__panel-titles">
            <h2 id="lml-mc-overview-title" class="lml-mc__panel-title">OVERVIEW</h2>
            <p class="lml-mc__panel-subtitle">
                Current pregnancy summary for this member.
            </p>
        </div>
    </header>

    <dl class="lml-mc__overview-grid" data-mc-overview-stats>
        <div class="lml-mc__overview-stat lml-mc__overview-stat--primary">
            <dt>Gestational Age</dt>
            <dd>
                <span data-mc-gestational-age>{{ $pregnancy['gestational_age_label'] ?? '—' }}</span>
                <span class="lml-mc__pill" data-mc-trimester>
                    {{ $pregnancy['trimester_label'] ?? '—' }}
                </span>
            </dd>
        </div>
        <div class="lml-mc__overview-stat">
            <dt>Last Menstrual Period</dt>
            <dd>
                @if (! empty($pregnancy['lmp']))
                    <time datetime="{{ $pregnancy['lmp'] }}">{{ $pregnancy['lmp_label'] }}</time>
                @else
                    —
                @endif
            </dd>
        </div>
        <div class="lml-mc__overview-stat">
            <dt>Estimated Date of Delivery</dt>
            <dd>
                @if (! empty($pregnancy['edd']))
                    <time datetime="{{ $pregnancy['edd'] }}">{{ $pregnancy['edd_label'] }}</time>
                @else
                    —
                @endif
            </dd>
        </div>
        <div class="lml-mc__overview-stat">
            <dt>Gravida–Parity</dt>
            <dd>{{ $pregnancy['gravida_parity_label'] ?? '—' }}</dd>
        </div>
    </dl>

    <div class="lml-mc__services" aria-labelledby="lml-mc-services-title">
        <h3 id="lml-mc-services-title" class="lml-mc__section-title">
            MATERNAL CARE SERVICES
        </h3>
        <ul class="lml-mc__service-grid">
            @foreach ($services as $service)
                <li>
                    <a
                        href="{{ route($service['route'], $routeParams) }}"
                        class="lml-mc__service-card lml-focus-ring"
                        data-mc-service="{{ $service['key'] }}"
                    >
                        <span class="lml-mc__service-icon" aria-hidden="true">
                            <i class="bi {{ $service['icon'] }}"></i>
                        </span>
                        <span class="lml-mc__service-label">{{ $service['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</section>
