<?php

use Illuminate\Support\Facades\Storage;

it('writes and reads a file on the local private disk', function () {
    Storage::disk('local')->put('smoke-test.txt', 'ok');

    expect(Storage::disk('local')->exists('smoke-test.txt'))->toBeTrue();
    expect(Storage::disk('local')->get('smoke-test.txt'))->toBe('ok');

    Storage::disk('local')->delete('smoke-test.txt');
});

it('local disk is not publicly served', function () {
    expect(config('filesystems.disks.local.serve'))->toBeFalse();
});
