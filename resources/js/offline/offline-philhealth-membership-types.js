/**
 * PhilHealth membership types (panel refinement S-02) for offline display and the offline edit form.
 * Must stay identical to App\Support\PhilhealthMembershipTypes (enforced by PhilhealthMembershipTypeTest).
 */
export const PHILHEALTH_MEMBERSHIP_TYPES = [
    { code: 'employed_private', label: 'Employed Private', group: 'Direct Contributor' },
    { code: 'employed_government', label: 'Employed Government', group: 'Direct Contributor' },
    { code: 'professional_practitioner', label: 'Professional Practitioner', group: 'Direct Contributor' },
    { code: 'self_earning_individual', label: 'Self-Earning Individual', group: 'Direct Contributor' },
    { code: 'kasambahay', label: 'Kasambahay', group: 'Direct Contributor' },
    { code: 'family_driver', label: 'Family Driver', group: 'Direct Contributor' },
    { code: 'migrant_worker', label: 'Migrant Worker', group: 'Direct Contributor' },
    { code: 'lifetime_member', label: 'Lifetime Member', group: 'Direct Contributor' },
    { code: 'dual_citizen_living_abroad', label: 'Filipinos with Dual Citizenship / Living Abroad', group: 'Direct Contributor' },
    { code: 'foreign_national', label: 'Foreign National', group: 'Direct Contributor' },
    { code: 'listahanan', label: 'Listahanan', group: 'Indirect Contributor' },
    { code: 'four_ps_mcct', label: '4Ps/MCCT', group: 'Indirect Contributor' },
    { code: 'senior_citizen', label: 'Senior Citizen', group: 'Indirect Contributor' },
    { code: 'pamana', label: 'PAMANA', group: 'Indirect Contributor' },
    { code: 'kia_kipo', label: 'KIA/KIPO', group: 'Indirect Contributor' },
    { code: 'bangsamoro_normalization', label: 'Bangsamoro/Normalization', group: 'Indirect Contributor' },
    { code: 'lgu_sponsored', label: 'LGU-sponsored', group: 'Indirect Contributor' },
    { code: 'nga_sponsored', label: 'NGA-sponsored', group: 'Indirect Contributor' },
    { code: 'private_sponsored', label: 'Private-sponsored', group: 'Indirect Contributor' },
    { code: 'person_with_disability', label: 'Person with Disability', group: 'Indirect Contributor' },
    { code: 'solo_parent', label: 'Solo Parent', group: 'Indirect Contributor' },
    { code: 'employed_person_with_disability', label: 'Employed Person with Disability', group: 'Indirect Contributor' },
];

export const PHILHEALTH_MEMBERSHIP_TYPE_NOT_RECORDED = 'Not recorded';

export function philhealthMembershipTypeLabel(code) {
    const raw = String(code ?? '').trim();
    const match = PHILHEALTH_MEMBERSHIP_TYPES.find((type) => type.code === raw);
    return match ? match.label : PHILHEALTH_MEMBERSHIP_TYPE_NOT_RECORDED;
}
