<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioDispatchRepositoryInterface;
use Plugins\Sirsoft\MessageBizppurio\Services\DispatchLinkContext;

/**
 * 코어 알림 로그 ↔ 비즈뿌리오 발송건 연결 리스너 (A-2 연결고리).
 *
 * 코어 알림 발송 이력 화면에서 비즈뿌리오 결과(성공/실패·사유·잔액부족·대체발송)를 그 행에
 * 붙이려면, 코어 로그(notification_logs) 와 우리 dispatch(bizppurio_dispatches) 를 이어야 한다.
 * 코어 로그 테이블 무수정 원칙(계획서 ⚑ 결정 1)에 따라, 연결 표식은 우리 dispatch 쪽에만 둔다
 * (bizppurio_dispatches.notification_log_id).
 *
 * 코어가 로그 생성 직후 발화하는 확장점을 구독한다:
 *   - core.notification_log.after_log_sent   — 발송 성공 로그 생성 직후(NotificationLog 전달)
 *   - core.notification_log.after_log_failed — 발송 실패 로그 생성 직후(NotificationLog 전달)
 *
 * 매칭 방식(A안, PO 확정): 복합키 근사 매칭이 아니라 발송 사이클 refkey 직접 표식이다.
 * 같은 채널 발송 한 사이클에서 우리 드라이버 send() 가 dispatch(refkey) 를 먼저 만들고
 * DispatchLinkContext 에 refkey 를 남긴 뒤, 곧바로 이 로그 훅이 발화한다. 여기서 그 refkey 를
 * 꺼내(consume) 그 dispatch 에 방금 만들어진 코어 로그 id 를 기록한다.
 *
 * 비-비즈뿌리오 채널(mail/database 등)의 로그가 발화한 경우엔 이 사이클에서 remember 된 refkey 가
 * 없으므로 consume() 이 null 을 반환하고 조용히 넘어간다(그 로그는 연결 대상 아님).
 */
class LinkNotificationLogListener implements HookListenerInterface
{
    /**
     * @param  BizppurioDispatchRepositoryInterface  $dispatches  dispatch 연결 위임
     * @param  DispatchLinkContext  $linkContext  발송 사이클 refkey 컨텍스트
     */
    public function __construct(
        private readonly BizppurioDispatchRepositoryInterface $dispatches,
        private readonly DispatchLinkContext $linkContext,
    ) {}

    /**
     * 구독할 훅 목록 반환.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.notification_log.after_log_sent' => ['method' => 'linkLog', 'priority' => 15],
            'core.notification_log.after_log_failed' => ['method' => 'linkLog', 'priority' => 15],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — linkLog 로 처리).
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void {}

    /**
     * 방금 생성된 코어 알림 로그를 이번 발송 사이클의 dispatch 에 연결합니다.
     *
     * 이 사이클에서 우리 드라이버가 남긴 refkey 가 있으면 그 dispatch 에 로그 id 를 기록한다.
     * refkey 가 없으면(비-비즈뿌리오 로그) 연결하지 않는다.
     *
     * @param  NotificationLog  $log  코어가 방금 생성한 로그(id 포함)
     */
    public function linkLog(NotificationLog $log): void
    {
        $refkey = $this->linkContext->consume();

        if ($refkey === null) {
            return;
        }

        try {
            $this->dispatches->linkNotificationLog($refkey, (int) $log->id);
        } catch (\Throwable $e) {
            Log::warning('비즈뿌리오: 코어 알림 로그 연결 실패', [
                'refkey' => $refkey,
                'notification_log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
