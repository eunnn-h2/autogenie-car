<?php
// Copy to alimtalk.local.php. Never put API keys in HTML/JavaScript.
return [
    'enabled'=>false, // Turn on only after templates have been approved.
    'service_id'=>'', // SENS Biz Message service ID, different from SMS service ID.
    'channel_id'=>'', // e.g. @오토지니, as registered in SENS.
    'site_url'=>'', // HTTPS site directory, e.g. https://example.com/autogenie-car
    'receipt_code'=>'', 'assigned_code'=>'',
    // Existing sms.local.php access_key/secret_key are used by default.
    // 'access_key'=>'', 'secret_key'=>'',
    // Override *_content / *_button only to exactly match approved templates.
];
