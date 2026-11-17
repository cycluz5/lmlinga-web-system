<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LMLinga Health Worker Account</title>
</head>
<body>
    <p>Hello {{ $workerName }},</p>
    @if ($isReset)
        <p>An administrator reset the password of your LMLinga health worker account. Your new temporary login details are below.</p>
    @else
        <p>An LMLinga health worker account was created for this email address. Your temporary login details are below.</p>
    @endif
    <p>
        Username: <strong>{{ $loginIdentity }}</strong><br>
        Email: <strong>{{ $loginEmail }}</strong><br>
        Temporary password: <strong>{{ $temporaryPassword }}</strong>
    </p>
    <p>Log in at <a href="{{ $loginUrl }}">{{ $loginUrl }}</a>.</p>
    <p>
        When you log in for the first time, we will email you a 6-digit verification code (valid for {{ $expiryMinutes }} minutes).
        Enter the code and choose a new password. The temporary password stops working once you have set your own.
    </p>
    <p>Do not share this password or the code with anyone, including barangay staff.</p>
    <p>If you were not expecting this email, you may ignore it. The account stays locked until the code is entered.</p>
</body>
</html>
