<?php
// Copy to config/sms.local.php, then enter your Naver Cloud SENS credentials.
// Never upload sms.local.php to GitHub.
return [
    'service_id' => '', // SENS SMS project service ID
    'access_key' => '', // NAVER Cloud API access key
    'secret_key' => '', // NAVER Cloud API secret key
    'sender' => '', // registered SMS sender number, digits only
    'local_debug' => false, // true: use localhost-only test code instead of sending SMS
];
