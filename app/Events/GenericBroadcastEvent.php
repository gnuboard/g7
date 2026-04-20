<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * G7 범용 브로드캐스트 이벤트 (HookManager 내부 전용)
 *
 * 외부에서 직접 사용하지 않습니다.
 * 반드시 HookManager::broadcast()를 통해 호출하세요.
 */
class GenericBroadcastEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string  $channel  브로드캐스트 채널명
     * @param  string  $eventName  클라이언트 수신 이벤트명
     * @param  array  $payload  브로드캐스트 데이터
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $eventName,
        public readonly array $payload = [],
    ) {}

    /**
     * 브로드캐스트할 채널을 반환합니다.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    /**
     * 브로드캐스트 이벤트명을 반환합니다.
     */
    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    /**
     * 브로드캐스트 데이터를 반환합니다.
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
