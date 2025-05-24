<?php

namespace App\Imports;

use App\Models\ImportKeyword;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Str;

class ImportKeywordsImport implements ToModel, WithHeadingRow
{
    /**
     * Define how each row should be mapped to the model.
     *
     * @param array $row
     * @return \Illuminate\Database\Eloquent\Model|null
     */
        public function model(array $row)
    {
        $keywordName = $row['keyword'];
        $keywordType = $row['keyword_type'];

        return new ImportKeyword([
            'keyword_name' => $keywordName,
            'slug' => Str::slug($keywordName),
            'keyword_type' => $keywordType,
        ]);
    }


}
