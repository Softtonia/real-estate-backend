<?php

namespace App\Exports;

use App\Models\ImportKeyword;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ImportKeywordsExport implements FromCollection, WithHeadings
{
    /**
     * Return a collection of all keywords.
     *
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return ImportKeyword::all();
    }

    /**
     * Define the headings for the export.
     *
     * @return array
     */
    public function headings(): array
    {
        return [
            'id',
            'keyword_name',
        ];
    }
}
