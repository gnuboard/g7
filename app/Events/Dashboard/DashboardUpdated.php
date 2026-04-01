<?php

namespace App\Events\Dashboard;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 대시보드 업데이트 브로드캐스트 이벤트
 *
 * 대시보드의 특정 섹션이 업데이트될 때 클라이언트에 실시간으로 전달합니다.
 */
class DashboardUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * 이벤트 인스턴스를 생성합니다.
     *
     * @param  string  $type  업데이트 타입 ('stats', 'resources', 'activities', 'modules', 'alerts')
     * @param  array  $data  업데이트된 데이터
     */
    public function __construct(
        public string $type,
        public array $data
    ) {}

    /**
     * 브로드캐스트할 채널을 반환합니다.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.dashboard')];
    }

    /**
     * 브로드캐스트 이벤트명을 반환합니다.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return "dashboard.{$this->type}.updated";
    }
}
