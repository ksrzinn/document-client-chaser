<?php

return [
    'interval_days' => (int) env('REMINDER_INTERVAL_DAYS', 2),
    'max_count' => (int) env('REMINDER_MAX_COUNT', 3),
];
