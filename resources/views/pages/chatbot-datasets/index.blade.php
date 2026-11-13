{{--
    SR-07 Chatbot Datasets — Admin only (route middleware ui.admin).
    Uploaded datasets are reviewed by a Linguist. Approved datasets are not used by the chatbot until
    dataset indexing is separately approved; the current chatbot knowledge base is unchanged.
--}}
@extends('layouts.dashboard')

@section('title', 'Chatbot Datasets - LMLinga')

@section('content')
    <div class="lml-language-management" data-lml-chatbot-datasets>
        @if (session('status'))
            <div class="alert alert-success mb-0" role="status">{{ session('status') }}</div>
        @endif

        <header class="lml-language-management__header">
            <div>
                <h1 class="lml-language-management__title">Chatbot datasets</h1>
                <p class="lml-language-management__subtitle">
                    Upload health information as .txt files and submit them for Linguist review.
                    Approved datasets are not used by the chatbot yet; the current chatbot knowledge base is unchanged.
                </p>
            </div>
            <a href="{{ route('chatbot-datasets.create') }}" class="btn btn-primary lml-focus-ring fw-medium">
                <i class="bi bi-upload" aria-hidden="true"></i>
                Upload Dataset
            </a>
        </header>

        <nav class="lml-language-management__filters" aria-label="Filter datasets by status">
            <a
                href="{{ route('chatbot-datasets.index', array_filter(['q' => $search, 'language' => $language !== 'all' ? $language : null])) }}"
                class="lml-language-management__filter{{ $status === 'all' ? ' is-active' : '' }}"
                @if ($status === 'all') aria-current="page" @endif
            >All <span>{{ $total }}</span></a>
            @foreach ($filters as $filter)
                <a
                    href="{{ route('chatbot-datasets.index', array_filter(['status' => $filter, 'q' => $search, 'language' => $language !== 'all' ? $language : null])) }}"
                    class="lml-language-management__filter{{ $status === $filter ? ' is-active' : '' }}"
                    data-lml-dataset-filter="{{ $filter }}"
                    @if ($status === $filter) aria-current="page" @endif
                >{{ \App\Support\ChatbotDatasetCatalog::label($filter) }} <span>{{ $counts[$filter] ?? 0 }}</span></a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('chatbot-datasets.index') }}" class="lml-language-management__search" role="search">
            @if ($status !== 'all')
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <label for="chatbot-datasets-language" class="visually-hidden">Language</label>
            <select id="chatbot-datasets-language" name="language" class="form-select lml-form-control">
                <option value="all">All languages</option>
                @foreach ($languages as $key => $label)
                    <option value="{{ $key }}" @selected($language === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <label for="chatbot-datasets-search" class="visually-hidden">Search datasets</label>
            <input id="chatbot-datasets-search" type="search" name="q" value="{{ $search }}" maxlength="160"
                class="form-control lml-form-control" placeholder="Search title">
            <button type="submit" class="btn btn-outline-primary lml-focus-ring fw-medium">Search</button>
        </form>

        @if (count($rows) === 0)
            <p class="lml-language-management__empty" data-lml-chatbot-datasets-empty>
                <i class="bi bi-inbox" aria-hidden="true"></i>
                @if ($total === 0)
                    No datasets yet. Use "Upload Dataset" to add the first one.
                @else
                    No datasets match this filter.
                @endif
            </p>
        @else
            <div class="table-responsive">
                <table class="table lml-language-management__table align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Title</th>
                            <th scope="col">Language</th>
                            <th scope="col">Category</th>
                            <th scope="col">Status</th>
                            <th scope="col">Last updated</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr data-lml-dataset-row>
                                <td class="fw-semibold">{{ $row['title'] }}</td>
                                <td>{{ $row['language_label'] }}</td>
                                <td>{{ $row['category_label'] }}</td>
                                <td>
                                    <span class="lml-term-status lml-term-status--{{ $row['status'] }}">{{ $row['status_label'] }}</span>
                                    @if ($row['revision_label'])
                                        <span class="lml-term-status lml-term-status--revision">{{ $row['revision_label'] }}</span>
                                    @endif
                                </td>
                                <td>{{ $row['updated_at'] }}</td>
                                <td class="text-end">
                                    <a href="{{ route('chatbot-datasets.show', ['datasetId' => $row['id']]) }}" class="btn btn-outline-secondary btn-sm lml-focus-ring">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
