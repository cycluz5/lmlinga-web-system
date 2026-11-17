<?php

namespace App\Http\Requests\LanguageManagement;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SR-05 / SR-07 Linguist approve/reject (terms and datasets). Route middleware `ui.linguist` limits who reaches it; the note is
 * required for a rejection (checked here and again in ChatbotTermWorkflow).
 */
class ReviewChatbotTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'review_note' => [$this->routeIs('language-review.*.reject') ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'review_note.required' => 'Explain what needs to change before rejecting.',
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('review_note');

        return is_string($note) ? $note : null;
    }
}
