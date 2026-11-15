<?php

namespace App\Models;

use App\Models\Contracts\TranslatableContent;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class HomepageContent extends Model implements TranslatableContent
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['tagline', 'subtitle', 'bio', 'meta_description', 'skills'];

    protected $fillable = ['tagline', 'subtitle', 'bio', 'meta_description', 'skills'];
}
