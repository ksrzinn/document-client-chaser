<?php

it('defaults the reminder interval to 2 days', function () {
    expect(config('reminders.interval_days'))->toBe(2);
});

it('defaults the max reminder count to 3', function () {
    expect(config('reminders.max_count'))->toBe(3);
});

it('reads the interval from REMINDER_INTERVAL_DAYS', function () {
    config(['reminders.interval_days' => 5]);
    expect(config('reminders.interval_days'))->toBe(5);
});
