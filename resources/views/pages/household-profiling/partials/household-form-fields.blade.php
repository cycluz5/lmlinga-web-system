    @php
        $v = $householdValues ?? [];
        $zones = \App\Http\Requests\ValidatesHouseholdShell::zones();
        $showHouseholdNo = $showHouseholdNo ?? false;
        $householdNoEditable = $householdNoEditable ?? false;
        $schemaGuard = app(\App\Support\DatabaseSchemaGuard::class);
    $streetWritable = $schemaGuard->columnExists('households', 'street');
    $accomplishedByWritable = $schemaGuard->columnExists('households', 'accomplished_by');
    $addressWritable = $schemaGuard->columnExists('households', 'address');
@endphp

<section class="lml-hh-member-form__section" aria-labelledby="lml-hh-sec-shell">
    <h3 id="lml-hh-sec-shell" class="lml-hh-member-form__section-title">
        <i class="bi bi-house-door-fill" aria-hidden="true"></i>
        <span>Household Information</span>
    </h3>

    <div class="lml-hh-member-form__grid lml-hh-member-form__grid--3">
        @if ($showHouseholdNo)
            <div class="lml-hh-member-form__field" data-field="household_no">
                <label for="lml-hh-household-no">
                    Household No.
                    @if ($householdNoEditable)
                        <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
                    @endif
                </label>
                <input
                    type="text"
                    id="lml-hh-household-no"
                    class="lml-hh-member-form__control lml-focus-ring"
                    value="{{ $v['household_no'] ?? '' }}"
                    @if ($householdNoEditable)
                        name="household_no"
                        required
                        aria-required="true"
                        inputmode="numeric"
                        pattern="[0-9]{3}"
                        maxlength="3"
                        autocomplete="off"
                    @else
                        readonly
                        aria-readonly="true"
                    @endif
                >
                @error('household_no')
                    <p class="lml-hh-member-form__error" role="alert">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <div class="lml-hh-member-form__field" data-field="zone">
            <label for="lml-hh-zone-field">
                Zone <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
            </label>
            <select
                id="lml-hh-zone-field"
                name="zone"
                class="lml-hh-member-form__control lml-focus-ring"
                required
                aria-required="true"
            >
                <option value="">Select zone</option>
                @foreach ($zones as $zone)
                    <option value="{{ $zone }}" @selected(($v['zone'] ?? '') === $zone)>{{ $zone }}</option>
                @endforeach
            </select>
            @error('zone')
                <p class="lml-hh-member-form__error" role="alert">{{ $message }}</p>
            @enderror
        </div>

        @if ($streetWritable)
            <div class="lml-hh-member-form__field" data-field="street">
                <label for="lml-hh-street-field">
                    Street <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
                </label>
                <input
                    type="text"
                    id="lml-hh-street-field"
                    name="street"
                    class="lml-hh-member-form__control lml-focus-ring"
                    placeholder="Street"
                    maxlength="150"
                    required
                    aria-required="true"
                    value="{{ $v['street'] ?? '' }}"
                >
                @error('street')
                    <p class="lml-hh-member-form__error" role="alert">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <div class="lml-hh-member-form__field" data-field="date_registered">
            <label for="lml-hh-date-registered">
                Date Registered <span class="lml-hh-member-form__req" aria-hidden="true">*</span>
            </label>
            <input
                type="date"
                id="lml-hh-date-registered"
                name="date_registered"
                class="lml-hh-member-form__control lml-focus-ring"
                required
                aria-required="true"
                max="{{ now()->toDateString() }}"
                value="{{ $v['date_registered'] ?? '' }}"
            >
            @error('date_registered')
                <p class="lml-hh-member-form__error" role="alert">{{ $message }}</p>
            @enderror
        </div>

        @if ($accomplishedByWritable)
            <div class="lml-hh-member-form__field" data-field="accomplished_by">
                <label for="lml-hh-accomplished-by">Accomplished By</label>
                <input
                    type="text"
                    id="lml-hh-accomplished-by"
                    name="accomplished_by"
                    class="lml-hh-member-form__control lml-focus-ring"
                    placeholder="Name of staff"
                    maxlength="160"
                    value="{{ $v['accomplished_by'] ?? '' }}"
                >
                @error('accomplished_by')
                    <p class="lml-hh-member-form__error" role="alert">{{ $message }}</p>
                @enderror
            </div>
        @endif

        @if ($addressWritable)
            <div class="lml-hh-member-form__field lml-hh-member-form__field--span-2" data-field="address">
                <label for="lml-hh-address">Address</label>
                <input
                    type="text"
                    id="lml-hh-address"
                    name="address"
                    class="lml-hh-member-form__control lml-focus-ring"
                    placeholder="Optional full address"
                    maxlength="255"
                    value="{{ $v['address'] ?? '' }}"
                >
                @error('address')
                    <p class="lml-hh-member-form__error" role="alert">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <div class="lml-hh-member-form__field" data-field="latitude">
            <label for="lml-hh-latitude">Latitude</label>
            <input
                type="number"
                id="lml-hh-latitude"
                name="latitude"
                class="lml-hh-member-form__control lml-focus-ring"
                placeholder="Optional"
                step="any"
                min="-90"
                max="90"
                value="{{ $v['latitude'] ?? '' }}"
            >
            @error('latitude')
                <p class="lml-hh-member-form__error" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="lml-hh-member-form__field" data-field="longitude">
            <label for="lml-hh-longitude">Longitude</label>
            <input
                type="number"
                id="lml-hh-longitude"
                name="longitude"
                class="lml-hh-member-form__control lml-focus-ring"
                placeholder="Optional"
                step="any"
                min="-180"
                max="180"
                value="{{ $v['longitude'] ?? '' }}"
            >
            @error('longitude')
                <p class="lml-hh-member-form__error" role="alert">{{ $message }}</p>
            @enderror
        </div>
    </div>
</section>
