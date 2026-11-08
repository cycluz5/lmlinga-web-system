{{-- Decorative Health Indicator pictograms. Parent supplies aria-hidden. --}}
@props(['name' => ''])

@php
    $name = (string) $name;
@endphp

<svg class="lml-dash-indicator__pictogram" viewBox="0 0 24 24" focusable="false">
    @switch($name)
        @case('lml-pregnant')
            {{-- Side-view pregnant woman --}}
            <circle fill="currentColor" cx="11.4" cy="3.85" r="2.15"/>
            <path fill="currentColor" d="M9.35 6.85c-1.35.25-2.25 1.35-2.35 2.75-.15 2.15 1 3.55 2.25 4.4.4.28.6.78.52 1.28L9.3 21h2.55l.28-4.55c2.55.15 4.55-1.2 5.2-3.35.85-2.7-.55-4.85-2.85-5.45-.75-.2-1.4 0-1.85.35-.3-.95-1.2-1.85-2.28-2.15z"/>
            @break

        @case('lml-breastfeeding')
            {{-- Mother cradling an infant --}}
            <circle fill="currentColor" cx="7.85" cy="4" r="2.15"/>
            <path fill="currentColor" d="M4.7 7.85c.2-1.2 1.45-2.05 3.2-2.1 1.75-.05 3.05.85 3.25 2.1.4 2.25-.2 3.85-1.35 4.85L9.35 21H6.55l.4-7.45C5.5 12.6 4.4 10.35 4.7 7.85z"/>
            <circle fill="currentColor" cx="15.35" cy="9.55" r="2.35"/>
            <ellipse fill="currentColor" cx="15.55" cy="13.85" rx="3.25" ry="2.45"/>
            @break

        @case('lml-family')
            {{-- Two adults and a child --}}
            <circle fill="currentColor" cx="7.1" cy="4.2" r="2"/>
            <path fill="currentColor" d="M3.85 8.15c.15-1.1 1.2-1.85 2.85-1.9 1.7-.05 2.9.8 3.05 1.95.3 2.05-.55 3.7-1.7 4.55L7.7 21H5.35l.25-7.05C4.2 13.15 3.55 11.4 3.85 8.15z"/>
            <circle fill="currentColor" cx="16.9" cy="4.2" r="2"/>
            <path fill="currentColor" d="M13.65 8.15c.15-1.1 1.2-1.85 2.85-1.9 1.7-.05 2.9.8 3.05 1.95.3 2.05-.55 3.7-1.7 4.55L17.5 21h-2.35l.25-7.05c-1.4-.8-2.05-2.55-1.75-5.8z"/>
            <circle fill="currentColor" cx="12" cy="9.55" r="1.7"/>
            <path fill="currentColor" d="M9.55 12.7c.15-.9 1.05-1.5 2.45-1.55 1.4-.05 2.3.6 2.45 1.55.25 1.55-.4 2.75-1.3 3.35L12.9 21h-1.8l.2-5.05c-.95-.55-1.65-1.7-1.75-3.25z"/>
            @break

        @case('lml-family-alert')
            {{-- Household pair with unmet-care mark --}}
            <circle fill="currentColor" cx="7.35" cy="5.15" r="2.05"/>
            <path fill="currentColor" d="M4.1 9.15c.15-1.15 1.25-1.95 3-2 1.8-.05 3 .85 3.15 2.05.3 2.15-.55 3.85-1.8 4.7L8 21H5.55l.3-6.85C4.45 13.3 3.8 11.45 4.1 9.15z"/>
            <circle fill="currentColor" cx="14.15" cy="5.15" r="2.05"/>
            <path fill="currentColor" d="M10.9 9.15c.15-1.15 1.25-1.95 3-2 1.8-.05 3 .85 3.15 2.05.3 2.15-.55 3.85-1.8 4.7L14.8 21h-2.45l.3-6.85c-1.4-.85-2.05-2.7-1.75-5z"/>
            <path fill="currentColor" d="M20.15 3.1 19.35 12h-1.9l-.8-8.9h3.5zm-1.75 11.15a1.45 1.45 0 1 1 0 2.9 1.45 1.45 0 0 1 0-2.9z"/>
            @break

        @case('lml-child-normal')
            {{-- Child with healthy/normal check --}}
            <circle fill="currentColor" cx="10.2" cy="4.15" r="2.15"/>
            <path fill="currentColor" d="M6.85 8.15c.2-1.15 1.45-1.95 3.2-2 1.8-.05 3.05.8 3.25 2 .3 2.05-.5 3.65-1.7 4.5l.35 8.35H8.15l.35-8.3C7.25 11.85 6.55 10.25 6.85 8.15z"/>
            <path fill="currentColor" d="M14.35 14.15 16.1 16l4.05-4.35 1.5 1.4-5.55 5.95-3.2-3.3z"/>
            @break

        @case('lml-child-under')
            {{-- Slimmer child with low-weight mark --}}
            <circle fill="currentColor" cx="12" cy="4.05" r="1.95"/>
            <path fill="currentColor" d="M9.55 7.85c.15-.95 1.15-1.6 2.45-1.65 1.3-.05 2.3.65 2.45 1.65.25 1.85-.45 3.2-1.45 3.95L13.2 21h-2.4l.2-9.2c-1-.7-1.7-2.05-1.45-3.95z"/>
            <path fill="currentColor" d="M16.2 12.35h4.6L18.5 18.1z"/>
            @break

        @case('lml-child-over')
            {{-- Broader child with high-weight mark --}}
            <circle fill="currentColor" cx="10.35" cy="4.2" r="2.2"/>
            <path fill="currentColor" d="M5.7 8.4c.25-1.25 1.7-2.15 4.15-2.2 2.5-.05 4 .95 4.25 2.2.4 2.2-.55 4.05-2.1 5.05L12.3 21H8.35l.3-7.5C6.95 12.5 5.3 10.7 5.7 8.4z"/>
            <path fill="currentColor" d="M16.2 19.75h4.6L18.5 14z"/>
            @break

        @case('lml-infant')
            {{-- Sitting infant / baby --}}
            <circle fill="currentColor" cx="12" cy="7.15" r="4.05"/>
            <path fill="currentColor" d="M6.35 13.35c.7-1.55 3-2.45 5.65-2.45s4.95.9 5.65 2.45c.7 1.55.15 3.15-1.15 4.05L17.6 21h-3.15l-.2-2.35h-4.5L9.55 21H6.4l1.1-3.6c-1.3-.9-1.85-2.5-1.15-4.05z"/>
            @break

        @case('lml-droplet')
            <path fill="currentColor" d="M12 2.55S5.35 10.15 5.35 14.4a6.65 6.65 0 0 0 13.3 0C18.65 10.15 12 2.55 12 2.55z"/>
            @break

        @case('lml-toilet')
            <path fill="currentColor" d="M8 3.25h8c.97 0 1.75.78 1.75 1.75V7.5H19a1 1 0 0 1 1 1v1.7c0 3.55-2.36 6.64-5.7 7.62l.52 2.43H9.18l.52-2.43A7.86 7.86 0 0 1 4 10.2V8.5a1 1 0 0 1 1-1h1.25V5c0-.97.78-1.75 1.75-1.75Zm.25 4.25h7.5V5a.25.25 0 0 0-.25-.25H8.5a.25.25 0 0 0-.25.25v2.5ZM6 9.5v.7A5.86 5.86 0 0 0 11.7 16h.6A5.86 5.86 0 0 0 18 10.2V9.5H6Z"/>
            @break
    @endswitch
</svg>
