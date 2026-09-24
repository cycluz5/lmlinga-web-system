/**
 * Adult Immunization — inline Edit/Save toggle.
 * Same interaction pattern as Child Immunization: dose date fields start
 * readonly/disabled, "Edit" enables them, "Save" submits the form.
 */

function getForm(root) {
    return root.querySelector('[data-adult-imm-form]');
}

function getEditableFields(form) {
    return form.querySelectorAll('[data-adult-imm-field]');
}

function setFieldsEditable(form, enabled) {
    getEditableFields(form).forEach((field) => {
        if (!(field instanceof HTMLInputElement)) {
            return;
        }

        field.disabled = !enabled;
        field.readOnly = !enabled;
        if (enabled) {
            field.removeAttribute('readonly');
        } else {
            field.setAttribute('readonly', 'readonly');
        }
    });
}

function setEditMode(form, editing) {
    const editBtn = form.querySelector('[data-adult-imm-edit]');
    const saveBtn = form.querySelector('[data-adult-imm-save]');

    form.dataset.editing = editing ? 'true' : 'false';
    setFieldsEditable(form, editing);

    if (editBtn instanceof HTMLElement) {
        editBtn.hidden = editing;
    }

    if (saveBtn instanceof HTMLElement) {
        saveBtn.hidden = !editing;
    }
}

function enterEditMode(form) {
    setEditMode(form, true);

    const saveBtn = form.querySelector('[data-adult-imm-save]');
    if (saveBtn instanceof HTMLElement) {
        saveBtn.focus({ preventScroll: true });
    }
}

function initAdultImmunization(root) {
    if (!(root instanceof HTMLElement) || root.dataset.adultImmReady === 'true') {
        return;
    }
    root.dataset.adultImmReady = 'true';

    const form = getForm(root);
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    setEditMode(form, false);

    form.addEventListener('submit', (event) => {
        if (form.dataset.editing !== 'true') {
            event.preventDefault();
            return;
        }

        // Fields must be enabled for their values to be included in the POST.
        getEditableFields(form).forEach((field) => {
            if (field instanceof HTMLInputElement) {
                field.disabled = false;
                field.readOnly = false;
                field.removeAttribute('readonly');
            }
        });
    });

    root.addEventListener('click', (event) => {
        const editBtn = event.target.closest('[data-adult-imm-edit]');
        if (editBtn && root.contains(editBtn)) {
            enterEditMode(form);
        }
    });
}

if (typeof document !== 'undefined') {
    const boot = () => {
        document.querySelectorAll('[data-lml-adult-imm]').forEach(initAdultImmunization);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}
