<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HelpCategory extends Model
{
    use HasFactory;
    protected $table='help_categories';
    protected $guarded=[];

    public function subcategories()
    {
        return $this->hasMany(HelpSubcategory::class);
    }

    public function childcategories()
    {
        return $this->hasMany(HelpChildcategory::class);
    }

    public function articles()
    {
        return $this->hasMany(HelpArticle::class);
    }

    protected $appends = ['full_image_url'];

    public function getFullImageUrlAttribute()
    {
        if ($this->image) {
            return config('app.url') . '/uploads/help/' . ltrim($this->image, '/');
        }
        return null;
    }

}
