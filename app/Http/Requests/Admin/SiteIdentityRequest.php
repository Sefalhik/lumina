<?php

namespace App\Http\Requests\Admin;

use App\Rules\ProfileUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SiteIdentityRequest extends FormRequest
{
    /** @var list<string> */
    private const PROFILE_FIELDS = ['github_url', 'linkedin_url', 'mastodon_url'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Tidy the input before judging it.
     *
     * Copying a profile URL from LinkedIn's interface yields a ?trk=... suffix:
     * harmless to a browser, but noise in a sameAs declaration and a needless
     * source of churn. Stripping it silently is kinder than rejecting the user
     * over something we can fix ourselves.
     */
    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (self::PROFILE_FIELDS as $field) {
            $value = $this->input($field);

            if (is_string($value) && trim($value) !== '') {
                $clean[$field] = $this->stripQueryAndFragment(trim($value));
            }
        }

        foreach (['full_name', 'contact_email'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $clean[$field] = trim($value);
            }
        }

        if ($clean !== []) {
            $this->merge($clean);
        }
    }

    private function stripQueryAndFragment(string $url): string
    {
        return explode('#', explode('?', $url, 2)[0], 2)[0];
    }

    /**
     * Every field is optional: the site must render correctly with an empty
     * identity, so the form must accept a partial one.
     *
     * URLs are checked on three axes — https (Laravel), the right network, and
     * the shape of a profile page. A plain `url` rule accepts
     * https://github.com/ and a LinkedIn address in the GitHub field alike;
     * both would end up in a sameAs statement and misinform.
     *
     * @return array<string, array<int, string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['nullable', 'string', 'max:120'],
            'job_title.fr' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email:rfc', 'max:180'],
            'github_url' => ['nullable', 'string', 'max:255', 'url:https', ProfileUrl::github()],
            'linkedin_url' => ['nullable', 'string', 'max:255', 'url:https', ProfileUrl::linkedin()],
            'mastodon_url' => ['nullable', 'string', 'max:255', 'url:https', ProfileUrl::mastodon()],
        ];
    }

    /**
     * Laravel's default `url` message says nothing about the https requirement,
     * which is the reason it fails most of the time here.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_reduce(
            self::PROFILE_FIELDS,
            function (array $messages, string $field): array {
                $messages[$field.'.url'] = __('admin.identity_url_requires_https');

                return $messages;
            },
            [],
        );
    }
}
