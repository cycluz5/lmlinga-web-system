{{-- Shared Risk Assessment age ineligibility notice (FR-14). --}}
@php
    $message = $message ?? \App\Support\RiskAssessmentService::INELIGIBLE_MESSAGE;
@endphp
<section class="lml-risk-assess__ineligible" data-risk-assess-ineligible aria-labelledby="lml-risk-assess-ineligible-title">
    <h2 id="lml-risk-assess-ineligible-title" class="lml-risk-assess__ineligible-title">
        Risk Assessment unavailable
    </h2>
    <p class="lml-risk-assess__ineligible-message">{{ $message }}</p>
</section>
