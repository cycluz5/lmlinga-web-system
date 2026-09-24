{{--
    Shared Environmental Sanitation wizard header:
    back control, program icon/title, stepper, page title.
    Visual structure matches the approved Household Water Supply layout.

    Heading hierarchy (dashboard topbar already owns the page h1):
    - program title → h2
    - workflow page title → h3

    Props (Blade kebab-case → camelCase):
    - current-step / currentStep
    - page-heading / pageHeading
    - page-heading-id / pageHeadingId
    - back-aria-label / backAriaLabel
    - total-steps / totalSteps
    - step-labels / stepLabels (optional list of marker labels, e.g. ['1','1.2','2','3'])
--}}
@props([
    'currentStep' => 1,
    'pageHeading' => 'Household Water Supply Information',
    'pageHeadingId' => null,
    'backAriaLabel' => 'Back',
    'totalSteps' => 3,
    'stepLabels' => null,
])

@php
    $currentStep = max(1, (int) $currentStep);
    $labels = is_array($stepLabels) ? array_values($stepLabels) : null;
    $useCustomLabels = is_array($labels) && count($labels) > 0;

    if ($useCustomLabels) {
        $totalSteps = count($labels);
    } else {
        $totalSteps = max(1, (int) $totalSteps);
    }

    if ($currentStep > $totalSteps) {
        $currentStep = $totalSteps;
    }
@endphp

<div class="lml-hws__intro">
    <div class="lml-hws__intro-shell">
        <button
            type="button"
            class="lml-hws__back lml-focus-ring"
            data-hws-back
            aria-label="{{ $backAriaLabel }}"
        >
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
        </button>

        <div class="lml-hws__intro-center">
            <span class="lml-hws__header-icon" aria-hidden="true">
                <i class="bi bi-heart-pulse-fill"></i>
            </span>
            <h2 class="lml-hws__program-title">
                Environmental Sanitation &amp; Occupational Health Program
            </h2>

            <nav
                class="lml-hws__stepper{{ $useCustomLabels ? ' lml-hws__stepper--labeled' : '' }}"
                aria-label="Household water supply progress"
            >
                <ol class="lml-hws__steps">
                    @for ($step = 1; $step <= $totalSteps; $step++)
                        @php
                            $stepClass = $step < $currentStep
                                ? 'is-complete'
                                : ($step === $currentStep ? 'is-current' : 'is-upcoming');
                            $stepStateLabel = $step < $currentStep
                                ? 'completed'
                                : ($step === $currentStep ? 'current' : 'upcoming');
                            $stepLabel = $useCustomLabels
                                ? (string) ($labels[$step - 1] ?? $step)
                                : (string) $step;
                        @endphp
                        <li
                            class="lml-hws__step {{ $stepClass }}"
                            @if ($step === $currentStep) aria-current="step" @endif
                        >
                            <span class="visually-hidden">
                                Step {{ $stepLabel }} of {{ $totalSteps }}, {{ $stepStateLabel }}
                            </span>
                            <span class="lml-hws__step-marker" aria-hidden="true">
                                @if (! $useCustomLabels && $step < $currentStep)
                                    <i class="bi bi-check-lg"></i>
                                @else
                                    {{ $stepLabel }}
                                @endif
                            </span>
                        </li>
                    @endfor
                </ol>
            </nav>

            <h3
                class="lml-hws__page-title"
                @if (filled($pageHeadingId)) id="{{ $pageHeadingId }}" @endif
            >
                {{ $pageHeading }}
            </h3>
        </div>

        <span class="lml-hws__intro-balance" aria-hidden="true"></span>
    </div>
</div>
