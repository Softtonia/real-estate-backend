<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
</head>
<body>
    <h2>Reset Password</h2>
    <form action="{{url('reset-password')}}" method="post">
        @csrf
        <input type="hidden" name="token" value="{{ $resetData->token }}">
        <label for="password">New Password:</label><br>
        <input type="password" id="password" name="password"><br>
        <label for="password_confirmation">Confirm Password:</label><br>
        <input type="password" id="password_confirmation" name="password_confirmation"><br><br>
        <button type="submit">Reset Password</button>
    </form>
</body>
</html>

