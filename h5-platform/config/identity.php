<?php

$recipients = env('H5_STAGING_TEST_RECIPIENTS', '');

return [
    'staging_test_recipients' => is_string($recipients) && $recipients !== '' ? explode(',', $recipients) : [],
];
