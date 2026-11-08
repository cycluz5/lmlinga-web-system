/**
 * Household Water Supply Information — wizard interactions.
 * Demo persistence via Laravel session (form POST) + sessionStorage draft.
 */

const STORAGE_KEY = 'lml_household_water_supply_draft';
const STEP2_STORAGE_KEY = 'lml_household_water_supply_step2_draft';
const STEP3_STORAGE_KEY = 'lml_household_water_supply_step3_draft';
const STEP4_STORAGE_KEY = 'lml_household_water_supply_step4_draft';
const PENDING_KEY = 'lml_pending_water_supply_household';
const DRAFT_NOTICE_MS = 5500;

const ERROR_MESSAGES = {
    water_supply_status: 'Please select a water supply level.',
    specify_water_source: 'Please specify the water source.',
    water_source_location: 'Please select water source location.',
    water_availability: 'Please indicate water availability.',
};

const STEP2_ERROR_MESSAGES = {
    microbiological_test_date: 'Please select the microbiological test date.',
    microbiological_result: 'Please select the microbiological test result.',
    physicochemical_test_date: 'Please select the physico-chemical test date.',
    physicochemical_result: 'Please select the physico-chemical test result.',
};

const STEP3_ERROR_MESSAGES = {
    toilet_type: 'Please select the type of toilet.',
    open_defecation_practiced: 'Please indicate whether open defecation is practiced.',
    shared_toilet: 'Please indicate whether the toilet facility is shared.',
    sewage_disposal_method: 'Please select the excreta or sewage disposal method.',
};

const STEP4_ERROR_MESSAGES = {
    solid_waste_practices: 'Please select at least one solid waste management practice.',
};

const SANITARY_TOILET_TYPES = [
    'pour_flush_with_septic_tank',
    'pour_flush_connected_to_septic_or_sewer',
    'ventilated_improved_pit_latrine',
];

const UNSANITARY_TOILET_TYPES = [
    'water_sealed_without_septic_tank',
    'overhung_latrine',
    'open_pit_latrine',
    'without_toilet',
];

const SEWAGE_DISPOSAL_METHODS = [
    'on_site_safely_managed',
    'off_site_collected_and_treated',
];

function deriveToiletStatus(toiletType) {
    if (SANITARY_TOILET_TYPES.includes(toiletType)) {
        return 'sanitary';
    }
    if (UNSANITARY_TOILET_TYPES.includes(toiletType)) {
        return 'unsanitary';
    }
    return null;
}

/**
 * Mirrors DemoHouseholdWaterSupply::deriveManagementStatus (display-only).
 */
function deriveManagementStatus(toiletType, sewageDisposalMethod) {
    if (!toiletType) {
        return 'not_yet_determined';
    }

    const toiletStatus = deriveToiletStatus(toiletType);

    if (toiletStatus === 'unsanitary') {
        return 'not_safely_managed';
    }

    if (toiletStatus === 'sanitary') {
        if (SEWAGE_DISPOSAL_METHODS.includes(sewageDisposalMethod)) {
            return 'safely_managed';
        }
        return 'not_yet_determined';
    }

    return 'not_yet_determined';
}

function isWithoutToilet(toiletType) {
    return toiletType === 'without_toilet';
}

const SAFE_WATER_LABELS = {
    with_basic_safe_water: 'With Basic Safe Water',
    without_basic_safe_water: 'Without Basic Safe Water',
    not_yet_determined: 'Not yet determined',
};

function deriveBasicSafeWaterStatus(level) {
    if (level === 'level_i' || level === 'level_ii' || level === 'level_iii') {
        return 'with_basic_safe_water';
    }
    if (level === 'others') {
        return 'without_basic_safe_water';
    }
    return 'not_yet_determined';
}

function checkedRadioValue(root, selector) {
    if (!root?.querySelector) {
        return '';
    }
    const byPseudo = root.querySelector(`${selector}:checked`);
    if (byPseudo) {
        return String(byPseudo.value || '');
    }
    const live = Array.from(root.querySelectorAll(selector)).find((input) => Boolean(input.checked));
    return live ? String(live.value || '') : '';
}

function readStep1State(root) {
    return {
        level: checkedRadioValue(root, '[data-hws-level]'),
        location: checkedRadioValue(root, '[data-hws-location]'),
        availability: checkedRadioValue(root, '[data-hws-availability]'),
        specify: String(root?.querySelector?.('[data-hws-specify-input]')?.value || '').trim(),
    };
}

function eventElement(event) {
    const target = event?.target;
    if (!target) {
        return null;
    }
    return target.nodeType === 1 ? target : (target.parentElement || null);
}

function closestAny(el, selectors) {
    if (!el?.closest) {
        return null;
    }
    for (const selector of selectors) {
        const found = el.closest(selector);
        if (found) {
            return found;
        }
    }
    const label = el.matches?.('label') ? el : el.closest?.('label');
    if (label) {
        for (const selector of selectors) {
            if (label.matches?.(selector)) {
                return label;
            }
            const nested = label.querySelector(selector);
            if (nested) {
                return nested;
            }
        }
    }
    return null;
}

const step1BridgedDocs = new WeakSet();

function isStep1Host(host) {
    if (!host) {
        return false;
    }
    if ((host.getAttribute('data-hws-step') || '1') === '1') {
        return true;
    }
    return Boolean(host.querySelector?.('[data-hws-level]'));
}

function radioFromEventElement(el) {
    if (!el) {
        return null;
    }
    if (el.matches?.('input[type="radio"]')) {
        return el;
    }
    const label = el.matches?.('label') ? el : el.closest?.('label');
    if (label) {
        return label.querySelector('input[type="radio"]');
    }
    const card = el.closest?.('[data-hws-level-card]');
    if (card) {
        return card.querySelector('[data-hws-level]');
    }
    return null;
}

function checkRadioInRoot(root, radio) {
    if (!radio || radio.disabled) {
        return radio;
    }
    const name = String(radio.getAttribute('name') || radio.name || '');
    const scope = radio.closest?.('[data-hws-form]') || root;
    const group = name
        ? Array.from((scope || root).querySelectorAll(`[name="${name}"]`)).filter((el) => (
            String(el.type || el.getAttribute?.('type') || '').toLowerCase() === 'radio'
        ))
        : [radio];
    group.forEach((other) => {
        other.checked = other === radio;
    });
    radio.checked = true;
    return radio;
}

function refreshStep1Host(host) {
    if (typeof host._hwsRefresh === 'function') {
        host._hwsRefresh();
        return true;
    }
    return initHouseholdWaterSupply(host);
}

function syncStep1FromLiveEvent(host, event) {
    const el = eventElement(event);
    if (!el || !isStep1Host(host)) {
        return false;
    }
    if (typeof host.contains === 'function' && !host.contains(el)) {
        return false;
    }
    const related = closestAny(el, [
        '[data-hws-level]',
        '[data-hws-level-card]',
        '[data-hws-location]',
        '[data-hws-availability]',
        '[data-hws-specify-input]',
        '[data-hws-specify]',
    ]);
    if (!related) {
        return false;
    }
    if (event.type === 'click' || event.type === 'change') {
        checkRadioInRoot(host, radioFromEventElement(related) || radioFromEventElement(el));
    }
    refreshStep1Host(host);
    return true;
}

function ensureLiveStep1Bridge(doc) {
    if (!doc || typeof doc.addEventListener !== 'function' || step1BridgedDocs.has(doc)) {
        return;
    }
    step1BridgedDocs.add(doc);
    const sync = (event) => {
        const el = eventElement(event);
        if (!el?.closest) {
            return;
        }
        const host = el.closest('[data-lml-hws]');
        if (!host || !isStep1Host(host)) {
            return;
        }
        syncStep1FromLiveEvent(host, event);
    };
    doc.addEventListener('change', sync);
    doc.addEventListener('input', sync);
    doc.addEventListener('click', sync);
}

function resolveHwsRoot(root) {
    if (!root) {
        return null;
    }
    if (typeof root.matches === 'function' && root.matches('[data-lml-hws]')) {
        return root;
    }
    return typeof root.querySelector === 'function' ? root.querySelector('[data-lml-hws]') : null;
}

function draftStorageKeyForStep(step) {
    if (step === '2') {
        return STEP2_STORAGE_KEY;
    }
    if (step === '3') {
        return STEP3_STORAGE_KEY;
    }
    if (step === '4') {
        return STEP4_STORAGE_KEY;
    }
    return STORAGE_KEY;
}

function getFocusable(container) {
    return Array.from(
        container.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
    ).filter((el) => el.offsetParent !== null || el === document.activeElement);
}

function lockPageScroll() {
    document.body.dataset.hwsScrollLocked = 'true';
    document.body.style.overflow = 'hidden';
}

function unlockPageScroll() {
    if (document.body.dataset.hwsScrollLocked !== 'true') {
        return;
    }

    delete document.body.dataset.hwsScrollLocked;
    document.body.style.overflow = '';
}

function trapFocus(event, panel) {
    if (event.key !== 'Tab') {
        return;
    }

    const focusables = getFocusable(panel);
    if (!focusables.length) {
        event.preventDefault();
        panel.focus();
        return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
        return;
    }

    if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function readPendingHousehold() {
    try {
        const raw = sessionStorage.getItem(PENDING_KEY);
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : null;
    } catch {
        return null;
    }
}

function clearPendingHousehold() {
    try {
        sessionStorage.removeItem(PENDING_KEY);
    } catch {
        // Ignore storage failures in private browsing.
    }
}

function readDraft(householdNo, storageKey = STORAGE_KEY) {
    if (!householdNo) {
        return null;
    }

    try {
        const raw = sessionStorage.getItem(storageKey);
        if (!raw) {
            return null;
        }
        const all = JSON.parse(raw);
        const draft = all?.[householdNo];
        return draft && typeof draft === 'object' ? draft : null;
    } catch {
        return null;
    }
}

function writeDraft(householdNo, draft, storageKey = STORAGE_KEY) {
    if (!householdNo) {
        return;
    }

    try {
        const raw = sessionStorage.getItem(storageKey);
        const all = raw ? JSON.parse(raw) : {};
        all[householdNo] = {
            ...draft,
            updatedAt: new Date().toISOString(),
        };
        sessionStorage.setItem(storageKey, JSON.stringify(all));
    } catch {
        // Ignore storage failures in private browsing.
    }
}

function clearDraft(householdNo, storageKey = STORAGE_KEY) {
    if (!householdNo) {
        return;
    }

    try {
        const raw = sessionStorage.getItem(storageKey);
        if (!raw) {
            return;
        }
        const all = JSON.parse(raw);
        if (all && typeof all === 'object') {
            delete all[householdNo];
            sessionStorage.setItem(storageKey, JSON.stringify(all));
        }
    } catch {
        // Ignore storage failures in private browsing.
    }
}

function draftHasContent(draft) {
    if (!draft || typeof draft !== 'object') {
        return false;
    }

    return Boolean(
        draft.water_supply_status
        || draft.specify_water_source
        || draft.water_source_location
        || draft.water_availability
    );
}

function step2DraftHasContent(draft) {
    if (!draft || typeof draft !== 'object') {
        return false;
    }

    return Boolean(
        draft.microbiological_test_date
        || draft.microbiological_result
        || draft.physicochemical_test_date
        || draft.physicochemical_result
    );
}

function step3DraftHasContent(draft) {
    if (!draft || typeof draft !== 'object') {
        return false;
    }

    return Boolean(
        draft.toilet_type
        || draft.open_defecation_practiced
        || draft.shared_toilet
        || draft.sewage_disposal_method
    );
}

function step4DraftHasContent(draft) {
    if (!draft || typeof draft !== 'object') {
        return false;
    }

    return Array.isArray(draft.solid_waste_practices) && draft.solid_waste_practices.length > 0;
}

function showDraftRestoredNotice(root) {
    if (root.querySelector('[data-hws-draft-notice]')) {
        return;
    }

    const notice = document.createElement('div');
    notice.className = 'lml-hws__draft-notice';
    notice.setAttribute('data-hws-draft-notice', '');
    notice.setAttribute('role', 'status');
    notice.setAttribute('aria-live', 'polite');

    const text = document.createElement('p');
    text.className = 'lml-hws__draft-notice-text';
    text.textContent = 'Draft restored from your previous session.';

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'lml-hws__draft-notice-dismiss lml-focus-ring';
    dismiss.setAttribute('aria-label', 'Dismiss draft restored notice');
    dismiss.textContent = 'Dismiss';

    notice.appendChild(text);
    notice.appendChild(dismiss);

    const intro = root.querySelector('.lml-hws__intro');
    if (intro?.parentNode) {
        intro.insertAdjacentElement('afterend', notice);
    } else {
        root.querySelector('.lml-hws__body')?.prepend(notice);
    }

    let hideTimer = window.setTimeout(() => {
        notice.remove();
    }, DRAFT_NOTICE_MS);

    dismiss.addEventListener('click', () => {
        window.clearTimeout(hideTimer);
        notice.remove();
    });
}

/**
 * :has() fallback for selected status cards (visual class only).
 */
function syncSelectedLevelCards(levelInputs) {
    levelInputs.forEach((input) => {
        const card = input.closest('[data-hws-level-card]');
        if (!card) {
            return;
        }
        card.classList.toggle('is-selected', Boolean(input.checked));
    });
}

function enableToggleRadios(inputs) {
    inputs.forEach((input) => {
        input.addEventListener('click', () => {
            if (input.dataset.hwsWasChecked === 'true') {
                input.checked = false;
                input.dataset.hwsWasChecked = 'false';
                input.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }

            inputs.forEach((other) => {
                other.dataset.hwsWasChecked = other === input && other.checked ? 'true' : 'false';
            });
        });
    });
}

function initSharedShell(root) {
    const form = root.querySelector('[data-hws-form]');
    const backBtn = root.querySelector('[data-hws-back]');
    const leaveTriggers = Array.from(root.querySelectorAll('[data-hws-leave]'));
    const spotMappingUrl = root.getAttribute('data-spot-mapping-url') || '/spot-mapping';
    const backUrl = root.getAttribute('data-hws-back-url') || spotMappingUrl;
    const alwaysConfirmLeave = root.hasAttribute('data-hws-confirm-leave');
    const householdInput = root.querySelector('[data-hws-household-no]');
    const dialog = root.querySelector('[data-hws-dialog]');
    const dialogPanel = root.querySelector('[data-hws-dialog-panel]');
    const dialogMessage = root.querySelector('[data-hws-dialog-message]');
    const stayBtn = root.querySelector('[data-hws-dialog-stay]');
    const leaveBtn = root.querySelector('[data-hws-dialog-leave]');
    const defaultDialogMessage = dialogMessage?.textContent?.trim() || 'Are you sure you want to leave this step?';
    const step = root.getAttribute('data-hws-step') || '1';
    const draftStorageKey = draftStorageKeyForStep(step);

    let householdNo = (root.getAttribute('data-household-no') || '').trim();
    let dirty = false;
    let allowLeave = false;
    let pendingLeaveUrl = backUrl;
    let clearDraftOnLeave = true;

    const pending = readPendingHousehold();
    if (!householdNo && pending?.householdNo) {
        householdNo = String(pending.householdNo).trim();
        if (householdInput) {
            householdInput.value = householdNo;
        }
        root.setAttribute('data-household-no', householdNo);
        const label = root.querySelector('[data-hws-household-label]');
        if (label) {
            label.textContent = householdNo;
        }
    }

    function openDialog(returnFocusEl, message) {
        if (!dialog || !dialogPanel) {
            return;
        }

        if (dialogMessage && message) {
            dialogMessage.textContent = message;
        }

        root._hwsReturnFocus = returnFocusEl || null;
        dialog.hidden = false;
        lockPageScroll();
        stayBtn?.focus();
    }

    function closeDialog({ restoreFocus = true } = {}) {
        if (!dialog) {
            return;
        }

        dialog.hidden = true;
        unlockPageScroll();

        if (dialogMessage) {
            dialogMessage.textContent = defaultDialogMessage;
        }

        if (restoreFocus && root._hwsReturnFocus instanceof HTMLElement) {
            root._hwsReturnFocus.focus();
        }

        root._hwsReturnFocus = null;
    }

    function navigateAway(url) {
        allowLeave = true;
        clearPendingHousehold();
        window.location.href = url || spotMappingUrl;
    }

    function requestLeave(returnFocusEl, {
        url = backUrl,
        forceConfirm = alwaysConfirmLeave,
        message = null,
        clearLocalDraft = false,
    } = {}) {
        pendingLeaveUrl = url;
        clearDraftOnLeave = clearLocalDraft;

        const needsConfirm = forceConfirm || dirty;
        if (!needsConfirm) {
            if (clearLocalDraft) {
                clearDraft(householdNo, draftStorageKey);
            }
            navigateAway(url);
            return;
        }

        openDialog(
            returnFocusEl,
            message || (
                dirty
                    ? 'You have unsaved changes. Are you sure you want to leave this step?'
                    : defaultDialogMessage
            )
        );
    }

    stayBtn?.addEventListener('click', () => {
        closeDialog();
    });

    leaveBtn?.addEventListener('click', () => {
        closeDialog({ restoreFocus: false });
        if (form && clearDraftOnLeave) {
            clearDraft(householdNo, draftStorageKey);
        }
        navigateAway(pendingLeaveUrl);
    });

    dialog?.addEventListener('click', (event) => {
        if (event.target === dialog) {
            closeDialog();
        }
    });

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && dialog && !dialog.hidden) {
            event.preventDefault();
            closeDialog();
            return;
        }

        if (dialog && !dialog.hidden && dialogPanel) {
            trapFocus(event, dialogPanel);
        }
    });

    backBtn?.addEventListener('click', (event) => {
        event.preventDefault();
        let leaveMessage = defaultDialogMessage;
        if (form) {
            if (step === '2') {
                leaveMessage = 'You have unsaved changes. Are you sure you want to return to Step 1?';
            } else if (step === '3') {
                leaveMessage = 'You have unsaved changes. Are you sure you want to return to Validation / Random Sampling / Testing?';
            } else {
                leaveMessage = 'You have unsaved changes. Are you sure you want to return to Spot Mapping?';
            }
        }

        requestLeave(backBtn, {
            url: backUrl,
            forceConfirm: alwaysConfirmLeave,
            clearLocalDraft: Boolean(form) && !alwaysConfirmLeave && step !== '2' && step !== '3',
            message: leaveMessage,
        });
    });

    leaveTriggers.forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            const url = trigger.getAttribute('data-hws-leave-url') || spotMappingUrl;
            requestLeave(trigger, {
                url,
                forceConfirm: true,
                message: 'Are you sure you want to return to Spot Mapping?',
            });
        });
    });

    window.addEventListener('beforeunload', (event) => {
        if (allowLeave || !dirty) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });

    return {
        form,
        householdNo,
        backUrl,
        draftStorageKey,
        requestLeave,
        setDirty(value) {
            dirty = Boolean(value);
        },
        setAllowLeave(value) {
            allowLeave = Boolean(value);
        },
        isDirty() {
            return dirty;
        },
    };
}

function initStep2Form(root, shell) {
    const { form, householdNo, backUrl, draftStorageKey, requestLeave, setDirty, setAllowLeave } = shell;

    if (!form) {
        return;
    }

    const nextBtn = root.querySelector('[data-hws-next]');
    const previousBtn = root.querySelector('[data-hws-previous]');
    const microDateInput = root.querySelector('[data-hws-micro-date]');
    const physicoDateInput = root.querySelector('[data-hws-physico-date]');
    const microResultInputs = Array.from(root.querySelectorAll('[data-hws-micro-result]'));
    const physicoResultInputs = Array.from(root.querySelectorAll('[data-hws-physico-result]'));
    let submitting = false;
    let initialSnapshot = '';

    const touched = {
        microbiological_test_date: false,
        microbiological_result: false,
        physicochemical_test_date: false,
        physicochemical_result: false,
    };

    function selectedValue(inputs) {
        const checked = inputs.find((input) => input.checked);
        return checked ? checked.value : '';
    }

    function getState() {
        return {
            microbiological_test_date: (microDateInput?.value || '').trim(),
            microbiological_result: selectedValue(microResultInputs),
            physicochemical_test_date: (physicoDateInput?.value || '').trim(),
            physicochemical_result: selectedValue(physicoResultInputs),
        };
    }

    function snapshot(state = getState()) {
        return JSON.stringify(state);
    }

    function setError(field, message) {
        const el = root.querySelector(`[data-hws-error="${field}"]`);
        if (!el) {
            return;
        }
        el.textContent = message || '';
        el.hidden = !message;
    }

    function clearError(field) {
        setError(field, '');
    }

    function markFieldInvalid(input, invalid) {
        if (!input) {
            return;
        }
        input.classList.toggle('is-invalid', Boolean(invalid));
        if (invalid) {
            input.setAttribute('aria-invalid', 'true');
        } else {
            input.removeAttribute('aria-invalid');
        }
    }

    function markResultGroupInvalid(resultInputs, invalid) {
        const group = resultInputs[0]?.closest('.lml-hws__radio-row')
            || resultInputs[0]?.closest('[role="radiogroup"]');

        if (group) {
            group.classList.toggle('is-invalid', Boolean(invalid));
            if (invalid) {
                group.setAttribute('aria-invalid', 'true');
            } else {
                group.removeAttribute('aria-invalid');
            }
        }

        resultInputs.forEach((input) => {
            if (invalid) {
                input.setAttribute('aria-invalid', 'true');
            } else {
                input.removeAttribute('aria-invalid');
            }
        });
    }

    function validateSection({ date, result, dateField, resultField, dateInput, resultInputs, show }) {
        let firstInvalid = null;
        const hasDate = Boolean(date);
        const hasResult = Boolean(result);
        const resultMissing = hasDate && !hasResult;
        const dateMissing = hasResult && !hasDate;

        if (resultMissing) {
            if (show) {
                setError(resultField, STEP2_ERROR_MESSAGES[resultField]);
                markResultGroupInvalid(resultInputs, true);
            } else {
                clearError(resultField);
                markResultGroupInvalid(resultInputs, false);
            }
            firstInvalid = resultInputs[0] || null;
        } else {
            clearError(resultField);
            markResultGroupInvalid(resultInputs, false);
        }

        if (dateMissing) {
            if (show) {
                setError(dateField, STEP2_ERROR_MESSAGES[dateField]);
                markFieldInvalid(dateInput, true);
            } else {
                clearError(dateField);
                markFieldInvalid(dateInput, false);
            }
            firstInvalid = firstInvalid || dateInput;
        } else {
            clearError(dateField);
            markFieldInvalid(dateInput, false);
        }

        return {
            valid: !(resultMissing || dateMissing),
            firstInvalid,
        };
    }

    function validateInline({ showAll = false } = {}) {
        const state = getState();
        const shouldShow = (field) => showAll || touched[field];

        const micro = validateSection({
            date: state.microbiological_test_date,
            result: state.microbiological_result,
            dateField: 'microbiological_test_date',
            resultField: 'microbiological_result',
            dateInput: microDateInput,
            resultInputs: microResultInputs,
            show: shouldShow('microbiological_test_date') || shouldShow('microbiological_result'),
        });

        const physico = validateSection({
            date: state.physicochemical_test_date,
            result: state.physicochemical_result,
            dateField: 'physicochemical_test_date',
            resultField: 'physicochemical_result',
            dateInput: physicoDateInput,
            resultInputs: physicoResultInputs,
            show: shouldShow('physicochemical_test_date') || shouldShow('physicochemical_result'),
        });

        return {
            valid: micro.valid && physico.valid,
            firstInvalid: micro.firstInvalid || physico.firstInvalid,
            state,
        };
    }

    function markDirty() {
        setDirty(snapshot() !== initialSnapshot);
        writeDraft(householdNo, getState(), draftStorageKey);
        validateInline({ showAll: false });
    }

    function applyDraft(draft) {
        if (!draft) {
            return false;
        }

        if (microDateInput && draft.microbiological_test_date) {
            microDateInput.value = draft.microbiological_test_date;
        }

        if (draft.microbiological_result) {
            const match = microResultInputs.find((input) => input.value === draft.microbiological_result);
            if (match) {
                match.checked = true;
                match.dataset.hwsWasChecked = 'true';
            }
        }

        if (physicoDateInput && draft.physicochemical_test_date) {
            physicoDateInput.value = draft.physicochemical_test_date;
        }

        if (draft.physicochemical_result) {
            const match = physicoResultInputs.find((input) => input.value === draft.physicochemical_result);
            if (match) {
                match.checked = true;
                match.dataset.hwsWasChecked = 'true';
            }
        }

        return step2DraftHasContent(draft);
    }

    enableToggleRadios(microResultInputs);
    enableToggleRadios(physicoResultInputs);

    const restored = applyDraft(readDraft(householdNo, draftStorageKey));
    initialSnapshot = snapshot();
    setDirty(false);
    validateInline({ showAll: false });

    function syncServerInvalidState() {
        const microResultErr = root.querySelector('[data-hws-error="microbiological_result"]');
        const physicoResultErr = root.querySelector('[data-hws-error="physicochemical_result"]');
        const microDateErr = root.querySelector('[data-hws-error="microbiological_test_date"]');
        const physicoDateErr = root.querySelector('[data-hws-error="physicochemical_test_date"]');

        if (microResultErr && !microResultErr.hidden && microResultErr.textContent.trim()) {
            markResultGroupInvalid(microResultInputs, true);
        }
        if (physicoResultErr && !physicoResultErr.hidden && physicoResultErr.textContent.trim()) {
            markResultGroupInvalid(physicoResultInputs, true);
        }
        if (microDateErr && !microDateErr.hidden && microDateErr.textContent.trim()) {
            markFieldInvalid(microDateInput, true);
        }
        if (physicoDateErr && !physicoDateErr.hidden && physicoDateErr.textContent.trim()) {
            markFieldInvalid(physicoDateInput, true);
        }
    }

    syncServerInvalidState();

    if (restored) {
        showDraftRestoredNotice(root);
    }

    const focusTarget = root.querySelector('.lml-hws__date-input.is-invalid, .lml-hws__radio-row.is-invalid input')
        || root.querySelector('.lml-hws__error:not([hidden])')?.closest('.lml-hws__field, .lml-hws__result-fieldset')
            ?.querySelector('input');

    if (focusTarget) {
        window.setTimeout(() => {
            focusTarget.focus?.();
        }, 0);
    }

    previousBtn?.addEventListener('click', (event) => {
        event.preventDefault();
        requestLeave(previousBtn, {
            url: backUrl,
            clearLocalDraft: false,
            message: 'You have unsaved changes. Are you sure you want to return to Step 1?',
        });
    });

    microDateInput?.addEventListener('change', () => {
        touched.microbiological_test_date = true;
        markDirty();
    });

    physicoDateInput?.addEventListener('change', () => {
        touched.physicochemical_test_date = true;
        markDirty();
    });

    microResultInputs.forEach((input) => {
        input.addEventListener('change', () => {
            touched.microbiological_result = true;
            markDirty();
        });
    });

    physicoResultInputs.forEach((input) => {
        input.addEventListener('change', () => {
            touched.physicochemical_result = true;
            markDirty();
        });
    });

    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }

        Object.keys(touched).forEach((key) => {
            touched[key] = true;
        });

        const result = validateInline({ showAll: true });
        if (!result.valid) {
            event.preventDefault();
            result.firstInvalid?.focus?.();
            result.firstInvalid?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
            return;
        }

        submitting = true;
        setAllowLeave(true);
        setDirty(false);
        clearDraft(householdNo, draftStorageKey);
        clearPendingHousehold();

        if (nextBtn) {
            nextBtn.disabled = true;
            nextBtn.setAttribute('aria-disabled', 'true');
        }
    });

    root._hwsRefresh = () => {
        validateInline({ showAll: false });
    };
}

function initStep1Form(root, shell) {
    const { form, householdNo, draftStorageKey, setDirty, setAllowLeave } = shell;

    if (!form) {
        return;
    }

    let initialSnapshot = '';

    const touched = {
        water_supply_status: false,
        specify_water_source: false,
        water_source_location: false,
        water_availability: false,
    };

    function levelInputs() {
        return Array.from(root.querySelectorAll('[data-hws-level]'));
    }

    function locationInputs() {
        return Array.from(root.querySelectorAll('[data-hws-location]'));
    }

    function availabilityInputs() {
        return Array.from(root.querySelectorAll('[data-hws-availability]'));
    }

    function liveSpecifyWrap() {
        return root.querySelector('[data-hws-specify]');
    }

    function liveSpecifyInput() {
        return root.querySelector('[data-hws-specify-input]');
    }

    function liveBadge() {
        return root.querySelector('[data-hws-safe-water-badge]');
    }

    function liveBadgeText() {
        return root.querySelector('[data-hws-safe-water-badge-text]');
    }

    function liveNext() {
        return root.querySelector('[data-hws-next]');
    }

    function syncSafeWaterBadge() {
        const safeWaterBadge = liveBadge();
        const safeWaterBadgeText = liveBadgeText();
        if (!safeWaterBadge || !safeWaterBadgeText) {
            return;
        }

        const status = deriveBasicSafeWaterStatus(readStep1State(root).level);
        safeWaterBadge.classList.remove('is-pending', 'is-with', 'is-without');

        if (status === 'with_basic_safe_water') {
            safeWaterBadge.classList.add('is-with');
            safeWaterBadgeText.textContent = SAFE_WATER_LABELS.with_basic_safe_water;
            return;
        }

        if (status === 'without_basic_safe_water') {
            safeWaterBadge.classList.add('is-without');
            safeWaterBadgeText.textContent = SAFE_WATER_LABELS.without_basic_safe_water;
            return;
        }

        safeWaterBadge.classList.add('is-pending');
        safeWaterBadgeText.textContent = SAFE_WATER_LABELS.not_yet_determined;
    }

    function getState() {
        const live = readStep1State(root);
        return {
            water_supply_status: live.level,
            specify_water_source: live.level === 'others' ? live.specify : '',
            water_source_location: live.location,
            water_availability: live.availability,
        };
    }

    function snapshot(state = getState()) {
        return JSON.stringify(state);
    }

    function setError(field, message) {
        const el = root.querySelector(`[data-hws-error="${field}"]`);
        if (!el) {
            return;
        }
        el.textContent = message || '';
        el.hidden = !message;
    }

    function clearError(field) {
        setError(field, '');
    }

    function syncSpecifyVisibility({ clearWhenHidden = true } = {}) {
        const status = readStep1State(root).level;
        const show = status === 'others';
        const specifyWrap = liveSpecifyWrap();
        const specifyInput = liveSpecifyInput();

        if (!specifyWrap) {
            return;
        }

        specifyWrap.hidden = !show;

        if (!show && clearWhenHidden && specifyInput) {
            specifyInput.value = '';
            specifyInput.classList.remove('is-invalid');
            clearError('specify_water_source');
            touched.specify_water_source = false;
        }

        if (specifyInput) {
            specifyInput.required = show;
            if (show) {
                specifyInput.setAttribute('aria-required', 'true');
            } else {
                specifyInput.removeAttribute('aria-required');
            }
        }
    }

    function isComplete(state = getState()) {
        if (!state.water_supply_status) {
            return false;
        }
        if (state.water_supply_status === 'others' && !state.specify_water_source) {
            return false;
        }
        if (!state.water_source_location) {
            return false;
        }
        if (!state.water_availability) {
            return false;
        }
        return true;
    }

    function updateNextButton() {
        const ready = isComplete();
        const nextBtn = liveNext();
        if (!nextBtn) {
            return;
        }
        nextBtn.disabled = !ready;
        if (ready) {
            nextBtn.removeAttribute('disabled');
        } else {
            nextBtn.setAttribute('disabled', '');
        }
        nextBtn.setAttribute('aria-disabled', ready ? 'false' : 'true');
    }

    function validateInline({ showAll = false } = {}) {
        const state = getState();
        let firstInvalid = null;
        const shouldShow = (field) => showAll || touched[field];

        if (!state.water_supply_status) {
            if (shouldShow('water_supply_status')) {
                setError('water_supply_status', ERROR_MESSAGES.water_supply_status);
            } else {
                clearError('water_supply_status');
            }
            firstInvalid = firstInvalid || levelInputs()[0];
        } else {
            clearError('water_supply_status');
        }

        if (state.water_supply_status === 'others' && !state.specify_water_source) {
            const specifyInput = liveSpecifyInput();
            if (shouldShow('specify_water_source')) {
                setError('specify_water_source', ERROR_MESSAGES.specify_water_source);
                specifyInput?.classList.add('is-invalid');
            } else {
                clearError('specify_water_source');
                specifyInput?.classList.remove('is-invalid');
            }
            firstInvalid = firstInvalid || specifyInput;
        } else {
            clearError('specify_water_source');
            liveSpecifyInput()?.classList.remove('is-invalid');
        }

        if (!state.water_source_location) {
            if (shouldShow('water_source_location')) {
                setError('water_source_location', ERROR_MESSAGES.water_source_location);
            } else {
                clearError('water_source_location');
            }
            firstInvalid = firstInvalid || locationInputs()[0];
        } else {
            clearError('water_source_location');
        }

        if (!state.water_availability) {
            if (shouldShow('water_availability')) {
                setError('water_availability', ERROR_MESSAGES.water_availability);
            } else {
                clearError('water_availability');
            }
            firstInvalid = firstInvalid || availabilityInputs()[0];
        } else {
            clearError('water_availability');
        }

        updateNextButton();

        return {
            valid: isComplete(state),
            firstInvalid,
            state,
        };
    }

    function markDirty() {
        setDirty(snapshot() !== initialSnapshot);
        writeDraft(householdNo, getState(), draftStorageKey);
        syncSelectedLevelCards(levelInputs());
        syncSafeWaterBadge();
        validateInline({ showAll: false });
    }

    function applyDraft(draft) {
        if (!draft) {
            return false;
        }

        if (draft.water_supply_status) {
            const match = levelInputs().find((input) => input.value === draft.water_supply_status);
            if (match) {
                match.checked = true;
            }
        }

        syncSpecifyVisibility({ clearWhenHidden: false });

        const specifyInput = liveSpecifyInput();
        if (specifyInput && draft.specify_water_source) {
            specifyInput.value = draft.specify_water_source;
        }

        if (draft.water_source_location) {
            const match = locationInputs().find((input) => input.value === draft.water_source_location);
            if (match) {
                match.checked = true;
            }
        }

        if (draft.water_availability) {
            const match = availabilityInputs().find((input) => input.value === draft.water_availability);
            if (match) {
                match.checked = true;
            }
        }

        return draftHasContent(draft);
    }

    const restored = applyDraft(readDraft(householdNo, draftStorageKey));
    syncSpecifyVisibility({ clearWhenHidden: false });
    syncSelectedLevelCards(levelInputs());
    syncSafeWaterBadge();
    initialSnapshot = snapshot();
    setDirty(false);
    updateNextButton();

    function refreshStep1() {
        syncSpecifyVisibility({ clearWhenHidden: false });
        syncSelectedLevelCards(levelInputs());
        syncSafeWaterBadge();
        updateNextButton();
    }
    root._hwsRefresh = refreshStep1;

    if (restored) {
        showDraftRestoredNotice(root);
    }

    function onStep1Interact(event) {
        const el = eventElement(event);
        if (!el || (typeof root.contains === 'function' && !root.contains(el))) {
            return;
        }
        const related = closestAny(el, [
            '[data-hws-level]',
            '[data-hws-level-card]',
            '[data-hws-location]',
            '[data-hws-availability]',
            '[data-hws-specify-input]',
            '[data-hws-specify]',
        ]);
        if (!related) {
            return;
        }

        if (event.type === 'click' || event.type === 'change') {
            const radio = checkRadioInRoot(root, radioFromEventElement(related) || radioFromEventElement(el));
            if (radio?.hasAttribute?.('data-hws-level') || radio?.matches?.('[data-hws-level]')) {
                touched.water_supply_status = true;
                clearError('water_supply_status');
                syncSpecifyVisibility({ clearWhenHidden: true });
            } else if (radio?.hasAttribute?.('data-hws-location') || radio?.matches?.('[data-hws-location]')) {
                touched.water_source_location = true;
                clearError('water_source_location');
            } else if (radio?.hasAttribute?.('data-hws-availability') || radio?.matches?.('[data-hws-availability]')) {
                touched.water_availability = true;
                clearError('water_availability');
            }
        }

        const specifyInput = liveSpecifyInput();
        if (specifyInput && (el === specifyInput || related.hasAttribute?.('data-hws-specify-input') || related.hasAttribute?.('data-hws-specify'))) {
            if (event.type === 'input' || event.type === 'change' || event.type === 'blur') {
                touched.specify_water_source = true;
                if ((specifyInput.value || '').trim()) {
                    clearError('specify_water_source');
                    specifyInput.classList.remove('is-invalid');
                }
            }
        }

        markDirty();
    }

    if (!root._hwsStep1Delegated) {
        root._hwsStep1Delegated = true;
        root.addEventListener('change', onStep1Interact);
        root.addEventListener('input', onStep1Interact);
        root.addEventListener('click', onStep1Interact);
        form.addEventListener('blur', onStep1Interact, true);
        form.addEventListener('submit', (event) => {
            Object.keys(touched).forEach((key) => {
                touched[key] = true;
            });

            const result = validateInline({ showAll: true });
            if (!result.valid) {
                event.preventDefault();
                result.firstInvalid?.focus?.();
                result.firstInvalid?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
                return;
            }

            setAllowLeave(true);
            setDirty(false);
            clearDraft(householdNo, draftStorageKey);
            clearPendingHousehold();
        });
    }
}

function initStep3Form(root, shell) {
    const { form, householdNo, backUrl, draftStorageKey, requestLeave, setDirty, setAllowLeave } = shell;

    if (!form) {
        return;
    }

    const nextBtn = root.querySelector('[data-hws-next]');
    const previousBtn = root.querySelector('[data-hws-previous]');
    const toiletSelect = root.querySelector('[data-hws-toilet-type]');
    const statusCard = root.querySelector('[data-hws-toilet-status]');
    const statusText = root.querySelector('[data-hws-status-text]');
    const statusIcon = root.querySelector('[data-hws-status-icon]');
    const managementBadge = root.querySelector('[data-hws-management-badge]');
    const managementBadgeText = root.querySelector('[data-hws-management-badge-text]');
    const openDefecationInputs = Array.from(root.querySelectorAll('[data-hws-open-defecation]'));
    const sharedToiletInputs = Array.from(root.querySelectorAll('[data-hws-shared-toilet]'));
    const sewageInputs = Array.from(root.querySelectorAll('[data-hws-sewage]'));
    const sharedNote = root.querySelector('[data-hws-shared-toilet-note]');
    const sewageNote = root.querySelector('[data-hws-sewage-note]');
    const sewageGroup = root.querySelector('[data-hws-sewage-group]');
    const sharedFieldset = root.querySelector('[data-hws-shared-toilet-fieldset]');
    let submitting = false;
    let initialSnapshot = '';

    const MANAGEMENT_BADGE_LABELS = {
        safely_managed: 'Safely Managed',
        not_safely_managed: 'Not Safely Managed',
        not_yet_determined: 'Not Yet Determined',
    };

    const MANAGEMENT_DISPLAY_TEXT = {
        safely_managed: 'SANITARY',
        not_safely_managed: 'UNSANITARY',
        not_yet_determined: 'Not yet determined',
    };

    const touched = {
        toilet_type: false,
        open_defecation_practiced: false,
        shared_toilet: false,
        sewage_disposal_method: false,
    };

    function selectedValue(inputs) {
        const checked = inputs.find((input) => input.checked);
        return checked ? checked.value : '';
    }

    function ensureSharedHidden(enabled) {
        let hidden = form.querySelector('[data-hws-shared-toilet-hidden]');
        if (enabled) {
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'shared_toilet';
                hidden.value = 'no';
                hidden.setAttribute('data-hws-shared-toilet-hidden', '');
                sharedFieldset?.appendChild(hidden);
            } else {
                hidden.value = 'no';
            }
            return;
        }

        hidden?.remove();
    }

    function getState() {
        const toiletType = (toiletSelect?.value || '').trim();
        const without = isWithoutToilet(toiletType);

        return {
            toilet_type: toiletType,
            open_defecation_practiced: selectedValue(openDefecationInputs),
            shared_toilet: without ? 'no' : selectedValue(sharedToiletInputs),
            sewage_disposal_method: without ? '' : selectedValue(sewageInputs),
        };
    }

    function snapshot(state = getState()) {
        return JSON.stringify(state);
    }

    function setError(field, message) {
        const el = root.querySelector(`[data-hws-error="${field}"]`);
        if (!el) {
            return;
        }
        el.textContent = message || '';
        el.hidden = !message;
    }

    function clearError(field) {
        setError(field, '');
    }

    function markFieldInvalid(input, invalid) {
        if (!input) {
            return;
        }
        input.classList.toggle('is-invalid', Boolean(invalid));
        if (invalid) {
            input.setAttribute('aria-invalid', 'true');
        } else {
            input.removeAttribute('aria-invalid');
        }
    }

    function markRadioGroupInvalid(inputs, invalid) {
        const group = inputs[0]?.closest('.lml-hws__radio-row')
            || inputs[0]?.closest('.lml-hws__radio-col')
            || inputs[0]?.closest('[role="radiogroup"]');

        if (group) {
            group.classList.toggle('is-invalid', Boolean(invalid));
            if (invalid) {
                group.setAttribute('aria-invalid', 'true');
            } else {
                group.removeAttribute('aria-invalid');
            }
        }

        inputs.forEach((input) => {
            if (invalid) {
                input.setAttribute('aria-invalid', 'true');
            } else {
                input.removeAttribute('aria-invalid');
            }
        });
    }

    function updateStatusCard(toiletType, sewageDisposalMethod) {
        if (!statusCard || !statusText || !statusIcon) {
            return;
        }

        const status = deriveManagementStatus(toiletType, sewageDisposalMethod);
        statusCard.classList.remove('is-pending', 'is-sanitary', 'is-unsanitary');

        if (managementBadge) {
            managementBadge.classList.remove('is-pending', 'is-safely-managed', 'is-not-safely-managed');
        }

        if (status === 'safely_managed') {
            statusCard.classList.add('is-sanitary');
            statusText.textContent = MANAGEMENT_DISPLAY_TEXT.safely_managed;
            statusIcon.innerHTML = '<i class="bi bi-shield-fill-check"></i>';
            if (managementBadge) {
                managementBadge.classList.add('is-safely-managed');
            }
            if (managementBadgeText) {
                managementBadgeText.textContent = MANAGEMENT_BADGE_LABELS.safely_managed;
            }
            return;
        }

        if (status === 'not_safely_managed') {
            statusCard.classList.add('is-unsanitary');
            statusText.textContent = MANAGEMENT_DISPLAY_TEXT.not_safely_managed;
            statusIcon.innerHTML = '<i class="bi bi-shield-fill-x"></i>';
            if (managementBadge) {
                managementBadge.classList.add('is-not-safely-managed');
            }
            if (managementBadgeText) {
                managementBadgeText.textContent = MANAGEMENT_BADGE_LABELS.not_safely_managed;
            }
            return;
        }

        statusCard.classList.add('is-pending');
        statusText.textContent = MANAGEMENT_DISPLAY_TEXT.not_yet_determined;
        statusIcon.innerHTML = '<i class="bi bi-shield"></i>';
        if (managementBadge) {
            managementBadge.classList.add('is-pending');
        }
        if (managementBadgeText) {
            managementBadgeText.textContent = MANAGEMENT_BADGE_LABELS.not_yet_determined;
        }
    }

    function refreshStatusCard() {
        const state = getState();
        updateStatusCard(state.toilet_type, state.sewage_disposal_method);
    }

    function applyWithoutToiletMode(without, { clearStale = true } = {}) {
        sharedToiletInputs.forEach((input) => {
            input.disabled = without;
            if (without) {
                input.checked = input.value === 'no';
            }
        });

        ensureSharedHidden(without);

        if (sharedNote) {
            sharedNote.hidden = !without;
        }

        sewageInputs.forEach((input) => {
            input.disabled = without;
            if (without && clearStale) {
                input.checked = false;
            }
        });

        if (sewageGroup) {
            sewageGroup.hidden = without;
            sewageGroup.setAttribute('aria-required', 'false');
        }

        if (sewageNote) {
            sewageNote.hidden = !without;
        }

        if (without) {
            clearError('shared_toilet');
            clearError('sewage_disposal_method');
            markRadioGroupInvalid(sharedToiletInputs, false);
            markRadioGroupInvalid(sewageInputs, false);
            touched.shared_toilet = false;
            touched.sewage_disposal_method = false;
        }
    }

    function isComplete(state = getState()) {
        if (!state.toilet_type) {
            return false;
        }
        if (!state.open_defecation_practiced) {
            return false;
        }
        if (isWithoutToilet(state.toilet_type)) {
            return true;
        }
        if (!state.shared_toilet) {
            return false;
        }
        return true;
    }

    function updateNextButton() {
        const ready = isComplete() && !submitting;
        if (!nextBtn) {
            return;
        }
        nextBtn.disabled = !ready;
        nextBtn.setAttribute('aria-disabled', ready ? 'false' : 'true');
    }

    function validateInline({ showAll = false } = {}) {
        const state = getState();
        const without = isWithoutToilet(state.toilet_type);
        let firstInvalid = null;
        const shouldShow = (field) => showAll || touched[field];

        if (!state.toilet_type) {
            if (shouldShow('toilet_type')) {
                setError('toilet_type', STEP3_ERROR_MESSAGES.toilet_type);
                markFieldInvalid(toiletSelect, true);
            } else {
                clearError('toilet_type');
                markFieldInvalid(toiletSelect, false);
            }
            firstInvalid = firstInvalid || toiletSelect;
        } else {
            clearError('toilet_type');
            markFieldInvalid(toiletSelect, false);
        }

        if (!state.open_defecation_practiced) {
            if (shouldShow('open_defecation_practiced')) {
                setError('open_defecation_practiced', STEP3_ERROR_MESSAGES.open_defecation_practiced);
                markRadioGroupInvalid(openDefecationInputs, true);
            } else {
                clearError('open_defecation_practiced');
                markRadioGroupInvalid(openDefecationInputs, false);
            }
            firstInvalid = firstInvalid || openDefecationInputs[0];
        } else {
            clearError('open_defecation_practiced');
            markRadioGroupInvalid(openDefecationInputs, false);
        }

        if (!without && !state.shared_toilet) {
            if (shouldShow('shared_toilet')) {
                setError('shared_toilet', STEP3_ERROR_MESSAGES.shared_toilet);
                markRadioGroupInvalid(sharedToiletInputs, true);
            } else {
                clearError('shared_toilet');
                markRadioGroupInvalid(sharedToiletInputs, false);
            }
            firstInvalid = firstInvalid || sharedToiletInputs[0];
        } else {
            clearError('shared_toilet');
            markRadioGroupInvalid(sharedToiletInputs, false);
        }

        clearError('sewage_disposal_method');
        markRadioGroupInvalid(sewageInputs, false);

        updateNextButton();

        return {
            valid: isComplete(state),
            firstInvalid,
            state,
        };
    }

    function markDirty() {
        setDirty(snapshot() !== initialSnapshot);
        writeDraft(householdNo, getState(), draftStorageKey);
        refreshStatusCard();
        validateInline({ showAll: false });
    }

    function applyDraft(draft) {
        if (!draft) {
            return false;
        }

        if (toiletSelect && draft.toilet_type) {
            toiletSelect.value = draft.toilet_type;
        }

        const without = isWithoutToilet((toiletSelect?.value || '').trim());
        applyWithoutToiletMode(without, { clearStale: false });

        if (draft.open_defecation_practiced) {
            const match = openDefecationInputs.find((input) => input.value === draft.open_defecation_practiced);
            if (match) {
                match.checked = true;
            }
        }

        if (!without && draft.shared_toilet) {
            const match = sharedToiletInputs.find((input) => input.value === draft.shared_toilet);
            if (match) {
                match.checked = true;
            }
        }

        if (!without && draft.sewage_disposal_method) {
            const match = sewageInputs.find((input) => input.value === draft.sewage_disposal_method);
            if (match) {
                match.checked = true;
            }
        }

        refreshStatusCard();

        return step3DraftHasContent(draft);
    }

    const initialToilet = (toiletSelect?.value || '').trim();
    applyWithoutToiletMode(isWithoutToilet(initialToilet), { clearStale: false });
    refreshStatusCard();

    const restored = applyDraft(readDraft(householdNo, draftStorageKey));
    initialSnapshot = snapshot();
    setDirty(false);
    updateNextButton();
    validateInline({ showAll: false });

    function syncServerInvalidState() {
        ['toilet_type', 'open_defecation_practiced', 'shared_toilet', 'sewage_disposal_method'].forEach((field) => {
            const err = root.querySelector(`[data-hws-error="${field}"]`);
            if (!err || err.hidden || !err.textContent.trim()) {
                return;
            }

            if (field === 'toilet_type') {
                markFieldInvalid(toiletSelect, true);
                return;
            }

            if (field === 'open_defecation_practiced') {
                markRadioGroupInvalid(openDefecationInputs, true);
                return;
            }

            if (field === 'shared_toilet') {
                markRadioGroupInvalid(sharedToiletInputs, true);
                return;
            }

            markRadioGroupInvalid(sewageInputs, true);
        });
    }

    syncServerInvalidState();

    if (restored) {
        showDraftRestoredNotice(root);
    }

    const focusTarget = root.querySelector('.lml-hws__select.is-invalid, .lml-hws__radio-row.is-invalid input, .lml-hws__radio-col.is-invalid input')
        || root.querySelector('.lml-hws__error:not([hidden])')?.closest('.lml-hws__field, .lml-hws__question, .lml-hws__facility-fieldset')
            ?.querySelector('select, input');

    if (focusTarget) {
        window.setTimeout(() => {
            focusTarget.focus?.();
        }, 0);
    }

    previousBtn?.addEventListener('click', (event) => {
        event.preventDefault();
        requestLeave(previousBtn, {
            url: backUrl,
            clearLocalDraft: false,
            message: 'You have unsaved changes. Are you sure you want to return to Validation / Random Sampling / Testing?',
        });
    });

    toiletSelect?.addEventListener('change', () => {
        touched.toilet_type = true;
        const toiletType = (toiletSelect.value || '').trim();
        const without = isWithoutToilet(toiletType);
        applyWithoutToiletMode(without, { clearStale: true });
        markDirty();
    });

    openDefecationInputs.forEach((input) => {
        input.addEventListener('change', () => {
            touched.open_defecation_practiced = true;
            clearError('open_defecation_practiced');
            markDirty();
        });
    });

    sharedToiletInputs.forEach((input) => {
        input.addEventListener('change', () => {
            touched.shared_toilet = true;
            clearError('shared_toilet');
            markDirty();
        });
    });

    // Sewage disposal is the one optional radio group on this step — unlike
    // the required ones, an accidental click must be undoable back to "no
    // selection", which native radios don't support on their own.
    let sewageWasCheckedBeforeActivation = false;

    sewageInputs.forEach((input) => {
        input.addEventListener('mousedown', () => {
            sewageWasCheckedBeforeActivation = input.checked;
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === ' ' || event.key === 'Spacebar') {
                sewageWasCheckedBeforeActivation = input.checked;
            }
        });

        input.addEventListener('click', () => {
            if (!sewageWasCheckedBeforeActivation) {
                return;
            }

            sewageWasCheckedBeforeActivation = false;
            input.checked = false;
            touched.sewage_disposal_method = true;
            clearError('sewage_disposal_method');
            markDirty();
        });

        input.addEventListener('change', () => {
            touched.sewage_disposal_method = true;
            clearError('sewage_disposal_method');
            markDirty();
        });
    });

    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }

        Object.keys(touched).forEach((key) => {
            touched[key] = true;
        });

        const result = validateInline({ showAll: true });
        if (!result.valid) {
            event.preventDefault();
            result.firstInvalid?.focus?.();
            result.firstInvalid?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
            return;
        }

        submitting = true;
        setAllowLeave(true);
        setDirty(false);
        clearDraft(householdNo, draftStorageKey);
        clearPendingHousehold();
        updateNextButton();

        if (nextBtn) {
            nextBtn.disabled = true;
            nextBtn.setAttribute('aria-disabled', 'true');
            nextBtn.classList.add('is-busy');
            nextBtn.setAttribute('aria-busy', 'true');
        }
    });

    root._hwsRefresh = () => {
        refreshStatusCard();
        updateNextButton();
        validateInline({ showAll: false });
    };
}

function initStep4Form(root, shell) {
    const { form, householdNo, backUrl, draftStorageKey, requestLeave, setDirty, setAllowLeave } = shell;

    if (!form) {
        return;
    }

    const nextBtn = root.querySelector('[data-hws-next]');
    const previousBtn = root.querySelector('[data-hws-previous]');
    const practiceInputs = Array.from(root.querySelectorAll('[data-hws-solid-practice]'));
    const practiceGroup = root.querySelector('[data-hws-solid-practices-group]');
    const statusCard = root.querySelector('[data-hws-solid-status]');
    const statusText = root.querySelector('[data-hws-solid-status-text]');
    const statusIcon = root.querySelector('[data-hws-solid-status-icon]');
    let submitting = false;
    let initialSnapshot = '';
    let touched = false;

    function getState() {
        return {
            solid_waste_practices: practiceInputs
                .filter((input) => input.checked)
                .map((input) => input.value),
        };
    }

    function snapshot(state = getState()) {
        return JSON.stringify(state);
    }

    function setError(field, message) {
        const el = root.querySelector(`[data-hws-error="${field}"]`);
        if (!el) {
            return;
        }
        el.textContent = message || '';
        el.hidden = !message;
    }

    function clearError(field) {
        setError(field, '');
    }

    function markGroupInvalid(invalid) {
        if (!practiceGroup) {
            return;
        }
        practiceGroup.classList.toggle('is-invalid', Boolean(invalid));
        if (invalid) {
            practiceGroup.setAttribute('aria-invalid', 'true');
        } else {
            practiceGroup.removeAttribute('aria-invalid');
        }
    }

    function updateStatusCard(selectedCount) {
        if (!statusCard || !statusText || !statusIcon) {
            return;
        }

        statusCard.classList.remove('is-pending', 'is-good');

        if (selectedCount > 0) {
            statusCard.classList.add('is-good');
            statusText.textContent = 'GOOD PRACTICE';
            statusIcon.innerHTML = '<i class="bi bi-shield-fill-check"></i>';
            return;
        }

        statusCard.classList.add('is-pending');
        statusText.textContent = 'NOT YET DETERMINED';
        statusIcon.innerHTML = '<i class="bi bi-shield"></i>';
    }

    function isComplete(state = getState()) {
        return state.solid_waste_practices.length > 0;
    }

    function updateNextButton() {
        const ready = isComplete() && !submitting;
        if (!nextBtn) {
            return;
        }
        nextBtn.disabled = !ready;
        nextBtn.setAttribute('aria-disabled', ready ? 'false' : 'true');
    }

    function validateInline({ showAll = false } = {}) {
        const state = getState();
        const valid = state.solid_waste_practices.length > 0;

        if (!valid && (showAll || touched)) {
            setError('solid_waste_practices', STEP4_ERROR_MESSAGES.solid_waste_practices);
            markGroupInvalid(true);
        } else {
            clearError('solid_waste_practices');
            markGroupInvalid(false);
        }

        updateStatusCard(state.solid_waste_practices.length);
        updateNextButton();

        return {
            valid,
            firstInvalid: !valid ? practiceInputs[0] : null,
            state,
        };
    }

    function markDirty() {
        setDirty(snapshot() !== initialSnapshot);
        writeDraft(householdNo, getState(), draftStorageKey);
        validateInline({ showAll: false });
    }

    function applyDraft(draft) {
        if (!draft || !Array.isArray(draft.solid_waste_practices)) {
            return false;
        }

        const selected = new Set(draft.solid_waste_practices);
        practiceInputs.forEach((input) => {
            input.checked = selected.has(input.value);
        });

        return step4DraftHasContent(draft);
    }

    const restored = applyDraft(readDraft(householdNo, draftStorageKey));
    initialSnapshot = snapshot();
    setDirty(false);
    validateInline({ showAll: false });

    function syncServerInvalidState() {
        const err = root.querySelector('[data-hws-error="solid_waste_practices"]');
        if (!err || err.hidden || !err.textContent.trim()) {
            return;
        }
        markGroupInvalid(true);
    }

    syncServerInvalidState();

    if (restored) {
        showDraftRestoredNotice(root);
    }

    const focusTarget = root.querySelector('.lml-hws__check-col.is-invalid input')
        || root.querySelector('.lml-hws__error:not([hidden])')?.closest('.lml-hws__solid-card')
            ?.querySelector('input');

    if (focusTarget) {
        window.setTimeout(() => {
            focusTarget.focus?.();
        }, 0);
    }

    previousBtn?.addEventListener('click', (event) => {
        event.preventDefault();
        requestLeave(previousBtn, {
            url: backUrl,
            clearLocalDraft: false,
            message: 'You have unsaved changes. Are you sure you want to return to Basic Sanitation Facility?',
        });
    });

    practiceInputs.forEach((input) => {
        input.addEventListener('change', () => {
            touched = true;
            clearError('solid_waste_practices');
            markDirty();
        });
    });

    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }

        touched = true;
        const result = validateInline({ showAll: true });
        if (!result.valid) {
            event.preventDefault();
            result.firstInvalid?.focus?.();
            result.firstInvalid?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
            return;
        }

        submitting = true;
        setAllowLeave(true);
        setDirty(false);
        clearDraft(householdNo, draftStorageKey);
        clearPendingHousehold();
        updateNextButton();

        if (nextBtn) {
            nextBtn.disabled = true;
            nextBtn.setAttribute('aria-disabled', 'true');
            nextBtn.classList.add('is-busy');
            nextBtn.setAttribute('aria-busy', 'true');
        }
    });

    root._hwsRefresh = () => {
        updateNextButton();
        validateInline({ showAll: false });
    };
}

// Keep bind state on this document only. A HTML flag survives Cache Storage
// navigation, but _hwsRefresh does not, so later init would skip listeners.
const boundHwsRoots = new WeakSet();

export function refreshHouseholdWaterSupplyState(root) {
    const host = resolveHwsRoot(root);
    if (!host) {
        return false;
    }
    if (typeof host._hwsRefresh === 'function') {
        host._hwsRefresh();
        return true;
    }
    return false;
}

export function initHouseholdWaterSupply(root) {
    const host = resolveHwsRoot(root);
    if (!host) {
        return false;
    }

    ensureLiveStep1Bridge(host.ownerDocument || (typeof document !== 'undefined' ? document : null));

    if (boundHwsRoots.has(host) && typeof host._hwsRefresh === 'function') {
        return refreshHouseholdWaterSupplyState(host);
    }

    const shell = initSharedShell(host);
    const step = host.getAttribute('data-hws-step') || '1';

    if (step === '2') {
        initStep2Form(host, shell);
    } else if (step === '3') {
        initStep3Form(host, shell);
    } else if (step === '4') {
        initStep4Form(host, shell);
    } else {
        initStep1Form(host, shell);
    }

    if (typeof host._hwsRefresh === 'function') {
        boundHwsRoots.add(host);
    }
    host.removeAttribute('data-hws-bound');
    return typeof host._hwsRefresh === 'function';
}

export function bootHouseholdWaterSupply(doc = typeof document !== 'undefined' ? document : null) {
    if (!doc?.querySelectorAll) {
        return;
    }
    ensureLiveStep1Bridge(doc);
    doc.querySelectorAll('[data-lml-hws]').forEach((root) => {
        initHouseholdWaterSupply(root);
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bootHouseholdWaterSupply(document));
    } else {
        bootHouseholdWaterSupply(document);
    }
}

export { PENDING_KEY, readStep1State, deriveBasicSafeWaterStatus };
