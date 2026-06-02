<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use TrackAnyDevice\Tad101\Events\Tad101CommandEvent;

it('broadcasts only on the per-device private channel', function () {
    $event = new Tad101CommandEvent('860000000000001', 'set_mode', 'cmd-1');
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-tad101.device.860000000000001');
});

it('keeps the broadcast payload schema unchanged', function () {
    $event = new Tad101CommandEvent('860000000000001', 'set_mode', 'cmd-1', ['mode' => 'realtime']);
    expect($event->broadcastWith())->toMatchArray([
        'tad' => '101',
        'cmd' => 'set_mode',
        'cmd_id' => 'cmd-1',
    ]);
});
