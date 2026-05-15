<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class HomepageContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tagline.fr' => ['required', 'string', 'max:200'],
            'subtitle.fr' => ['required', 'string', 'max:200'],
            'bio.fr' => ['required', 'string', 'max:1000'],
        ];
    }
}
