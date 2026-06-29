<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TemplateRevision extends Model
{
    protected $fillable = [
        'template_id',
        'layout_json',
        'conditions_json',
        'revision_type',
        'note',
        'created_by',
    ];

    protected $casts = [
        'layout_json' => 'array',
        'conditions_json' => 'array',
    ];

    public function template()
    {
        return $this->belongsTo(Template::class);
    }
}