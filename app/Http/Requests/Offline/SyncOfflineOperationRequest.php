<?php

namespace App\Http\Requests\Offline;

use App\Support\Offline\OfflineOperationType;
use App\Support\Offline\OfflineSyncException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncOfflineOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['actor_user_id', 'actor_username', 'server_result', 'status'] as $ignored) {
            $this->request->remove($ignored);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operation_id' => ['required', 'uuid'],
            'schema_version' => ['required', 'integer', 'in:1'],
            'operation_type' => ['required', 'string', Rule::in(OfflineOperationType::ALL)],
            'payload' => ['required', 'array'],
            'base_snapshot' => ['nullable', 'array'],
            'parent_server' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function offlineEnvelope(): array
    {
        $validated = $this->validated();

        return [
            'operation_id' => strtolower((string) $validated['operation_id']),
            'schema_version' => (int) $validated['schema_version'],
            'operation_type' => (string) $validated['operation_type'],
            'payload' => is_array($validated['payload'] ?? null) ? $validated['payload'] : [],
            'base_snapshot' => is_array($validated['base_snapshot'] ?? null) ? $validated['base_snapshot'] : null,
            'parent_server' => is_array($validated['parent_server'] ?? null) ? $validated['parent_server'] : null,
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->toArray();

        if (isset($errors['operation_type']) && $this->filled('operation_type') && ! OfflineOperationType::isKnown((string) $this->input('operation_type'))) {
            throw OfflineSyncException::unknownOperation();
        }

        $malformedFields = ['operation_id', 'schema_version', 'payload'];
        foreach ($malformedFields as $field) {
            if (isset($errors[$field])) {
                throw OfflineSyncException::malformed(
                    $validator->errors()->first($field) ?: 'The sync envelope is malformed.',
                    $errors,
                );
            }
        }

        throw OfflineSyncException::validationFailed($errors, $validator->errors()->first() ?: null);
    }
}
