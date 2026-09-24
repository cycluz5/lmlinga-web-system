@php
    $emptyRecord = $emptyRecord ?? 'No record';
    $summaryAttr = $summaryAttr ?? 'data-child-imm-birth-summary';
    $headingId = $headingId ?? 'lml-hr-cc-nr-birth-heading';
    $editUrl = $editUrl ?? '#';
    $editLinkAttr = $editLinkAttr ?? 'data-child-imm-birth-edit-link';
    $birthHistory = $birthHistory ?? [
        'weight' => ['label' => 'Birth Weight', 'value' => $emptyRecord],
        'length' => ['label' => 'Birth Length', 'value' => $emptyRecord],
        'status' => ['label' => 'Status', 'value' => $emptyRecord],
        'pcab' => ['label' => 'PCAB from Neonatal Tetanus', 'value' => $emptyRecord],
    ];
@endphp

<div class="lml-child-imm__birth-history" aria-labelledby="{{ $headingId }}">
    <div class="lml-child-imm__birth-head">
        <h2 id="{{ $headingId }}" class="lml-child-imm__birth-title">
            <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
            <span>Birth History</span>
        </h2>
        <a
            href="{{ $editUrl }}"
            class="lml-child-imm__birth-edit-link lml-focus-ring"
            {{ $editLinkAttr }}
            aria-label="Edit birth history"
        >
            <i class="bi bi-pencil-square" aria-hidden="true"></i>
            <span>Edit</span>
        </a>
    </div>
    <dl class="lml-child-imm__birth-dl" {{ $summaryAttr }}>
        @foreach ($birthHistory as $key => $item)
            <div class="lml-child-imm__birth-item">
                <dt>{{ $item['label'] }}</dt>
                <dd data-birth-summary="{{ $key }}">{{ $item['value'] }}</dd>
            </div>
        @endforeach
    </dl>
</div>
