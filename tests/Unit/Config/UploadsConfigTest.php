<?php

it('defaults the configurable max upload size to 10 MB in kilobytes', function () {
    expect(config('uploads.max_size_kb'))->toBe(10240);
});

it('reads the max upload size from the UPLOAD_MAX_SIZE_KB env var', function () {
    config(['uploads.max_size_kb' => (int) '2048']);

    expect(config('uploads.max_size_kb'))->toBe(2048);
});
