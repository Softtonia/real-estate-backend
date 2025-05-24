<!-- resources/views/emails/verify_email.blade.php -->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification</title>
</head>
<body>
    <p>Hello {{ $user->fullname }},</p>
    <p>Please click on the following link to verify your email address:</p>
    <a href="{{ $verificationUrl }}">{{ $verificationUrl }}</a>
    <p>This link will expire in 10 minutes.</p>
</body>
</html>
