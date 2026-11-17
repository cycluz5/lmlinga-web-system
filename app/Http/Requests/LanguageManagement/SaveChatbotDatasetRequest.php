<?php

namespace App\Http\Requests\LanguageManagement;

use App\Support\ChatbotDatasetCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SR-07 Admin upload / replacement of a chatbot dataset. Route middleware `ui.admin` limits who reaches it.
 * These rules are the first layer only; ChatbotDatasetFileInspector checks the real content, size,
 * encoding and type (the file name/extension alone is never trusted).
 */
class SaveChatbotDatasetRequest extends FormRequest
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
        $creating = $this->routeIs('chatbot-datasets.store');
        $maxKb = max(1, (int) config('lmlinga.chatbot_datasets.max_kilobytes', 100));

        return [
            'title' => ['required', 'string', 'max:160', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'source_reference' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'language' => $creating ? ['required', 'string', Rule::in(array_keys(ChatbotDatasetCatalog::LANGUAGES))] : ['prohibited'],
            'category' => $creating ? ['required', 'string', Rule::in(array_keys(ChatbotDatasetCatalog::CATEGORIES))] : ['prohibited'],
            'dataset_file' => [$creating ? 'required' : 'nullable', 'file', 'max:'.$maxKb, 'extensions:txt'],
            'no_personal_data_confirmed' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'source_reference' => 'source reference',
            'dataset_file' => 'dataset file',
            'no_personal_data_confirmed' => 'personal information confirmation',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dataset_file.extensions' => 'Only .txt files are accepted.',
            'no_personal_data_confirmed.accepted' => 'Confirm that the file contains no resident personal information.',
        ];
    }
}
