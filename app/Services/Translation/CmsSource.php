<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\Contracts\TranslatableContent;
use Illuminate\Database\Eloquent\Model;

/**
 * One CMS record and its French: what `cms:translate` translates. Its id names it in the registry
 * — `cms_HomepageContent_1` — as `php:nav` names a file.
 */
final readonly class CmsSource
{
    /**
     * @param  array<string, string>  $strings  the French of every translatable field
     */
    public function __construct(
        public Model&TranslatableContent $record,
        public string $id,
        public array $strings,
    ) {}

    public function label(): string
    {
        return class_basename($this->record).' #'.$this->record->getKey();
    }
}
