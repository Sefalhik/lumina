<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class HomepageContent extends Model
{
    use HasTranslations;

    /** @var list<string> */
    public array $translatable = ['tagline', 'subtitle', 'bio', 'meta_description', 'skills'];

    protected $fillable = ['tagline', 'subtitle', 'bio', 'meta_description', 'skills'];
}
