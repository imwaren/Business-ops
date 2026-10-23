<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bethany Christian School</title>
    <!-- import cdn for bootstrap -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css" integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm" crossorigin="anonymous">
</head>
<body>
    <div style="width: 100%; background-color: #f5f5f5; padding: 20px; box-sizing: border-box;">
        <h2>Welcome to Bethany Christian School!</h2>
        <p>Dear <?= esc($user->first_name . ' ' . $user->last_name, 'html') ?>,</p>
        <p>We are thrilled to welcome you and your child to our school community. Your registration is almost complete! For security purposes, we ask that you log in and change this password as soon as possible.</p>

        <p><strong>Username:</strong> <?= esc($user->username, 'html') ?></p>
        <p><strong>Temporary Password:</strong> <?= esc($temporary_password, 'html') ?></p>

        <p>If you encounter any issues or have any questions, feel free to reach us at <a href="mailto:inquiries@bethanychristianschool.edu.ph">inquiries@bethanychristianschool.edu.ph</a>.</p>

        <p>Best regards,<br>
            Bethany Christian School</p>
    </div>
</body>
</html>

