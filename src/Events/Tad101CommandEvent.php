<?php

declare(strict_types=1);

namespace TrackAnyDevice\Tad101\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Outbound TAD101 command pushed to a single device over Soketi.
 *
 * Broadcast as `tad101-command` on the per-device private channel
 * `private-tad101.device.{imei}`. The device SDK listens on that channel and
 * is expected to respond with a `command_ack` event referencing `cmd_id`.
 */
class Tad101CommandEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $imei,
        public readonly string $cmd,
        public readonly string $cmdId,
        /** @var array<string, mixed> */
        public readonly array $params = [],
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tad101.device.'.$this->imei),
            // Mirror onto a public discovery channel for admin observability —
            // useful when debugging commands without per-device auth tokens.
            new Channel('tad101.commands'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'tad101-command';
    }

    public function broadcastWith(): array
    {
        return [
            'tad' => '101',
            'v' => config('tad101.version', '1.0.0'),
            'cmd' => $this->cmd,
            'cmd_id' => $this->cmdId,
            'params' => (object) $this->params,
            'ts' => now()->timestamp,
        ];
    }
}
