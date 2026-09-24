/**
 * Compact HTML that mirrors the production Environmental Health Blade contract.
 * Used as the cached canonical shell in JS tests — not the plain JS fallback.
 */

export const EH_SHELL_PLACEHOLDER = 'LML-EH';

export function poisonedLaravelEhStep1Html(householdNo = '600') {
    const body = canonicalEhShellHtml(1, householdNo);
    return body
        .replace(
            'src="/build/assets/app.js"',
            'src="http://60027.0.0.600:8000/build/assets/app-BNw2EkZH.js"',
        )
        .replace(
            'href="/build/assets/app.css"',
            'href="http://60027.0.0.600:8000/build/assets/app-CKtgAao_.css"',
        )
        .replace('data-hws-step="1"', `data-hws-step="${householdNo}"`);
}

function layout(title, body) {
    return `<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8">
<title>${title} - LMLinga</title>
<link rel="stylesheet" href="/build/assets/app.css">
<script type="module" src="/build/assets/app.js"></script>
</head>
<body class="lml-page">
<div class="lml-dashboard" data-lml-offline-root data-offline-actor-id="7">
<aside class="lml-sidebar"></aside>
<div class="lml-dashboard__main">
<main class="lml-dashboard__content" id="main-content">
${body}
</main>
</div>
</div>
</body></html>`;
}

function header(heading) {
    return `<div class="lml-hws__intro">
<div class="lml-hws__intro-shell">
<button type="button" class="lml-hws__back lml-focus-ring" data-hws-back>Back</button>
<div class="lml-hws__intro-center">
<h2 class="lml-hws__program-title">Environmental Sanitation &amp; Occupational Health Program</h2>
<nav class="lml-hws__stepper" aria-label="Household water supply progress"><ol class="lml-hws__steps"></ol></nav>
<h3 class="lml-hws__page-title" id="lml-hws-page-title">${heading}</h3>
</div>
</div>
</div>`;
}

export function canonicalEhShellHtml(step, householdNo = EH_SHELL_PLACEHOLDER) {
    const no = String(householdNo);
    const n = Number(step);
    if (n === 1) {
        return layout('Household Water Supply Information', `
<div class="lml-hws" data-lml-hws data-hws-step="1" data-household-no="${no}" data-spot-mapping-url="/spot-mapping" data-hws-back-url="/spot-mapping">
<div class="lml-hws__body">
${header('Household Water Supply Information')}
<form class="lml-hws__form" data-hws-form method="post" action="/environmental-health/household-water-supply" novalidate data-offline-operation="ENVIRONMENTAL_WATER_SUPPLY_UPDATE" data-offline-eh-step="1" data-offline-parent-household-no="${no}">
<input type="hidden" name="household_no" value="${no}" data-hws-household-no>
<section class="lml-hws__section" aria-labelledby="lml-hws-status-heading">
<div class="lml-hws__status-panel">
<div class="lml-hws__status-panel-header">
<h3 id="lml-hws-status-heading" class="lml-hws__section-title">Water Supply Status</h3>
<p class="lml-hws__safe-water-badge is-pending" data-hws-safe-water-badge role="status">
<span data-hws-safe-water-badge-text>Not yet determined</span>
</p>
</div>
<div class="lml-hws__level-grid" role="radiogroup" data-hws-level-group>
<label class="lml-hws__level-card lml-focus-ring" data-hws-level-card><input type="radio" class="lml-hws__level-input" name="water_supply_status" value="level_i" data-hws-level><span class="lml-hws__level-label">Level I</span></label>
<label class="lml-hws__level-card lml-focus-ring" data-hws-level-card><input type="radio" class="lml-hws__level-input" name="water_supply_status" value="level_ii" data-hws-level><span class="lml-hws__level-label">Level II</span></label>
<label class="lml-hws__level-card lml-focus-ring" data-hws-level-card><input type="radio" class="lml-hws__level-input" name="water_supply_status" value="level_iii" data-hws-level><span class="lml-hws__level-label">Level III</span></label>
<label class="lml-hws__level-card lml-focus-ring" data-hws-level-card><input type="radio" class="lml-hws__level-input" name="water_supply_status" value="others" data-hws-level><span class="lml-hws__level-label">Others</span></label>
</div>
<div class="lml-hws__specify" data-hws-specify hidden>
<label class="lml-hws__specify-label" for="lml-hws-specify-input">Specify Water Source</label>
<input id="lml-hws-specify-input" type="text" class="form-control lml-form-control lml-hws__specify-input" name="specify_water_source" data-hws-specify-input>
</div>
</div>
</section>
<fieldset class="lml-hws__question" data-hws-question="location">
<p class="lml-hws__question-text">Is the water source located within the household premises?</p>
<label class="lml-hws__choice"><input type="radio" name="water_source_location" value="yes" data-hws-location><span>Yes</span></label>
<label class="lml-hws__choice"><input type="radio" name="water_source_location" value="no" data-hws-location><span>No</span></label>
</fieldset>
<fieldset class="lml-hws__question" data-hws-question="availability">
<p class="lml-hws__question-text">Is water available 24 hours a day?</p>
<label class="lml-hws__choice"><input type="radio" name="water_availability" value="yes" data-hws-availability><span>Yes</span></label>
<label class="lml-hws__choice"><input type="radio" name="water_availability" value="no" data-hws-availability><span>No</span></label>
</fieldset>
<div class="lml-hws__actions"><button type="submit" class="lml-hws__next lml-focus-ring" data-hws-next disabled aria-disabled="true">Next</button></div>
</form>
</div>
</div>`);
    }
    if (n === 2) {
        return layout('Validation / Random Sampling / Testing', `
<div class="lml-hws" data-lml-hws data-hws-step="2" data-household-no="${no}" data-hws-back-url="/environmental-health/household-water-supply?household=${no}">
<div class="lml-hws__body">
${header('Validation / Random Sampling / Testing')}
<form class="lml-hws__form" data-hws-form data-hws-step2-form method="post" action="/environmental-health/household-water-supply/${no}/step-2" novalidate data-offline-operation="ENVIRONMENTAL_WATER_SUPPLY_UPDATE" data-offline-eh-step="2" data-offline-parent-household-no="${no}">
<input type="hidden" name="household_no" value="${no}" data-hws-household-no>
<div class="lml-hws__test-grid">
<section class="lml-hws__test-panel">
<h4 class="lml-hws__test-heading">Microbiological Validation</h4>
<input type="date" name="microbiological_test_date" data-hws-micro-date>
<label><input type="radio" name="microbiological_result" value="passed" data-hws-micro-result></label>
<label><input type="radio" name="microbiological_result" value="failed" data-hws-micro-result></label>
</section>
<section class="lml-hws__test-panel">
<h4 class="lml-hws__test-heading">Physico-Chemical Validation</h4>
<input type="date" name="physicochemical_test_date" data-hws-physico-date>
<label><input type="radio" name="physicochemical_result" value="passed" data-hws-physico-result></label>
<label><input type="radio" name="physicochemical_result" value="failed" data-hws-physico-result></label>
</section>
</div>
<div class="lml-hws__actions"><button type="submit" class="lml-hws__next lml-focus-ring" data-hws-next>Next</button></div>
</form>
</div>
</div>`);
    }
    if (n === 3) {
        return layout('Basic Sanitation Facility', `
<div class="lml-hws" data-lml-hws data-hws-step="3" data-household-no="${no}" data-hws-back-url="/environmental-health/household-water-supply/${no}/step-2">
<div class="lml-hws__body">
${header('Basic Sanitation Facility')}
<form class="lml-hws__form" data-hws-form data-hws-step3-form method="post" action="/environmental-health/household-water-supply/${no}/step-3" novalidate data-offline-operation="ENVIRONMENTAL_WATER_SUPPLY_UPDATE" data-offline-eh-step="3" data-offline-parent-household-no="${no}">
<input type="hidden" name="household_no" value="${no}" data-hws-household-no>
<div class="lml-hws__toilet-top">
<label class="lml-hws__field-label" for="lml-hws-toilet-type">Type of Toilet</label>
<select id="lml-hws-toilet-type" name="toilet_type" class="form-select lml-form-control lml-hws__select" data-hws-toilet-type>
<option value=""></option>
<option value="pour_flush_with_septic_tank">Pour/Flush Type with Septic Tank</option>
<option value="pour_flush_connected_to_septic_or_sewer">Pour/Flush Connected to Septic Tank or Sewerage System</option>
<option value="ventilated_improved_pit_latrine">Pour/ Ventilated Pit (VIP) Latrine</option>
<option value="water_sealed_without_septic_tank">Water-Sealed Toilet without Septic Tank</option>
<option value="overhung_latrine">Overhung Latrine (Antipolo Type)</option>
<option value="open_pit_latrine">Open Pit Latrine</option>
<option value="without_toilet">Without Toilet</option>
</select>
</div>
<label><input type="radio" name="open_defecation_practiced" value="yes" data-hws-open-defecation> Yes</label>
<label><input type="radio" name="open_defecation_practiced" value="no" data-hws-open-defecation> No</label>
<label><input type="radio" name="shared_toilet" value="yes" data-hws-shared-toilet> Yes</label>
<label><input type="radio" name="shared_toilet" value="no" data-hws-shared-toilet> No</label>
<label><input type="radio" name="sewage_disposal_method" value="on_site_safely_managed" data-hws-sewage></label>
<label><input type="radio" name="sewage_disposal_method" value="off_site_collected_and_treated" data-hws-sewage></label>
<div class="lml-hws__actions"><button type="submit" class="lml-hws__next lml-focus-ring" data-hws-next>Next</button></div>
</form>
</div>
</div>`);
    }
    return layout('Solid Waste Management', `
<div class="lml-hws" data-lml-hws data-hws-step="4" data-household-no="${no}" data-hws-back-url="/environmental-health/household-water-supply/${no}/step-3">
<div class="lml-hws__body">
${header('Solid Waste Management')}
<form class="lml-hws__form" data-hws-form data-hws-step4-form method="post" action="/environmental-health/household-water-supply/${no}/step-4" novalidate data-offline-operation="ENVIRONMENTAL_WATER_SUPPLY_UPDATE" data-offline-eh-step="4" data-offline-parent-household-no="${no}">
<input type="hidden" name="household_no" value="${no}" data-hws-household-no>
<fieldset class="lml-hws__solid-card">
<legend class="lml-hws__question-title">Waste Management Practices</legend>
<div class="lml-hws__check-col" data-hws-solid-practices-group>
<label class="lml-hws__choice"><input type="checkbox" name="solid_waste_practices[]" value="waste_segregation" data-hws-solid-practice><span>Waste Segregation</span></label>
<label class="lml-hws__choice"><input type="checkbox" name="solid_waste_practices[]" value="backyard_composting" data-hws-solid-practice><span>Backyard Composting</span></label>
<label class="lml-hws__choice"><input type="checkbox" name="solid_waste_practices[]" value="recycling_reuse" data-hws-solid-practice><span>Recycling / Reuse</span></label>
<label class="lml-hws__choice"><input type="checkbox" name="solid_waste_practices[]" value="municipal_collection" data-hws-solid-practice><span>Collected by Municipality / Municipal Collection and Disposal System</span></label>
</div>
</fieldset>
<div class="lml-hws__actions"><button type="submit" class="lml-hws__next lml-focus-ring" data-hws-next>Next</button></div>
</form>
</div>
</div>`);
}

export function plainEhFallbackHtml(householdNo = '132', step = 1) {
    const no = String(householdNo);
    return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Household Water Supply Information - LMLinga</title></head>
<body data-lml-offline-root>
<div class="lml-hws" data-lml-hws data-hws-step="${Number(step)}" data-household-no="${no}">
<p class="lml-hh-view__sync-badge">Waiting to sync</p>
<p data-hws-household-label>${no}</p>
<form class="lml-hws__form" data-hws-form method="post" action="/environmental-health/household-water-supply" data-offline-operation="ENVIRONMENTAL_WATER_SUPPLY_UPDATE" data-offline-eh-step="${Number(step)}" data-offline-parent-household-no="${no}">
<div data-hws-level-group>
<label data-hws-level-card><input type="radio" name="water_supply_status" value="level_i" data-hws-level> Level I</label>
<label data-hws-level-card><input type="radio" name="water_supply_status" value="level_ii" data-hws-level> Level II</label>
<label data-hws-level-card><input type="radio" name="water_supply_status" value="level_iii" data-hws-level> Level III</label>
<label data-hws-level-card><input type="radio" name="water_supply_status" value="others" data-hws-level> Others</label>
</div>
<label><input type="radio" name="water_source_location" value="yes"> Yes</label>
<label><input type="radio" name="water_source_location" value="no"> No</label>
<label><input type="radio" name="water_availability" value="yes"> Yes</label>
<label><input type="radio" name="water_availability" value="no"> No</label>
<button type="submit" class="lml-hws__next" data-hws-next>Next</button>
</form>
</div>
</body></html>`;
}
