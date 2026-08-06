<?php

namespace App\Jobs;

use App\Services\PropertyAvailability\PropertyAvailabilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExpireSoldPropertyVisibilityJob implements
    ShouldQueue,
    ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $propertyId
    ) {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        return (string) $this->propertyId;
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        PropertyAvailabilityService $service
    ): void {
        $service->expireSoldVisibility(
            $this->propertyId
        );
    }

    public function failed(
        Throwable $exception
    ): void {
        report($exception);
    }
}
