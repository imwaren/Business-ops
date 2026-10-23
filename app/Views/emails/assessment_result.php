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
    <!-- Determine pronouns -->
    <?php 
        $pronoun_he_she = ($student->gender == 'male') ? 'He' : 'She';
        $pronoun_him_her = ($student->gender == 'male') ? 'him' : 'her';
        $pronoun_his_her = ($student->gender == 'male') ? 'his' : 'her';
        function formatGradeLevel($gradeLevel) {
            // Replace underscores with spaces
            $formatted = str_replace('_', ' ', $gradeLevel);
            
            // Capitalize each word
            return ucwords($formatted);
        }        
    ?>
    <!-- Letter contents -->
    <div style="width: 100%; background-color: #f5f5f5; padding: 20px; box-sizing: border-box;">
        <p>Dear Parent, </p>
        <p>This is to inform you that your child, <b><?= esc($student->first_name.' '.$student->last_name, 'html') ?></b>, <span style="color: <?= $assessment->remarks == 'passed' ? 'green' : 'red' ?>; font-weight: bold;"><?= esc(strtoupper((string) $assessment->remarks), 'html') ?></span> <b>the Entrance Assessment</b> held on <?= esc(date("F j, Y", strtotime($assessment->exam_taken ?? 'now')), 'html') ?>.</p>
        
        <!-- Conditional Remarks -->
        <?php if ($assessment->remarks == 'passed'): ?>
            <p><?= esc($pronoun_he_she, 'html') ?> can now enroll for <b><?= esc(formatGradeLevel($admission->english_grade_level), 'html') ?></b>.</p>
        <?php elseif ($assessment->remarks == 'failed'): ?>
            <p>If you would like to request for a reconsideration of the application, please submit your request through email at <a href="mailto:admissions@bethanychristianschool.edu.ph">admissions@bethanychristianschool.edu.ph</a>.</p>
        <?php endif; ?>

        <p>For any inquiries or concerns about the results, kindly reach us through landline or mobile numbers 032 262-0140, 262-9210, 09158272740. </p>
        <p>We acknowledge your child's effort in this endeavor and wish <?= esc($pronoun_him_her, 'html') ?> continued success in <?= esc($pronoun_his_her, 'html') ?> academic journey.</p>
        <p>Best regards,<br>
            Bethany Christian School
        </p>
    </div>
</body>
</html>