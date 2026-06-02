<?php

declare(strict_types=1);

namespace TrackAnyDevice\Tad101\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Outbound TAD101 command pushed to a single device over Soketi.
 *
 * 200k capacity model: dropped the `tad101.commands` public discovery
 * firehose. Cross-tenant debugging now goes through the sampled
 * `private-admin.device-logs` channel which fires via DeviceLog::out()
 * when the command is dispatched.
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

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tad101.device.'.$this->imei),
        ];
    }

    public function broadcastAs(): string
    {
        return 'tad101-command';
    }

    /**
     * @return array<string, mixed>
     */
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
