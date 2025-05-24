<?php

// CompanyConsultancyProject.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyConsultancyProject extends Model
{
    use HasFactory;

    protected $table = 'company_consultancy_projects';
    protected $guarded = [];

    // Define the relationship with the User model based on company_id
    public function company()
    {
        return $this->belongsTo(User::class, 'company_id');
    }

    // Define the relationship with the User model based on consultancy_id
    public function consultancy()
    {
        return $this->belongsTo(User::class, 'consultancy_id');
    }

    // Define the relationship with the Projectlist model based on project_id
    public function project()
    {
        return $this->belongsTo(Projectlist::class, 'project_id');
    }
}
