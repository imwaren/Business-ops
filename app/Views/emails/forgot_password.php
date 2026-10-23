<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Request</title>
</head>
<body>
    <div style="width: 100%; background-color: #f5f5f5; padding: 20px; box-sizing: border-box;">
        <h2>Password Reset Request</h2>
        <p>Dear <?= esc($user->username ?? 'User') ?>,</p>
        <p>We received a request to reset your password.</p>
        <p>Use the reset button below to proceed with the request. This link expires in 1 hour.</p>
        <p>
            <a href="<?= esc($reset_link) ?>" style="display: inline-block; padding: 10px 20px; font-size: 16px; color: #ffffff; background-color: #007bff; text-decoration: none; border-radius: 5px;">Reset Password</a>
        </p>
        <p>If the button does not work, copy and paste this URL into your browser:<br><?= esc($reset_link) ?></p>
        <p>If you did not request this change, please ignore this email.</p>
        <p>Best regards,<br>
            CLINICA PRIME - BUSINESS OPERATIONS</p>
    </div>
</body>
</html>
