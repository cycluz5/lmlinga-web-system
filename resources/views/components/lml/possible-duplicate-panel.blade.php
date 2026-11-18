@props(['kind' => 'member'])

{{-- Server-rendered possible-duplicate warning. Matches were derived server-side by DuplicateFinder during
     validation and flashed to the session; nothing here is taken from user input. The JS live check
     (duplicate-check.js) detects data-lml-possible-duplicate-panel and reuses this panel. --}}
@if ($errors->has('possible_duplicate'))
    @php
        $flashed = session('possible_duplicate_matches');
        $items = is_array($flashed) && ($flashed['kind'] ?? null) === $kind && is_array($flashed['items'] ?? null)
            ? $flashed['items']
            : [];
        $confirmLabel = $kind === 'head' ? 'This is a different household' : 'This is a different person';
    @endphp
    <div class="lml-possible-duplicate" data-lml-possible-duplicate-panel role="alert">
        <p class="lml-possible-duplicate__message">{{ $errors->first('possible_duplicate') }}</p>
        @if ($items !== [])
            <ul class="lml-possible-duplicate__list">
                @foreach ($items as $item)
                    <li>
                        @if (! empty($item['url']))
                            <a href="{{ $item['url'] }}" target="_blank" rel="noopener">{{ $item['name'] ?? '' }}</a>
                        @else
                            {{ $item['name'] ?? '' }}
                        @endif
                        @if (($item['relation'] ?? '') !== '')
                            ({{ $item['relation'] }})
                        @endif
                        &ndash; Household {{ $item['household_no'] ?? '' }}
                    </li>
                @endforeach
            </ul>
        @endif
        <label class="lml-possible-duplicate__confirm">
            <input type="checkbox" name="confirm_possible_duplicate" value="1" @checked(old('confirm_possible_duplicate') === '1')>
            <span>{{ $confirmLabel }}</span>
        </label>
    </div>
@endif
