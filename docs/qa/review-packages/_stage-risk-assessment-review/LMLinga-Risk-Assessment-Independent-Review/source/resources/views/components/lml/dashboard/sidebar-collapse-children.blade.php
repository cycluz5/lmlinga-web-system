{{-- Shared Health Records (and future) sidebar child rows. --}}
@props([
    'children' => [],
    'active' => '',
])

<ul class="lml-sidebar__sublist list-unstyled mb-0">
    @foreach ($children as $child)
        @php
            $isActiveChild = ($child['key'] ?? '') === $active;
            $childHref = $child['href'] ?? null;
            $hasChildHref = filled($childHref) && $childHref !== '#';
        @endphp
        <li>
            @if ($hasChildHref)
                <a
                    href="{{ $childHref }}"
                    @class([
                        'lml-sidebar__sublink',
                        'lml-sidebar__sublink--active' => $isActiveChild,
                    ])
                    @if ($isActiveChild) aria-current="page" @endif
                >
                    @if (! empty($child['icon']))
                        <i class="bi {{ $child['icon'] }} lml-sidebar__subicon" aria-hidden="true"></i>
                    @endif
                    <span>{{ $child['label'] }}</span>
                </a>
            @else
                {{--
                  Named route missing — render a non-navigating item.
                  Do not invent destinations or use href="#".
                --}}
                <span
                    @class([
                        'lml-sidebar__sublink',
                        'lml-sidebar__sublink--unavailable',
                        'lml-sidebar__sublink--active' => $isActiveChild,
                    ])
                    aria-disabled="true"
                >
                    @if (! empty($child['icon']))
                        <i class="bi {{ $child['icon'] }} lml-sidebar__subicon" aria-hidden="true"></i>
                    @endif
                    <span>{{ $child['label'] }}</span>
                </span>
            @endif
        </li>
    @endforeach
</ul>
