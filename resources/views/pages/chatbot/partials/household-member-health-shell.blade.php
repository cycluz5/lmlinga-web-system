{{-- Shared chrome for resident health module pages --}}
@php
    $memberId = $memberId ?? '';
    $memberName = $memberName ?? 'Resident';
    $moduleTitle = $moduleTitle ?? 'Health Record';
@endphp

<div class="lml-chatbot-member-health">
    <div class="lml-chatbot-member-health__inner">
        <header class="lml-chatbot-member-health__header">
            <div class="lml-chatbot-member-health__identity">
                <a
                    href="{{ route('chatbot.household.members.show', ['member' => $memberId]) }}"
                    class="lml-chatbot-member-health__back lml-focus-ring"
                    aria-label="Back to Member Information"
                >
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </a>
                <span class="lml-chatbot-member-health__avatar" aria-hidden="true">
                    <i class="bi bi-journal-medical"></i>
                </span>
                <div class="lml-chatbot-member-health__identity-text">
                    <h1 class="lml-chatbot-member-health__title">{{ $moduleTitle }}</h1>
                    <p class="lml-chatbot-member-health__subtitle">{{ $memberName }}</p>
                </div>
            </div>
        </header>

        <main class="lml-chatbot-member-health__main" id="main-content">
            {{ $slot }}
        </main>
    </div>
</div>
