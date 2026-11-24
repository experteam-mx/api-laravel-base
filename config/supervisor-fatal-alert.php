<?php

return [
    'recipient' => env('SUPERVISOR_FATAL_ALERT_EMAIL') ?: 'crasupport@experteam.com.ec',
    'namespace' => env('MY_POD_NAMESPACE') ?: 'local',
    'services_url' => env('DHL_API_SERVICES_URL'),
    'app_key' => env('AUTH_MASTER_APP_KEY'),
];
