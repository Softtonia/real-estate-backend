<?php

namespace App\Exports;

use App\Models\SubscribedEmail;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\Exportable;

class SubscribedEmailsExport implements FromCollection, WithHeadings
{
    use Exportable;

    protected $filters;

    // Inject filters into the constructor
    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $query = SubscribedEmail::select('subscribe_email', 'is_subscribed', 'custom_tag', 'user_id', 'created_at', 'updated_at');

        // Apply filters if provided
        if (!empty($this->filters['start_date'])) {
            $query->where('created_at', '>=', $this->filters['start_date']);
        }

        if (!empty($this->filters['end_date'])) {
            $query->where('created_at', '<=', $this->filters['end_date']);
        }

        if (!empty($this->filters['tag'])) {
            $query->where('custom_tag', $this->filters['tag']);
        }

        if (isset($this->filters['is_subscribed'])) {
            $query->where('is_subscribed', $this->filters['is_subscribed']);
        }

        // Get the filtered collection
        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Email',
            'Is Subscribed',
            'Custom Tag',
            'User ID',
            'Created At',
            'Updated At',
        ];
    }
}
