{{-- Shared Health Records (and future) sidebar child rows. --}}
@props([
    'children' => [],
    'active' => '',
    'adminOnlyDisabled' => false,
    'pendingDeathRequests' => 0,
])

<ul class="lml-sidebar__sublist list-unstyled mb-0">
    @foreach ($children as $child)
        @php
            $isActiveChild = ($child['key'] ?? '') === $active;
            $childHref = $child['href'] ?? null;
            $hasChildHref = filled($childHref) && $childHref !== '#' && ! $adminOnlyDisabled;
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
                    @if (($child['key'] ?? '') === 'death-requests' && ! $adminOnlyDisabled && $pendingDeathRequests > 0)
                        <span
                            class="lml-sidebar__count"
                            aria-label="{{ $pendingDeathRequests }} pending death {{ $pendingDeathRequests === 1 ? 'request' : 'requests' }}"
                        >{{ $pendingDeathRequests }}</span>
                    @endif
                </a>
            @else
                {{--
                  Named route missing — render a non-navigating item.
                  Admin-only children for workers are also non-navigating.
                  Do not invent destinations or use href="#".
                --}}
                <span
                    @class([
                        'lml-sidebar__sublink',
                        'lml-sidebar__sublink--unavailable' => ! $adminOnlyDisabled,
                        'lml-sidebar__link--disabled' => $adminOnlyDisabled,
                        'lml-sidebar__sublink--active' => $isActiveChild,
                    ])
                    aria-disabled="true"
                    @if ($adminOnlyDisabled)
                        data-lml-admin-only
                        tabindex="0"
                        role="button"
                    @endif
                >
                    @if (! empty($child['icon']))
                        <i class="bi {{ $child['icon'] }} lml-sidebar__subicon" aria-hidden="true"></i>
                    @endif
                    <span>{{ $child['label'] }}</span>
                    @if (($child['key'] ?? '') === 'death-requests' && ! $adminOnlyDisabled && $pendingDeathRequests > 0)
                        <span
                            class="lml-sidebar__count"
                            aria-label="{{ $pendingDeathRequests }} pending death {{ $pendingDeathRequests === 1 ? 'request' : 'requests' }}"
                        >{{ $pendingDeathRequests }}</span>
                    @endif
                </span>
            @endif
        </li>
    @endforeach
</ul>
