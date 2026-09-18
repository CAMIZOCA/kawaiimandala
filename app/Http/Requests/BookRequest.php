<?php

namespace App\Http\Requests;

use App\Models\Book;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class BookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'animal_theme' => ['required', 'string', 'max:120'],
            'introduction' => ['nullable', 'string', 'max:10000'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'creator_description' => ['nullable', 'string', 'max:5000'],
            'copyright_text' => ['nullable', 'string', 'max:2000'],
            'copyright_year' => ['nullable', 'integer', 'min:1900', 'max:2200'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'mandala_count' => [
                'required', 'integer',
                'min:'.config('kawaii.min_mandalas'),
                'max:'.config('kawaii.max_mandalas'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'título',
            'subtitle' => 'subtítulo',
            'animal_theme' => 'animal / tema',
            'introduction' => 'introducción',
            'author_name' => 'nombre del creador',
            'creator_description' => 'descripción del creador',
            'copyright_text' => 'texto de copyright',
            'copyright_year' => 'año de copyright',
            'website_url' => 'website',
            'mandala_count' => 'cantidad de mandalas',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $book = $this->route('book');

            if (! $book instanceof Book || $validator->errors()->has('mandala_count')) {
                return;
            }

            $blocking = $book->mandalas()
                ->where('position', '>', (int) $this->input('mandala_count'))
                ->whereNotNull('image_path')
                ->count();

            if ($blocking > 0) {
                $validator->errors()->add(
                    'mandala_count',
                    "No se puede reducir la cantidad: {$blocking} mandala(s) con imagen quedarían fuera. Elimínalos primero.",
                );
            }
        }];
    }
}
