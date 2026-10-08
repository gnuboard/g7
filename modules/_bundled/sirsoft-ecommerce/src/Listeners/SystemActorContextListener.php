<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Support\SystemActor;

/**
 * 큐로 간 자동 전이 훅의 작업자를 시스템으로 복원하는 리스너 (S1-D16 후속)
 *
 * `SystemActor::run()` 안에서 발화한 훅이 큐로 가면 코어 `HookContextCapture` 가 `user_id` null 을
 * 싣는다. 그런데 코어 `restore()` 는 `user_id` 가 비어 있으면 인증 사용자를 건드리지 않아, 오래 사는
 * 워커에서는 앞서 처리한 작업의 사용자가 그대로 작업자로 기록됐다(2026-10-07 재실측 — 자동 진행
 * 취소의 「취소신청서 상태 변경」 줄만 고객). 캡처 때 시스템 범위 표지를 싣고, 복원 때 그 표지가
 * 있으면 작업자를 비운다. 표지가 없는 작업은 건드리지 않는다.
 */
class SystemActorContextListener implements HookListenerInterface
{
    /**
     * 컨텍스트에 싣는 시스템 범위 표지 키.
     */
    public const MARKER = 'sirsoft_ecommerce_system_actor';

    /**
     * 구독할 훅 목록 반환
     *
     * 복원은 작업 처리 직전에 그 자리에서 일어나야 하므로 동기다.
     *
     * @return array<string, array<string, mixed>> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'hook.context.capture' => ['method' => 'markSystemScope', 'type' => 'filter', 'priority' => 20],
            'hook.context.restore' => ['method' => 'restoreSystemScope', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 시스템 범위에서 캡처한 컨텍스트에 표지를 싣습니다. (filter 훅)
     *
     * @param  array<string, mixed>  $context  캡처된 컨텍스트
     * @return array<string, mixed> 표지가 실린 컨텍스트
     */
    public function markSystemScope(array $context): array
    {
        if (SystemActor::active()) {
            $context[self::MARKER] = true;
            $context['user_id'] = null;
        }

        return $context;
    }

    /**
     * 표지가 실린 작업을 복원할 때 작업자를 비웁니다. (action 훅)
     *
     * @param  array<string, mixed>  $context  복원할 컨텍스트
     * @return void 반환값 없음
     */
    public function restoreSystemScope(array $context): void
    {
        if (! empty($context[self::MARKER])) {
            SystemActor::assumeForJob();
        }
    }

    /**
     * 기본 핸들러 (method 매핑 사용 — 직접 호출되지 않음)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // method 매핑(markSystemScope/restoreSystemScope)을 사용
    }
}
