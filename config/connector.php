<?php

return [
    'protocol_version' => '1.0',
    'online_ttl_seconds' => (int) env('CONNECTOR_ONLINE_TTL_SECONDS', 60),
    'demo_internal_token' => env('DEMO_INTERNAL_TOKEN'),
    'demo_command_timeout_seconds' => (int) env('DEMO_COMMAND_TIMEOUT_SECONDS', 10),
    'demo_poll_interval_ms' => (int) env('DEMO_POLL_INTERVAL_MS', 200),
    'supported_ops' => [
        'drill_holes.list@1',
        'drill_holes.get@1',
        'progress.create@1',
        'progress.list@1',
    ],
];
