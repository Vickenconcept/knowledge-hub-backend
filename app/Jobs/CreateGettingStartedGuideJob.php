<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\OnboardingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateGettingStartedGuideJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $organizationId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $organizationId)
    {
        $this->organizationId = $organizationId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Creating getting started guide in background', [
            'org_id' => $this->organizationId,
        ]);

        $organization = Organization::find($this->organizationId);

        if (!$organization) {
            Log::error('Organization not found for getting started guide', [
                'org_id' => $this->organizationId,
            ]);
            return;
        }

        // Create the guide
        OnboardingService::createGettingStartedGuide($organization);

        Log::info('Getting started guide created successfully in background', [
            'org_id' => $this->organizationId,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to create getting started guide in background', [
            'org_id' => $this->organizationId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

