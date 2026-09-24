/**
 * Dashboard home — interactive La Medalla preview map (shared Spot Mapping base).
 * No plot tools, filters, or handoff workflow.
 */
import { createLaMedallaBaseMap } from '../maps/la-medalla-base';

function initDashboardHomeMap() {
    const el = document.querySelector('[data-lml-dash-map]');
    if (!el) {
        return;
    }

    createLaMedallaBaseMap(el);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboardHomeMap);
} else {
    initDashboardHomeMap();
}
