<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExperienceRequest extends FormRequest
{
    /** Authorisation is the middleware chain's job: auth, role:admin, 2FA. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An empty ended_at is the "still in this position" case, so it stays
     * nullable — but when it is given it cannot precede the start.
     *
     * @return array<string, array<int, string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'employer' => ['required', 'string', 'max:120'],
            'job_title' => ['required', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'description' => ['nullable', 'string', 'max:2000'],
            'achievements' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ended_at.after_or_equal' => __('admin.experience_end_before_start'),
        ];
    }
}
