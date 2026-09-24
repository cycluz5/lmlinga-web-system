@php
    $v = $memberValues ?? [];
    $disability = $v['disability'] ?? [];
    $medical = $v['medical_history'] ?? [];
    if (! is_array($disability)) { $disability = []; }
    if (! is_array($medical)) { $medical = []; }
    $showDisabilityOthers = in_array('others', $disability, true);
    $showMedicalOthers = in_array('others', $medical, true);

    $occupationOptions = ['None / N/A', 'Farmer', 'Fisherfolk', 'Vendor', 'Teacher', 'Nurse', 'Driver', 'Construction Worker', 'Government Employee', 'Private Employee', 'Self-employed', 'Student', 'Homemaker', 'Unemployed', 'Other'];
    $religionOptions = ['Roman Catholic', 'Iglesia ni Cristo', 'Protestant', 'Islam', 'Born Again', 'Other', 'None'];
    $occupationSelect = old('occupation', $v['occupation_select'] ?? '');
    if ($occupationSelect === '') {
        $rawOccupation = (string) old('occupation', $v['occupation'] ?? '');
        $occupationSelect = in_array($rawOccupation, $occupationOptions, true)
            ? $rawOccupation
            : ($rawOccupation !== '' ? 'Other' : '');
        if ($occupationSelect === 'Other' && blank($v['occupation_other'] ?? null) && $rawOccupation !== '' && $rawOccupation !== 'Other') {
            $v['occupation_other'] = $rawOccupation;
        }
    }
    $religionSelect = old('religion', $v['religion_select'] ?? '');
    if ($religionSelect === '') {
        $rawReligion = (string) old('religion', $v['religion'] ?? '');
        $religionSelect = in_array($rawReligion, $religionOptions, true)
            ? $rawReligion
            : ($rawReligion !== '' ? 'Other' : '');
        if ($religionSelect === 'Other' && blank($v['religion_other'] ?? null) && $rawReligion !== '' && $rawReligion !== 'Other') {
            $v['religion_other'] = $rawReligion;
        }
    }
    $showOccupationOther = $occupationSelect === 'Other';
    $showReligionOther = $religionSelect === 'Other';
@endphp

{{-- Personal Information --}}
<section class="lml-hh-member-form__section" aria-labelledby="lml-hh-sec-personal">
    <h3 id="lml-hh-sec-personal" class="lml-hh-member-form__section-title">
        <i class="bi bi-person-fill" aria-hidden="true"></i>
        <span>Personal Information</span>
    </h3>

    <div class="lml-hh-member-form__grid lml-hh-member-form__grid--3">
        <div class="lml-hh-member-form__field" data-field="last_name">
            <label for="lml-hh-last-name">
                Last Name <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
            </label>
            <input
                type="text"
                id="lml-hh-last-name"
                name="last_name"
                class="lml-hh-member-form__control lml-focus-ring"
                placeholder="Last name"
                autocomplete="family-name"
                required
                aria-required="true"
                value="{{ $v['last_name'] ?? '' }}"
            >
            <p class="lml-hh-member-form__error" id="err-last_name" hidden></p>
        </div>

        <div class="lml-hh-member-form__field" data-field="first_name">
            <label for="lml-hh-first-name">
                First Name <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
            </label>
            <input
                type="text"
                id="lml-hh-first-name"
                name="first_name"
                class="lml-hh-member-form__control lml-focus-ring"
                placeholder="First name"
                autocomplete="given-name"
                required
                aria-required="true"
                value="{{ $v['first_name'] ?? '' }}"
            >
            <p class="lml-hh-member-form__error" id="err-first_name" hidden></p>
        </div>

        <div class="lml-hh-member-form__field" data-field="middle_name">
            <label for="lml-hh-middle-name">Middle Name</label>
            <input
                type="text"
                id="lml-hh-middle-name"
                name="middle_name"
                class="lml-hh-member-form__control lml-focus-ring"
                placeholder="Middle name"
                autocomplete="additional-name"
                value="{{ $v['middle_name'] ?? '' }}"
            >
            <p class="lml-hh-member-form__error" id="err-middle_name" hidden></p>
        </div>

        <div class="lml-hh-member-form__field" data-field="relation">
            <label for="lml-hh-relation">
                Relation to Household Head <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
            </label>
            <div class="lml-hh-member-form__select-wrap">
                <select id="lml-hh-relation" name="relation" class="lml-hh-member-form__control lml-focus-ring" required aria-required="true">
                    <option value="">Select</option>
                    <option value="Head" @selected(($v['relation'] ?? '') === 'Head')>Head</option>
                    <option value="Spouse" @selected(($v['relation'] ?? '') === 'Spouse')>Spouse</option>
                    <option value="Son" @selected(($v['relation'] ?? '') === 'Son')>Son</option>
                    <option value="Daughter" @selected(($v['relation'] ?? '') === 'Daughter')>Daughter</option>
                    <option value="Parent" @selected(($v['relation'] ?? '') === 'Parent')>Parent</option>
                    <option value="Sibling" @selected(($v['relation'] ?? '') === 'Sibling')>Sibling</option>
                    <option value="Grandchild" @selected(($v['relation'] ?? '') === 'Grandchild')>Grandchild</option>
                    <option value="Other Relative" @selected(($v['relation'] ?? '') === 'Other Relative')>Other Relative</option>
                    <option value="Non-Relative" @selected(($v['relation'] ?? '') === 'Non-Relative')>Non-Relative</option>
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <p class="lml-hh-member-form__error" id="err-relation" hidden></p>
        </div>

        <div class="lml-hh-member-form__field" data-field="birthday">
            <label for="lml-hh-birthday">
                Birthday <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
            </label>
            <input type="date" id="lml-hh-birthday" name="birthday" class="lml-hh-member-form__control lml-focus-ring" required aria-required="true" data-hh-max-today value="{{ $v['birthday'] ?? '' }}">
            <p class="lml-hh-member-form__error" id="err-birthday" hidden></p>
        </div>

        <div class="lml-hh-member-form__field" data-field="sex">
            <label for="lml-hh-sex">
                Sex <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
            </label>
            <div class="lml-hh-member-form__select-wrap">
                <select id="lml-hh-sex" name="sex" class="lml-hh-member-form__control lml-focus-ring" required aria-required="true">
                    <option value="">Select</option>
                    <option value="Male" @selected(($v['sex'] ?? '') === 'Male')>Male</option>
                    <option value="Female" @selected(($v['sex'] ?? '') === 'Female')>Female</option>
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <p class="lml-hh-member-form__error" id="err-sex" hidden></p>
        </div>

        <div class="lml-hh-member-form__field" data-field="relationship_status">
            <label for="lml-hh-relationship-status">
                Relationship Status <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
            </label>
            <div class="lml-hh-member-form__select-wrap">
                <select id="lml-hh-relationship-status" name="relationship_status" class="lml-hh-member-form__control lml-focus-ring" required aria-required="true">
                    <option value="">Select</option>
                    <option value="Single" @selected(($v['relationship_status'] ?? '') === 'Single')>Single</option>
                    <option value="Married" @selected(($v['relationship_status'] ?? '') === 'Married')>Married</option>
                    <option value="Widowed" @selected(($v['relationship_status'] ?? '') === 'Widowed')>Widowed</option>
                    <option value="Separated" @selected(($v['relationship_status'] ?? '') === 'Separated')>Separated</option>
                    <option value="Live-in" @selected(($v['relationship_status'] ?? '') === 'Live-in')>Live-in</option>
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <p class="lml-hh-member-form__error" id="err-relationship_status" hidden></p>
        </div>
    </div>
</section>

{{-- Socio-Economic Details --}}
<section class="lml-hh-member-form__section" aria-labelledby="lml-hh-sec-socio">
    <h3 id="lml-hh-sec-socio" class="lml-hh-member-form__section-title">
        <i class="bi bi-bar-chart-fill" aria-hidden="true"></i>
        <span>Socio-Economic Details</span>
    </h3>

    <div class="lml-hh-member-form__grid lml-hh-member-form__grid--2">
        <div class="lml-hh-member-form__field" data-field="occupation">
            <label for="lml-hh-occupation">
                Occupation <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
            </label>
            <div class="lml-hh-member-form__select-wrap">
                <select id="lml-hh-occupation" name="occupation" class="lml-hh-member-form__control lml-focus-ring" required aria-required="true" data-hh-other-select="occupation_other">
                    <option value="">Select</option>
                    @foreach ($occupationOptions as $option)
                        <option value="{{ $option }}" @selected($occupationSelect === $option)>{{ $option }}</option>
                    @endforeach
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <p class="lml-hh-member-form__error" id="err-occupation" hidden></p>
            <div class="lml-hh-member-form__others mt-2" data-field="occupation_other" @unless($showOccupationOther) hidden @endunless>
                <label for="lml-hh-occupation-other">Specify occupation</label>
                <input type="text" id="lml-hh-occupation-other" name="occupation_other" class="lml-hh-member-form__control lml-focus-ring" placeholder="Enter occupation" maxlength="255" value="{{ old('occupation_other', $v['occupation_other'] ?? '') }}">
                <p class="lml-hh-member-form__error" id="err-occupation_other" hidden></p>
            </div>
        </div>

        <div class="lml-hh-member-form__field" data-field="monthly_income">
            <label for="lml-hh-monthly-income">
                Monthly Income <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
            </label>
            <div class="lml-hh-member-form__select-wrap">
                <select id="lml-hh-monthly-income" name="monthly_income" class="lml-hh-member-form__control lml-focus-ring" required aria-required="true">
                    <option value="">Select</option>
                    @foreach (['None / N/A', 'Below 5,000', '5,000 – 9,999', '10,000 – 19,999', '20,000 – 29,999', '30,000 – 49,999', '50,000 and above'] as $option)
                        <option value="{{ $option }}" @selected(($v['monthly_income'] ?? '') === $option)>{{ $option }}</option>
                    @endforeach
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <p class="lml-hh-member-form__error" id="err-monthly_income" hidden></p>
        </div>

        <div class="lml-hh-member-form__field" data-field="religion">
            <label for="lml-hh-religion">
                Religion <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
            </label>
            <div class="lml-hh-member-form__select-wrap">
                <select id="lml-hh-religion" name="religion" class="lml-hh-member-form__control lml-focus-ring" required aria-required="true" data-hh-other-select="religion_other">
                    <option value="">Select</option>
                    @foreach ($religionOptions as $option)
                        <option value="{{ $option }}" @selected($religionSelect === $option)>{{ $option }}</option>
                    @endforeach
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <p class="lml-hh-member-form__error" id="err-religion" hidden></p>
            <div class="lml-hh-member-form__others mt-2" data-field="religion_other" @unless($showReligionOther) hidden @endunless>
                <label for="lml-hh-religion-other">Specify religion</label>
                <input type="text" id="lml-hh-religion-other" name="religion_other" class="lml-hh-member-form__control lml-focus-ring" placeholder="Enter religion" maxlength="255" value="{{ old('religion_other', $v['religion_other'] ?? '') }}">
                <p class="lml-hh-member-form__error" id="err-religion_other" hidden></p>
            </div>
        </div>

        <div class="lml-hh-member-form__field" data-field="education">
            <label for="lml-hh-education">
                Educational Attainment <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
            </label>
            <div class="lml-hh-member-form__select-wrap">
                <select id="lml-hh-education" name="education" class="lml-hh-member-form__control lml-focus-ring" required aria-required="true">
                    <option value="">Select</option>
                    @foreach (\App\Http\Requests\ValidatesHouseholdResidentMember::educations() as $option)
                        <option value="{{ $option }}" @selected(($v['education'] ?? '') === $option)>{{ $option }}</option>
                    @endforeach
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <p class="lml-hh-member-form__error" id="err-education" hidden></p>
        </div>
    </div>
</section>

{{-- Health & Welfare --}}
<section class="lml-hh-member-form__section" aria-labelledby="lml-hh-sec-health">
    <h3 id="lml-hh-sec-health" class="lml-hh-member-form__section-title">
        <i class="bi bi-heart-fill" aria-hidden="true"></i>
        <span>Health &amp; Welfare</span>
    </h3>

    <div class="lml-hh-member-form__grid lml-hh-member-form__grid--2">
        <div class="lml-hh-member-form__field" data-field="philhealth">
            <label for="lml-hh-philhealth">
                PhilHealth Number
            </label>
            <input type="text" id="lml-hh-philhealth" name="philhealth" class="lml-hh-member-form__control lml-focus-ring" placeholder="000000000000" inputmode="numeric" maxlength="12" autocomplete="off" value="{{ old('philhealth', $v['philhealth'] ?? '') }}">
            <p class="lml-hh-member-form__error" id="err-philhealth" hidden></p>
        </div>

        <div class="lml-hh-member-form__field" data-field="fp_user">
            <label for="lml-hh-fp-user">
                Family Planning (FP) User <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
            </label>
            <div class="lml-hh-member-form__select-wrap">
                <select id="lml-hh-fp-user" name="fp_user" class="lml-hh-member-form__control lml-focus-ring" required aria-required="true">
                    <option value="">Select</option>
                    <option value="Yes" @selected(($v['fp_user'] ?? '') === 'Yes')>Yes</option>
                    <option value="No" @selected(($v['fp_user'] ?? '') === 'No')>No</option>
                    <option value="N/A" @selected(($v['fp_user'] ?? '') === 'N/A')>N/A</option>
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <p class="lml-hh-member-form__error" id="err-fp_user" hidden></p>
        </div>

        <div class="lml-hh-member-form__field lml-hh-member-form__field--group" data-field="disability">
            <fieldset data-hh-check-group="disability">
                <legend>
                    Disability Type <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
                </legend>
                <div class="lml-hh-member-form__checks" role="group" aria-labelledby="lml-hh-disability-legend">
                    <span id="lml-hh-disability-legend" class="visually-hidden">Disability Type options</span>
                    <label class="lml-hh-member-form__check"><input type="checkbox" id="lml-hh-disability-first" name="disability[]" value="none" data-hh-none class="lml-focus-ring" @checked(in_array('none', $disability, true))><span>None</span></label>
                    <label class="lml-hh-member-form__check"><input type="checkbox" name="disability[]" value="Intellectual Disability (ID)" class="lml-focus-ring" @checked(in_array('Intellectual Disability (ID)', $disability, true))><span>Intellectual Disability (ID)</span></label>
                    <label class="lml-hh-member-form__check"><input type="checkbox" name="disability[]" value="Mental Disability (MD)" class="lml-focus-ring" @checked(in_array('Mental Disability (MD)', $disability, true))><span>Mental Disability (MD)</span></label>
                    <label class="lml-hh-member-form__check"><input type="checkbox" name="disability[]" value="Physical Disability (PD)" class="lml-focus-ring" @checked(in_array('Physical Disability (PD)', $disability, true))><span>Physical Disability (PD)</span></label>
                    <label class="lml-hh-member-form__check"><input type="checkbox" name="disability[]" value="others" data-hh-others-toggle="disability_others" class="lml-focus-ring" @checked(in_array('others', $disability, true))><span>Others</span></label>
                </div>
                <div class="lml-hh-member-form__others" data-field="disability_others" @unless($showDisabilityOthers) hidden @endunless>
                    <label for="lml-hh-disability-others" class="visually-hidden">Specify disability</label>
                    <input type="text" id="lml-hh-disability-others" name="disability_others" class="lml-hh-member-form__control lml-focus-ring" placeholder="Specify..." data-hh-others-input="disability" value="{{ $v['disability_others'] ?? '' }}">
                    <p class="lml-hh-member-form__error" id="err-disability_others" hidden></p>
                </div>
                <p class="lml-hh-member-form__error" id="err-disability" hidden></p>
            </fieldset>
        </div>

        <div class="lml-hh-member-form__field lml-hh-member-form__field--group" data-field="medical_history">
            <fieldset data-hh-check-group="medical_history">
                <legend>
                    Medical History <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
                </legend>
                <div class="lml-hh-member-form__checks">
                    <label class="lml-hh-member-form__check"><input type="checkbox" id="lml-hh-medical-history-first" name="medical_history[]" value="none" data-hh-none class="lml-focus-ring" @checked(in_array('none', $medical, true))><span>None</span></label>
                    <label class="lml-hh-member-form__check"><input type="checkbox" name="medical_history[]" value="Diabetes Mellitus" class="lml-focus-ring" @checked(in_array('Diabetes Mellitus', $medical, true))><span>Diabetes Mellitus</span></label>
                    <label class="lml-hh-member-form__check"><input type="checkbox" name="medical_history[]" value="Heart Disease" class="lml-focus-ring" @checked(in_array('Heart Disease', $medical, true))><span>Heart Disease</span></label>
                    <label class="lml-hh-member-form__check"><input type="checkbox" name="medical_history[]" value="Hypertension" class="lml-focus-ring" @checked(in_array('Hypertension', $medical, true))><span>Hypertension</span></label>
                    <label class="lml-hh-member-form__check"><input type="checkbox" name="medical_history[]" value="Kidney Disease" class="lml-focus-ring" @checked(in_array('Kidney Disease', $medical, true))><span>Kidney Disease</span></label>
                    <label class="lml-hh-member-form__check"><input type="checkbox" name="medical_history[]" value="Tuberculosis" class="lml-focus-ring" @checked(in_array('Tuberculosis', $medical, true))><span>Tuberculosis</span></label>
                    <label class="lml-hh-member-form__check"><input type="checkbox" name="medical_history[]" value="others" data-hh-others-toggle="medical_others" class="lml-focus-ring" @checked(in_array('others', $medical, true))><span>Others</span></label>
                </div>
                <div class="lml-hh-member-form__others" data-field="medical_others" @unless($showMedicalOthers) hidden @endunless>
                    <label for="lml-hh-medical-others" class="visually-hidden">Specify medical history</label>
                    <input type="text" id="lml-hh-medical-others" name="medical_others" class="lml-hh-member-form__control lml-focus-ring" placeholder="Specify..." data-hh-others-input="medical_history" value="{{ $v['medical_others'] ?? '' }}">
                    <p class="lml-hh-member-form__error" id="err-medical_others" hidden></p>
                </div>
                <p class="lml-hh-member-form__error" id="err-medical_history" hidden></p>
            </fieldset>
        </div>
    </div>
</section>
