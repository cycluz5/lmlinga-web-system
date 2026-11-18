{{--
    SR-07 one dataset version: metadata, screening notes and the full text as escaped plain text.
    Screening is a preliminary safeguard only; the Linguist's review is the decision.
--}}
<dl class="lml-term-version" data-lml-dataset-version="{{ $version['version_no'] }}">
    <div><dt>Title</dt><dd class="fw-semibold">{{ $version['title'] }}</dd></div>
    <div><dt>Source reference</dt><dd>{{ $version['source_reference'] }}</dd></div>
    <div><dt>Size</dt><dd>{{ $version['size_kb'] }} KB · {{ $version['line_count'] }} lines</dd></div>
    <div><dt>Uploaded file</dt><dd>{{ $version['original_filename'] ?? '—' }}</dd></div>
    <div><dt>Uploaded by</dt><dd>{{ $version['created_by'] }}, {{ $version['created_at'] }}</dd></div>
    <div><dt>No resident personal information</dt><dd>{{ $version['no_personal_data_confirmed'] ? 'Confirmed by the uploader' : 'Not confirmed' }}</dd></div>
</dl>

<div class="lml-dataset-screening" data-lml-dataset-screening>
    <p class="lml-dataset-screening__title">Automated screening (preliminary only — not a guarantee)</p>
    @if (count($version['warnings']) === 0)
        <p class="lml-dataset-screening__ok" data-lml-dataset-screening-clear>No warnings. Read the full text before deciding.</p>
    @else
        <ul class="lml-dataset-screening__list">
            @foreach ($version['warnings'] as $warning)
                <li data-lml-dataset-warning>{{ $warning }}</li>
            @endforeach
        </ul>
    @endif
</div>

@if ($showContent ?? true)
    <details class="lml-dataset-content" {{ ($openContent ?? false) ? 'open' : '' }}>
        <summary>Full text</summary>
        <pre class="lml-dataset-content__text" data-lml-dataset-content>{{ $version['content'] }}</pre>
    </details>
@endif
