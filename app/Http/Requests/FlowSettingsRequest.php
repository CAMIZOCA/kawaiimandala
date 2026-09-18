<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class FlowSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'flows' => ['required', 'array'],
            'flows.*.flow_url' => ['nullable', 'url:http,https', 'max:2048'],
            'flows.*.enabled' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'flows.*.flow_url.url' => 'El enlace del flujo del mandala :position debe ser una URL http(s) válida.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (array_keys((array) $this->input('flows', [])) as $position) {
                if (! is_numeric($position) || $position < 1 || $position > config('kawaii.max_mandalas')) {
                    $validator->errors()->add('flows', "Posición de mandala inválida: {$position}.");
                }
            }
        }];
    }
}
