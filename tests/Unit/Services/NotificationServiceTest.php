<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Notifications\GenericNotification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * NotificationService 테스트
 *
 * 사이트내 알림 조회, 읽음 처리, 삭제, 정리 동작을 검증합니다.
 */
class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NotificationService::class);
    }

    /**
     * 사용자의 알림 목록 조회
     */
    public function test_get_notifications_returns_paginated_list(): void
    {
        $user = User::factory()->create();

        // database 채널로 알림 3개 발송
        Notification::fake();
        // fake 대신 실제 database 채널로 발송하여 DB에 저장
        Notification::swap(new \Illuminate\Support\Testing\Fakes\NotificationFake());

        // 직접 notifications 테이블에 레코드 생성
        for ($i = 0; $i < 3; $i++) {
            $user->notifications()->create([
                'id' => \Illuminate\Support\Str::uuid(),
                'type' => GenericNotification::class,
                'data' => ['type' => 'test', 'subject' => "Test {$i}"],
            ]);
        }

        $result = $this->service->getNotifications($user, [], 20);

        $this->assertEquals(3, $result->total());
    }

    /**
     * 미읽음 알림 수 조회
     */
    public function test_get_unread_count(): void
    {
        $user = User::factory()->create();

        $user->notifications()->create([
            'id' => \Illuminate\Support\Str::uuid(),
            'type' => GenericNotification::class,
            'data' => ['type' => 'test'],
        ]);

        $user->notifications()->create([
            'id' => \Illuminate\Support\Str::uuid(),
            'type' => GenericNotification::class,
            'data' => ['type' => 'test'],
            'read_at' => now(),
        ]);

        $count = $this->service->getUnreadCount($user);

        $this->assertEquals(1, $count);
    }

    /**
     * 알림 읽음 처리
     */
    public function test_mark_as_read(): void
    {
        $user = User::factory()->create();

        $notification = $user->notifications()->create([
            'id' => \Illuminate\Support\Str::uuid(),
            'type' => GenericNotification::class,
            'data' => ['type' => 'test'],
        ]);

        $result = $this->service->markAsRead($user, $notification->id);

        $this->assertNotNull($result);
        $this->assertNotNull($result->read_at);
    }

    /**
     * 전체 읽음 처리
     */
    public function test_mark_all_as_read(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $user->notifications()->create([
                'id' => \Illuminate\Support\Str::uuid(),
                'type' => GenericNotification::class,
                'data' => ['type' => 'test'],
            ]);
        }

        $count = $this->service->markAllAsRead($user);

        $this->assertEquals(3, $count);
        $this->assertEquals(0, $this->service->getUnreadCount($user));
    }

    /**
     * 알림 삭제
     */
    public function test_delete_notification(): void
    {
        $user = User::factory()->create();

        $notification = $user->notifications()->create([
            'id' => \Illuminate\Support\Str::uuid(),
            'type' => GenericNotification::class,
            'data' => ['type' => 'test'],
        ]);

        $result = $this->service->deleteNotification($user, $notification->id);

        $this->assertTrue($result);
        $this->assertEquals(0, $user->notifications()->count());
    }

    /**
     * 존재하지 않는 알림 삭제 시 false 반환
     */
    public function test_delete_nonexistent_notification_returns_false(): void
    {
        $user = User::factory()->create();

        $result = $this->service->deleteNotification($user, 'nonexistent-uuid');

        $this->assertFalse($result);
    }
}
