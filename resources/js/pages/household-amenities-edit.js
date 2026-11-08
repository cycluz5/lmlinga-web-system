/**
 * Household Profiling — Edit Household Amenities Details.
 * Sewage Disposal Method is the form's one optional radio group — unlike
 * native radios, an accidental click must be undoable back to "no selection".
 */

function initAmenitiesEdit(root) {
    const sewageInputs = Array.from(root.querySelectorAll('[data-amenities-sewage]'));
    if (sewageInputs.length === 0) {
        return;
    }

    let wasCheckedBeforeActivation = false;

    sewageInputs.forEach((input) => {
        input.addEventListener('mousedown', () => {
            wasCheckedBeforeActivation = input.checked;
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === ' ' || event.key === 'Spacebar') {
                wasCheckedBeforeActivation = input.checked;
            }
        });

        input.addEventListener('click', () => {
            if (!wasCheckedBeforeActivation) {
                return;
            }

            wasCheckedBeforeActivation = false;
            input.checked = false;
        });
    });
}

if (typeof document !== 'undefined') {
    const boot = () => {
        document.querySelectorAll('[data-lml-amenities]').forEach((root) => {
            initAmenitiesEdit(root);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}
