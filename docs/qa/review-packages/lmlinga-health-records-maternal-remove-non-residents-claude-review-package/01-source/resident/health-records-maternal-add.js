/**
 * Health Records → Maternal → Non-Residents → Add.
 * BMI: kg / (cm/100)², 1 decimal (DemoMaternalCare convention).
 * EDD: LMP + 280 days when EDD is empty.
 */

function formatYmd(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function initNonResidentMaternalAdd(root) {
    const form = root.querySelector('[data-hr-mc-add-form]');
    if (!form) {
        return;
    }

    const lmp = form.querySelector('[data-hr-mc-lmp]');
    const edd = form.querySelector('[data-hr-mc-edd]');
    const weight = form.querySelector('[data-hr-mc-weight]');
    const height = form.querySelector('[data-hr-mc-height]');
    const bmi = form.querySelector('[data-hr-mc-bmi]');

    const updateEdd = () => {
        if (!lmp || !edd || !lmp.value || edd.value) {
            return;
        }
        const date = new Date(`${lmp.value}T00:00:00`);
        if (Number.isNaN(date.getTime())) {
            return;
        }
        date.setDate(date.getDate() + 280);
        edd.value = formatYmd(date);
    };

    const updateBmi = () => {
        if (!bmi) {
            return;
        }
        const w = Number.parseFloat(weight?.value ?? '');
        const hCm = Number.parseFloat(height?.value ?? '');
        if (!Number.isFinite(w) || !Number.isFinite(hCm) || w <= 0 || hCm <= 0) {
            bmi.value = '';
            return;
        }
        const hM = hCm / 100;
        const value = w / (hM * hM);
        if (!Number.isFinite(value)) {
            bmi.value = '';
            return;
        }
        bmi.value = value.toFixed(1);
    };

    lmp?.addEventListener('change', updateEdd);
    lmp?.addEventListener('input', updateEdd);
    weight?.addEventListener('input', updateBmi);
    height?.addEventListener('input', updateBmi);

    updateEdd();
    updateBmi();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-mc-add]').forEach((root) => {
        initNonResidentMaternalAdd(root);
    });
});
