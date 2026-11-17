<?php

/**
 * Adds a "Loan Questions" row for every online application that does not have one yet (the page does this by itself whenever it is opened; this is for a
 * daily cron so the questions are ready in the morning). Reads applications and writes only the loan_followups table.
 *
 *   php bin/loan_followups_sync.php
 */

require __DIR__ . '/../bootstrap/app.php';

$added = (new App\Models\LoanFollowup())->syncApplications(); // the same rule as the page; a test application is closed with the page's "not a real loan" button
echo $added . " new application(s) added to the Loan Questions.\n";
