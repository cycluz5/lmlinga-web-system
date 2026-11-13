{{--
    SR-05 Language Management — Admin only (route middleware ui.admin).
    Terms are entered by an Admin and verified by a Linguist; only approved terms may be used by the chatbot.
--}}
@extends('layouts.dashboard')

@section('title', 'Language Management - LMLinga')

@section('content')
    <div class="lml-language-management" data-lml-language-management>
        @if (session('status'))
            <div class="alert alert-success mb-0" role="status">{{ session('status') }}</div>
        @endif

        <header class="lml-language-management__header">
            <div>
                <h1 class="lml-language-management__title">Bikol-Iriga terms</h1>
                <p class="lml-language-management__subtitle">
                    Add terms and submit them for Linguist review. Only approved terms may be used by the chatbot.
                </p>
            </div>
            <a href="{{ route('language-management.create') }}" class="btn btn-primary lml-focus-ring fw-medium">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                Add Term
            </a>
        </header>

        <nav class="lml-language-management__filters" aria-label="Filter terms by status">
            <a
                href="{{ route('language-management.index', array_filter(['q' => $search])) }}"
                class="lml-language-management__filter{{ $status === 'all' ? ' is-active' : '' }}"
                @if ($status === 'all') aria-current="page" @endif
            >All <span>{{ $total }}</span></a>
            @foreach ($filters as $filter)
                <a
                    href="{{ route('language-management.index', array_filter(['status' => $filter, 'q' => $search])) }}"
                    class="lml-language-management__filter{{ $status === $filter ? ' is-active' : '' }}"
                    data-lml-term-filter="{{ $filter }}"
                    @if ($status === $filter) aria-current="page" @endif
                >{{ \App\Support\ChatbotTermStatus::label($filter) }} <span>{{ $counts[$filter] ?? 0 }}</span></a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('language-management.index') }}" class="lml-language-management__search" role="search">
            @if ($status !== 'all')
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <label for="language-management-search" class="visually-hidden">Search terms</label>
            <input
                id="language-management-search"
                type="search"
                name="q"
                value="{{ $search }}"
                maxlength="120"
                class="form-control lml-form-control"
                placeholder="Search term or English meaning"
            >
            <button type="submit" class="btn btn-outline-primary lml-focus-ring fw-medium">Search</button>
        </form>

        @if (count($rows) === 0)
            <p class="lml-language-management__empty" data-lml-language-management-empty>
                <i class="bi bi-inbox" aria-hidden="true"></i>
                @if ($total === 0)
                    No terms yet. Use "Add Term" to enter the first Bikol-Iriga term.
                @else
                    No terms match this filter.
                @endif
            </p>
        @else
            <div class="table-responsive">
                <table class="table lml-language-management__table align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Bikol-Iriga term</th>
                            <th scope="col">English meaning</th>
                            <th scope="col">Status</th>
                            <th scope="col">Last updated</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr data-lml-term-row>
                                <td class="fw-semibold">{{ $row['term'] }}</td>
                                <td>{{ $row['english_meaning'] }}</td>
                                <td>
                                    <span class="lml-term-status lml-term-status--{{ $row['status'] }}">{{ $row['status_label'] }}</span>
                                    @if ($row['revision_label'])
                                        <span class="lml-term-status lml-term-status--revision">{{ $row['revision_label'] }}</span>
                                    @endif
                                </td>
                                <td>{{ $row['updated_at'] }}</td>
                                <td class="text-end">
                                    <a href="{{ route('language-management.show', ['termId' => $row['id']]) }}" class="btn btn-outline-secondary btn-sm lml-focus-ring">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
