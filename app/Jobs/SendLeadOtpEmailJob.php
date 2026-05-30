<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendLeadOtpEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $emailData;
    protected $email;

    /**
     * Create a new job instance.
     */
    public function __construct(array $emailData, string $email)
    {
        $this->emailData = $emailData;
        $this->email = $email;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        Mail::send('emails.lead-otp', $this->emailData, function ($message) {
            $message->to($this->email)
                    ->subject('Your Mobile OTP Verification Code');
        });
    }
}