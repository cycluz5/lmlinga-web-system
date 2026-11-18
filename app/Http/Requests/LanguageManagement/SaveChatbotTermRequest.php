<?php

namespace App\Http\Requests\LanguageManagement;

use App\Support\ChatbotTermStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SR-05 Admin create/edit of a Bikol-Iriga term (D-T3 fields). Route middleware `ui.admin` limits who
 * reaches it; ChatbotTermWorkflow checks the role again. Text is stored as plain text and escaped on output.
 */
class SaveChatbotTermRequest extends FormRequest
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
            'term' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'english_meaning' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'tagalog_meaning' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'part_of_speech' => ['nullable', 'string', Rule::in(array_keys(ChatbotTermStatus::PARTS_OF_SPEECH))],
            'example_usage' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'term' => 'Bikol-Iriga term',
            'english_meaning' => 'English meaning',
            'tagalog_meaning' => 'Tagalog meaning',
            'part_of_speech' => 'part of speech',
            'example_usage' => 'example usage',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function content(): array
    {
        return $this->safe()->only(['term', 'english_meaning', 'tagalog_meaning', 'part_of_speech', 'example_usage', 'notes']);
    }
}
