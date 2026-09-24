{{--
    Household Amenities context strip — icon + value (Figma).
    Labels are visually hidden to avoid aria-label / visible-text double announcement.
--}}
@php
    $displayNo = $household['displayNo'] ?? $householdNo;
    $zone = $household['zone'] ?? 'N/A';
@endphp
<section class="lml-amenities__context" aria-label="Household context">
    <div class="lml-amenities__context-item">
        <span class="lml-amenities__context-icon" aria-hidden="true">
            <i class="bi bi-house-door-fill"></i>
        </span>
        <p class="lml-amenities__context-value">
            <span class="visually-hidden">Household Number</span>
            <strong>{{ $displayNo }}</strong>
        </p>
    </div>
    <div class="lml-amenities__context-item">
        <span class="lml-amenities__context-icon" aria-hidden="true">
            <i class="bi bi-person-vcard"></i>
        </span>
        <p class="lml-amenities__context-value">
            <span class="visually-hidden">Socioeconomic Status</span>
            <span class="lml-amenities__ses-badge">{{ $socioeconomic }}</span>
        </p>
    </div>
    <div class="lml-amenities__context-item">
        <span class="lml-amenities__context-icon" aria-hidden="true">
            <i class="bi bi-person-fill"></i>
        </span>
        <p class="lml-amenities__context-value">
            <span class="visually-hidden">Household Head</span>
            <strong>{{ $houseHead }}</strong>
        </p>
    </div>
    <div class="lml-amenities__context-item">
        <span class="lml-amenities__context-icon" aria-hidden="true">
            <i class="bi bi-geo-alt-fill"></i>
        </span>
        <p class="lml-amenities__context-value">
            <span class="visually-hidden">Zone</span>
            <strong>{{ $zone }}</strong>
        </p>
    </div>
</section>
