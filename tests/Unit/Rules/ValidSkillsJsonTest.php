<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidSkillsJson;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ValidSkillsJsonTest extends TestCase
{
    private function passes(mixed $value): bool
    {
        return Validator::make(
            ['skills' => $value],
            ['skills' => [new ValidSkillsJson]],
        )->passes();
    }

    public function test_valid_payload_passes(): void
    {
        $this->assertTrue($this->passes(json_encode([
            ['icon' => '⬡', 'name' => 'Backend', 'techs' => ['PHP', 'Laravel']],
        ])));
    }

    public function test_empty_techs_array_passes(): void
    {
        $this->assertTrue($this->passes(json_encode([
            ['icon' => '⬡', 'name' => 'Backend', 'techs' => []],
        ])));
    }

    public function test_multiple_categories_pass(): void
    {
        $this->assertTrue($this->passes(json_encode([
            ['icon' => '⬡', 'name' => 'Backend',  'techs' => ['PHP']],
            ['icon' => '◈', 'name' => 'Frontend', 'techs' => ['Vue']],
        ])));
    }

    public function test_empty_array_passes(): void
    {
        $this->assertTrue($this->passes('[]'));
    }

    public function test_invalid_json_fails(): void
    {
        $this->assertFalse($this->passes('not-json'));
    }

    public function test_category_with_empty_name_fails(): void
    {
        $this->assertFalse($this->passes(json_encode([
            ['icon' => '⬡', 'name' => '', 'techs' => ['PHP']],
        ])));
    }

    public function test_category_with_whitespace_only_name_fails(): void
    {
        $this->assertFalse($this->passes(json_encode([
            ['icon' => '⬡', 'name' => '   ', 'techs' => ['PHP']],
        ])));
    }

    public function test_tech_with_empty_name_fails(): void
    {
        $this->assertFalse($this->passes(json_encode([
            ['icon' => '⬡', 'name' => 'Backend', 'techs' => ['PHP', '', 'Laravel']],
        ])));
    }

    public function test_tech_with_whitespace_only_name_fails(): void
    {
        $this->assertFalse($this->passes(json_encode([
            ['icon' => '⬡', 'name' => 'Backend', 'techs' => ['   ']],
        ])));
    }

    public function test_valid_icon_passes(): void
    {
        $this->assertTrue($this->passes(json_encode([
            ['icon' => '◈', 'name' => 'Frontend', 'techs' => ['Vue']],
        ])));
    }

    public function test_invalid_icon_fails(): void
    {
        $this->assertFalse($this->passes(json_encode([
            ['icon' => 'X', 'name' => 'Backend', 'techs' => ['PHP']],
        ])));
    }

    public function test_missing_icon_passes(): void
    {
        $this->assertTrue($this->passes(json_encode([
            ['name' => 'Backend', 'techs' => ['PHP']],
        ])));
    }
}
