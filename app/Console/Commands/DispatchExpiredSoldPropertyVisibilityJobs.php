<?php

namespace App\Console\Commands;

use App\Enums\PropertyAvailabilityStatus;
use App\Jobs\ExpireSoldPropertyVisibilityJob;
use App\Models\DynamicPost;
use Illuminate\Console\Command;

class DispatchExpiredSoldPropertyVisibilityJobs extends Command
{
    protected $signature =
        'property-availability:dispatch-expired-sold';

    protected $description =
        'Dispatch jobs for sold properties whose public visibility has expired.';

    public function handle(): int
    {
        $batchSize = (int) config(
            'property_availability.expiry_batch_size',
            200
        );

        $dispatched = 0;

        DynamicPost::query()
            ->select('id')
            ->where(
                'availability_status',
                PropertyAvailabilityStatus::SOLD
            )
            ->whereNotNull(
                'availability_public_until'
            )
            ->where(
                'availability_public_until',
                '<=',
                now()
            )
            ->whereNull(
                'availability_hidden_at'
            )
            ->chunkById(
                $batchSize,
                function ($properties) use (
                    &$dispatched
                ) {
                    foreach ($properties as $property) {
                        ExpireSoldPropertyVisibilityJob::
                            dispatch(
                                (int) $property->id
                            );

                        $dispatched++;
                    }
                }
            );

        $this->info(
            $dispatched .
            ' sold visibility expiry job(s) dispatched.'
        );

        return self::SUCCESS;
    }
}
