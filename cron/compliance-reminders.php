<?php
/**
 * Daily for_compliance follow-up.
 * Run once a day from Windows Task Scheduler or cron:
 *   php cron/compliance-reminders.php
 *
 * Does not change application status.
 */

if (php_sapi_name() !== 'cli') {
    if (!headers_sent()) {
        http_response_code(403);
    }
    echo "This script can only be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/app-helpers.php';

$counts = runComplianceFollowUp($database);

echo "Compliance follow-up\n";
foreach ($counts as $name => $value) {
    echo $name . ': ' . (int) $value . PHP_EOL;
}
