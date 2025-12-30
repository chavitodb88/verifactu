<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API VERI*FACTU</title>
</head>

<body>
    <h1>Welcome to API VERI*FACTU</h1>
    <?php if (isset($test) && $test): ?>
        <p>The API is currently running in <strong>TEST</strong> mode.</p>
    <?php else: ?>
        <p>The API is currently running in <strong>PRODUCTION</strong> mode.</p>
    <?php endif; ?>

    <?php if (isset($sendReal) && $sendReal): ?>
        <p>Invoices will be sent to the real VERI*FACTU service.</p>
    <?php else: ?>
        <p>The invoices will not be sent to VERI*FACTU.</p>
    <?php endif; ?>


    <p>This is the welcome message for the VERI*FACTU API.</p>

</body>

</html>