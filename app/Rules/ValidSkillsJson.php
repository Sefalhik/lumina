<?php

namespace App\Rules;

use App\Enums\SkillIcon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the JSON string stored in the `skills` translatable column.
 *
 * Expected structure:
 *
 * @example
 * [
 *   { "icon": "⬡", "name": "Backend", "techs": ["PHP", "Laravel"] },
 *   { "icon": "◈", "name": "Frontend", "techs": [] }
 * ]
 *
 * Rules enforced:
 * - Value must be valid JSON decoding to an array.
 * - `icon`, if present, must be one of the glyphs defined in {@see SkillIcon}.
 * - Each category must have a non-empty, non-whitespace `name`.
 * - Each entry in `techs` must be a non-empty, non-whitespace string.
 * - An empty `techs` array is allowed (category with no techs yet).
 */
class ValidSkillsJson implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $categories = json_decode((string) $value, true);

        if (! is_array($categories)) {
            $fail(__('admin.skills_invalid_json'));

            return;
        }

        foreach ($categories as $i => $category) {
            if (isset($category['icon']) && ! in_array($category['icon'], SkillIcon::glyphs(), true)) {
                $fail(__('admin.skills_invalid_icon', ['n' => $i + 1]));

                return;
            }

            if (empty(trim((string) ($category['name'] ?? '')))) {
                $fail(__('admin.skills_category_name_required', ['n' => $i + 1]));

                return;
            }

            foreach ((array) ($category['techs'] ?? []) as $j => $tech) {
                if (empty(trim((string) $tech))) {
                    $fail(__('admin.skills_tech_name_required', ['n' => $i + 1, 't' => $j + 1]));

                    return;
                }
            }
        }
    }
}
