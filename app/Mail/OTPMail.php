<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class OTPMail extends Mailable
{
    public $otp;
    public $email;
    public $fullName;

    /**
     * Create a new message instance.
     *
     * @param  string  $otp
     * @param  string  $email
     * @param  string  $fullName
     * @return void
     */
    public function __construct($otp, $email)
    {
        $this->otp = $otp;
        $this->email = $email;

        // Optional: Fetch user's full name (if needed)
        $user = \App\Models\User::where('email', $email)->first();
        $this->fullName = $user ? $user->first_name . ' ' . $user->last_name : '';   // Default to empty string if user not found
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->view('emails.otp')  // Use the correct view for the OTP email
                    ->subject('Your OTP Code')
                    ->with([
                        'otp' => $this->otp,
                        'email' => $this->email,
                        'fullName' => $this->fullName,  // Pass full name to the view
                    ]);
    }
}
