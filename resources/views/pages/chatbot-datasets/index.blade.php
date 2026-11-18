{{--
    Chatbot Datasets — Admin only (route middleware ui.admin).
    The health information the chatbot answers from, grouped by language (English, Tagalog, Bikol-Iriga).
    Saving a dataset sends it into the chatbot's health information at once.
    Layout follows the standard admin listings: tabs + toolbar (User Management), table (Household Requests),
    rows paged by the shared pagination component (data-lml-paginate).
--}}
@extends('layouts.dashboard')

@section('title', 'Chatbot Datasets - LMLinga')

@php
    $languageLabel = $languages[$language] ?? $language;
@endphp

@section('content')
    <div class="lml-cd" data-lml-chatbot-datasets>
        @if (session('status'))
            <p class="lml-cd__alert lml-cd__alert--success" role="status">{{ session('status') }}</p>
        @endif

        @if ($importable > 0)
            <form method="POST" action="{{ route('chatbot-datasets.import-existing') }}" class="lml-cd__notice" data-lml-dataset-import>
                @csrf
                <p>
                    <strong>{{ $importable }} existing chatbot document(s)</strong> are not listed here yet.
                    Listing them lets you see and change them. The chatbot keeps using them as they are until you change one.
                </p>
                <div class="form-check">
                    <input id="import-confirm" type="checkbox" name="no_personal_data_confirmed" value="1" required class="form-check-input @error('no_personal_data_confirmed') is-invalid @enderror">
                    <label for="import-confirm" class="form-check-label">I confirm these documents contain no resident or patient personal information.</label>
                    @error('no_personal_data_confirmed')<p class="lml-form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <button type="submit" class="lml-user-mgmt__add-btn lml-focus-ring">
                        <i class="bi bi-journal-check" aria-hidden="true"></i>
                        <span>Show existing documents</span>
                    </button>
                </div>
            </form>
        @endif

        <nav class="lml-user-mgmt__tabs" aria-label="Dataset languages">
            @foreach ($languages as $key => $label)
                <a
                    href="{{ route('chatbot-datasets.index', ['language' => $key]) }}"
                    class="lml-user-mgmt__tab lml-focus-ring{{ $language === $key ? ' lml-user-mgmt__tab--active' : '' }}"
                    data-lml-dataset-language="{{ $key }}"
                    @if ($language === $key) aria-current="page" @endif
                >
                    <span>{{ $label }}</span>
                    <span class="lml-cd__count">{{ $languageCounts[$key] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>

        <div class="lml-user-mgmt__toolbar" role="search" aria-label="Filter {{ $languageLabel }} datasets">
            <div class="lml-user-mgmt__search">
                <i class="bi bi-search lml-user-mgmt__search-icon" aria-hidden="true"></i>
                <label class="visually-hidden" for="lml-cd-search">Search dataset</label>
                <input
                    type="search"
                    id="lml-cd-search"
                    class="lml-user-mgmt__search-input"
                    placeholder="Search dataset"
                    autocomplete="off"
                    maxlength="160"
                    data-cd-search
                >
            </div>

            <div class="lml-user-mgmt__toolbar-end">
                <div class="lml-user-mgmt__category">
                    <label class="visually-hidden" for="lml-cd-category">Category</label>
                    <select id="lml-cd-category" class="lml-user-mgmt__category-select" data-cd-category>
                        <option value="all" selected>All Categories</option>
                        @foreach ($categories as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <i class="bi bi-chevron-down lml-user-mgmt__category-icon" aria-hidden="true"></i>
                </div>

                <a href="{{ route('chatbot-datasets.create', ['language' => $language]) }}" class="lml-user-mgmt__add-btn lml-focus-ring" data-lml-dataset-add>
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    <span>Add Dataset</span>
                </a>
            </div>
        </div>

        @if (count($rows) > 0)
            <div class="lml-hr-table-wrap" data-cd-table-wrap>
                <table class="lml-hr-table lml-cd-table">
                    <caption class="visually-hidden">{{ $languageLabel }} chatbot datasets by title and category</caption>
                    <thead>
                        <tr>
                            <th scope="col">Title</th>
                            <th scope="col">Category</th>
                            <th scope="col">Last updated</th>
                            <th scope="col"><span class="visually-hidden">View</span></th>
                        </tr>
                    </thead>
                    <tbody data-lml-paginate="lml-dataset-row" data-lml-paginate-label="datasets">
                        @foreach ($rows as $row)
                            <tr
                                class="lml-hr-table__row"
                                data-lml-dataset-row
                                data-cd-title="{{ $row['title'] }}"
                                data-cd-source="{{ $row['source_name'] }}"
                                data-cd-category="{{ $row['category'] }}"
                            >
                                <td class="lml-hr-table__cell" data-label="Title">
                                    <span class="lml-hr-table__name">{{ $row['title'] }}</span>
                                    <span class="lml-cd__langs" data-lml-dataset-langs aria-label="Available in">
                                        @foreach ($languages as $key => $label)
                                            <span class="badge {{ in_array($key, $row['languages'], true) ? 'text-bg-success' : 'text-bg-light border' }}" title="{{ $label }}{{ in_array($key, $row['languages'], true) ? '' : ' (missing)' }}">{{ strtoupper($key) }}</span>
                                        @endforeach
                                    </span>
                                    <span class="lml-cd__source">{{ $row['source_name'] }}@if ($row['retired']) · Retired @endif @if ($row['is_default']) <span class="badge text-bg-secondary" data-lml-dataset-default>Default</span> @endif</span>
                                </td>
                                <td class="lml-hr-table__cell lml-hr-table__cell--zone" data-label="Category">{{ $row['category_label'] }}</td>
                                <td class="lml-hr-table__cell" data-label="Last updated">{{ $row['updated_at'] }}</td>
                                <td class="lml-hr-table__cell lml-hr-table__cell--actions" data-label="View">
                                    <a
                                        href="{{ route('chatbot-datasets.show', ['datasetId' => $row['id']]) }}"
                                        class="lml-hr-table__view-btn lml-focus-ring"
                                        aria-label="View dataset {{ $row['title'] }}"
                                    >
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                        <span>View</span>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="lml-hr__empty" role="status" aria-live="polite" hidden data-cd-empty data-lml-chatbot-datasets-empty>
                No {{ $languageLabel }} datasets match your search or filters.
            </p>
        @else
            <p class="lml-hr__empty" role="status" data-lml-chatbot-datasets-empty>
                @if ($total === 0 && $importable > 0)
                    No datasets are listed yet. Use "Show existing documents" above to list the ones the chatbot already has, or "Add Dataset" to add a new one.
                @else
                    No {{ $languageLabel }} datasets yet. Use "Add Dataset" to add one.
                @endif
            </p>
        @endif
    </div>
@endsection
