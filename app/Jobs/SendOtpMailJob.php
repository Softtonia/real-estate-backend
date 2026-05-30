<?php

namespace App\Jobs;

use App\Mail\OTPMail;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOtpMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $otp;
    public $email;
    public $fullName;

    public function __construct($otp, $email, $fullName)
    {
        $this->otp = $otp;
        $this->email = $email;
        $this->fullName = $fullName;
    }

    public function handle()
    {
        $settings = DB::table('mail_configs')
            ->where('status', 1)
            ->first();

        if ($settings) {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => $settings->host,
                'mail.mailers.smtp.port' => $settings->port,
                'mail.mailers.smtp.username' => $settings->username,
                'mail.mailers.smtp.password' => $settings->password,
                'mail.mailers.smtp.encryption' => $settings->encryption,
                'mail.from.address' => $settings->from_address,
                'mail.from.name' => $settings->from_name,
            ]);

            app('mail.manager')->forgetMailers();
        }

        Mail::to($this->email)->send(
            new OTPMail($this->otp, $this->email, $this->fullName)
        );
    }
}