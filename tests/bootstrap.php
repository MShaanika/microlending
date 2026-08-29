<?php

/**
 * PHPUnit bootstrap -- reuses the app's real bootstrap/app.php (the same
 * one every bin/*.php cron script already requires in CLI context), so
 * tests run against the same autoloader, config, and local dev database
 * as the app itself. This codebase has no separate test/environment
 * database; tests that touch the DB run against local XAMPP dev data.
 */

require __DIR__ . '/../bootstrap/app.php';
