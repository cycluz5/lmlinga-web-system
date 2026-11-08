{{-- Announcement row actions: view, edit, and delete the selected DB record. --}}
@php
    $menuId = 'lml-announce-menu-'.$variant.'-'.$itemId;
@endphp

<div class="lml-announce__action-menu" data-announce-action-menu>
    <button
        type="button"
        class="lml-announce__action-btn lml-focus-ring"
        aria-haspopup="true"
        aria-expanded="false"
        aria-controls="{{ $menuId }}"
        data-announce-action-toggle
        aria-label="Announcement actions"
    >
        <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
    </button>
    <div
        id="{{ $menuId }}"
        class="lml-announce__action-list"
        role="menu"
        hidden
        data-announce-action-list
    >
        <a
            href="{{ route('announcements.show', $itemId) }}"
            class="lml-announce__action-item"
            role="menuitem"
            data-announce-action="view"
        >
            <i class="bi bi-eye" aria-hidden="true"></i>
            <span>View</span>
        </a>
        <a
            href="{{ route('announcements.edit', $itemId) }}"
            class="lml-announce__action-item"
            role="menuitem"
            data-announce-action="edit"
        >
            <i class="bi bi-pencil" aria-hidden="true"></i>
            <span>Edit</span>
        </a>
        <form
            method="POST"
            action="{{ route('announcements.destroy', $itemId) }}"
            onsubmit="return confirm('Delete this announcement? This cannot be undone.');"
        >
            @csrf
            @method('DELETE')
            <button
                type="submit"
                class="lml-announce__action-item lml-announce__action-item--danger"
                role="menuitem"
                data-announce-action="delete"
            >
                <i class="bi bi-trash" aria-hidden="true"></i>
                <span>Delete</span>
            </button>
        </form>
    </div>
</div>
