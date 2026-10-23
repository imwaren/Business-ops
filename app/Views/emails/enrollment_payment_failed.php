<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrollment Payment Failed – Action Required</title>
    <!-- import cdn for bootstrap -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css" integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm" crossorigin="anonymous">
</head>
<body>
    <!-- Letter contents -->
    <div style="width: 100%; background-color: #f5f5f5; padding: 20px; box-sizing: border-box;">
        <p>We hope this email finds you well. We regret to inform you that your recent enrollment payment for school year <?= esc($sy->year_from.'-'.$sy->year_to, 'html') ?> on <?= esc(date("F j, Y", strtotime($payment->payment_date)), 'html') ?> was unsuccessful.</p>
        <p><strong>Transaction Details:</strong><br>
        <ul>
            <li><strong>Student Name:</strong> <?= esc($student->first_name.' '.$student->last_name, 'html') ?></li>
            <li><strong>Payment Amount:</strong> <?= esc($payment->paid_amount, 'html') ?></li>
            <li><strong>Mode of Payment:</strong> <?= esc(ucwords(str_replace('_', ' ', $payment->mop)), 'html') ?></li>
            <li><strong>Reasons:</strong> <?= esc($payment->remarks, 'html') ?></li>
        </ul>
        <p>For any questions or assistance, feel free to contact us at <a href="mailto:admissions@bethanychristianschool.edu.ph">admissions@bethanychristianschool.edu.ph</a>.</p>
        <p>Best regards,<br>
            Bethany Christian School
        </p>
        <!-- <img src="https://copernicus-api.myt-tests.com/assets/experts/inn_eu.png" alt="Expert Image" style="max-width: 30%; height: auto;"> -->
    </div>
</body>
</html>