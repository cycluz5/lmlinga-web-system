/**
 * Child Nutrition — embedded Deworming "Add Deworming Record" toggle.
 * The add form starts hidden; only the latest record shows by default.
 */

function initDewormingToggle(root) {
    if (!(root instanceof HTMLElement) || root.dataset.childNutDewormingToggleReady === 'true') {
        return;
    }
    root.dataset.childNutDewormingToggleReady = 'true';

    const toggleBtn = root.querySelector('[data-child-nut-deworming-toggle]');
    const panel = root.querySelector('[data-child-nut-deworming-panel]');
    const cancelBtn = root.querySelector('[data-child-nut-deworming-cancel]');

    if (!(toggleBtn instanceof HTMLElement) || !(panel instanceof HTMLElement)) {
        return;
    }

    const setOpen = (open) => {
        panel.hidden = !open;
        toggleBtn.hidden = open;
        toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            const firstField = panel.querySelector('input, select, textarea');
            if (firstField instanceof HTMLElement) {
                firstField.focus({ preventScroll: true });
            }
        }
    };

    toggleBtn.addEventListener('click', () => setOpen(true));

    if (cancelBtn instanceof HTMLElement) {
        cancelBtn.addEventListener('click', () => {
            setOpen(false);
            toggleBtn.focus({ preventScroll: true });
        });
    }
}

if (typeof document !== 'undefined') {
    const boot = () => {
        document.querySelectorAll('[data-lml-child-nut-deworming]').forEach(initDewormingToggle);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}
