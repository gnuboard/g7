<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Notification;

use App\Extension\HookManager;
use App\Services\NotificationLogService;
use Plugins\Sirsoft\MessageBizppurio\Listeners\LinkNotificationLogListener;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Services\DispatchLinkContext;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 코어 알림 로그 ↔ 비즈뿌리오 dispatch 연결 통합 테스트 (A-2 연결고리).
 *
 * Hook 도메인 규정(실제 훅 체인 관찰, mock 금지)에 따라, 코어 NotificationLogService 를 실제로
 * 호출해 core.notification_log.after_log_sent / after_log_failed 훅이 발화하고, 우리
 * LinkNotificationLogListener 가 발송 사이클 refkey 로 dispatch 에 코어 로그 id 를 연결하는지
 * 관찰한다(관찰 가능한 상태 변화 = dispatch.notification_log_id).
 *
 * 매칭은 복합키가 아니라 DispatchLinkContext 의 발송 사이클 refkey 직접 표식(A안)이다.
 */
class NotificationLogLinkTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 테스트 환경에는 플러그인이 활성 등록되지 않으므로, A-2 연결 리스너를 HookManager 에
        // 명시 등록해 실제 훅 체인(after_log_sent/after_log_failed)이 이 리스너로 흐르게 한다.
        // getSubscribedHooks() 를 그대로 반영해 실제 발화 경로를 검증한다(리스너 직접 호출 아님).
        foreach (LinkNotificationLogListener::getSubscribedHooks() as $hook => $config) {
            HookManager::addAction(
                $hook,
                fn (...$args) => app(LinkNotificationLogListener::class)->{$config['method']}(...$args),
                $config['priority'] ?? 10,
            );
        }
    }

    /**
     * 발송 사이클을 흉내내 pending dispatch 를 만들고 refkey 를 컨텍스트에 남깁니다.
     *
     * 채널 드라이버 send() 의 dispatch 생성 + linkContext->remember() 와 동일한 상태를 만든다.
     *
     * @param  string  $channel  발송 채널
     * @return string 부여한 refkey
     */
    private function beginDispatchCycle(string $channel = 'sms'): string
    {
        $refkey = 'rk_'.uniqid();

        BizppurioDispatch::create([
            'refkey' => $refkey,
            'channel' => $channel,
            'to_number' => '01011112222',
            'content' => '본문',
            'notification_type' => 'welcome',
            'status' => 'pending',
            'source' => 'auto',
            'sent_at' => now(),
        ]);

        // 발송 사이클과 동일한 싱글턴 인스턴스에 refkey 를 남긴다.
        app(DispatchLinkContext::class)->remember($refkey);

        return $refkey;
    }

    public function test_발송_성공_로그가_dispatch에_연결된다(): void
    {
        $refkey = $this->beginDispatchCycle();

        // 코어 로그 서비스로 실제 로그 생성 → after_log_sent 훅 발화 → 우리 리스너가 연결
        $log = app(NotificationLogService::class)->logSent([
            'channel' => 'sms',
            'notification_type' => 'welcome',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'recipient_identifier' => '01011112222',
            'recipient_name' => '홍길동',
            'source' => 'notification',
            'sent_at' => now(),
        ]);

        $dispatch = BizppurioDispatch::query()->where('refkey', $refkey)->first();
        $this->assertNotNull($dispatch);
        $this->assertSame((int) $log->id, (int) $dispatch->notification_log_id);
    }

    public function test_발송_실패_로그도_dispatch에_연결된다(): void
    {
        $refkey = $this->beginDispatchCycle('alimtalk');

        $log = app(NotificationLogService::class)->logFailed([
            'channel' => 'alimtalk',
            'notification_type' => 'welcome',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'recipient_identifier' => '01011112222',
            'error_message' => 'boom',
            'source' => 'notification',
            'sent_at' => now(),
        ]);

        $dispatch = BizppurioDispatch::query()->where('refkey', $refkey)->first();
        $this->assertSame((int) $log->id, (int) $dispatch->notification_log_id);
    }

    public function test_비_비즈뿌리오_로그는_연결되지_않는다(): void
    {
        // remember() 를 부르지 않은 상태(mail/database 등 비-비즈뿌리오 발송 사이클)
        $log = app(NotificationLogService::class)->logSent([
            'channel' => 'mail',
            'notification_type' => 'welcome',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'recipient_identifier' => 'a@b.com',
            'source' => 'notification',
            'sent_at' => now(),
        ]);

        // 어떤 dispatch 도 이 로그에 연결되지 않아야 한다.
        $linked = BizppurioDispatch::query()->where('notification_log_id', $log->id)->exists();
        $this->assertFalse($linked);
    }

    public function test_한_refkey는_한_로그에만_연결된다_consume_후_비워짐(): void
    {
        $refkey = $this->beginDispatchCycle();

        // 첫 로그 → 연결됨
        $first = app(NotificationLogService::class)->logSent([
            'channel' => 'sms',
            'notification_type' => 'welcome',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'recipient_identifier' => '01011112222',
            'source' => 'notification',
            'sent_at' => now(),
        ]);

        // 같은 사이클에서 refkey 를 다시 남기지 않은 채 둘째 로그 발생(다른 채널) → 연결 안 됨
        $second = app(NotificationLogService::class)->logSent([
            'channel' => 'database',
            'notification_type' => 'welcome',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'recipient_identifier' => '01011112222',
            'source' => 'notification',
            'sent_at' => now(),
        ]);

        $dispatch = BizppurioDispatch::query()->where('refkey', $refkey)->first();
        $this->assertSame((int) $first->id, (int) $dispatch->notification_log_id);
        $this->assertFalse(
            BizppurioDispatch::query()->where('notification_log_id', $second->id)->exists()
        );
    }
}
