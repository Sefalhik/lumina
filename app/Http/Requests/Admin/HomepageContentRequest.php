<?php

namespace App\Http\Requests\Admin;

use App\Rules\ValidSkillsJson;
use Illuminate\Foundation\Http\FormRequest;

class HomepageContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string|ValidSkillsJson>> */
    public function rules(): array
    {
        return [
            'tagline.fr' => ['required', 'string', 'max:200'],
            'subtitle.fr' => ['required', 'string', 'max:200'],
            'bio.fr' => ['required', 'string', 'max:1000'],
            'meta_description.fr' => ['required', 'string', 'max:160'],
            'skills.fr' => ['required', 'string', new ValidSkillsJson],
        ];
    }
}
