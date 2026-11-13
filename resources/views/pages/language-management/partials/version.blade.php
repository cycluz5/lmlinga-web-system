{{-- SR-05 one term version (plain text, escaped). Used by Language Management and Language Review. --}}
<dl class="lml-term-version" data-lml-term-version="{{ $version['version_no'] }}">
    <div><dt>Bikol-Iriga term</dt><dd class="fw-semibold">{{ $version['term'] }}</dd></div>
    <div><dt>English meaning</dt><dd>{{ $version['english_meaning'] }}</dd></div>
    <div><dt>Tagalog meaning</dt><dd>{{ $version['tagalog_meaning'] ?? '—' }}</dd></div>
    <div><dt>Part of speech</dt><dd>{{ $version['part_of_speech_label'] ?? '—' }}</dd></div>
    <div class="lml-term-version__wide"><dt>Example usage</dt><dd class="lml-term-version__text">{{ $version['example_usage'] ?? '—' }}</dd></div>
    <div class="lml-term-version__wide"><dt>Notes for the reviewer</dt><dd class="lml-term-version__text">{{ $version['notes'] ?? '—' }}</dd></div>
</dl>
