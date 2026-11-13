<?php

namespace Plugins\Sirsoft\Sso\Listeners\Provisioning;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\Sso\Services\AttributeSyncService;

/**
 * IdP 가 관리하는 회원 칸을 회원 수정 화면과 API 에서 잠급니다 (필터 `core.user.locked_fields`).
 *
 * 잠금 대상은 회원의 활성 연결 IdP 들이 「매 로그인 덮어쓰기 + 수정 잠금」(`sync_lock`)으로 둔 코어 칸의 합집합이다. 마이페이지와
 * 관리자 수정 표면이 같다. 플러그인 동기화는 요청을 거치지 않으므로 잠금에 걸리지 않는다.
 *
 * @since 1.0.0
 */
class LockedFieldsListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.locked_fields' => ['method' => 'lockedFields', 'priority' => 20, 'type' => 'filter', 'sync' => true],
            // 잠근 사유 — 화면이 「회사 계정에서 관리하는 항목입니다」 안내를 고르는 근거(코어 7.2.0)
            'core.user.locked_field_reasons' => ['method' => 'lockedFieldReasons', 'priority' => 20, 'type' => 'filter', 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 잠글 칸을 더합니다.
     *
     * @param  mixed  $fields  앞선 필터 결과
     * @param  mixed  ...$args  [User, string $surface]
     * @return mixed 잠글 칸 이름
     */
    public function lockedFields(mixed $fields, mixed ...$args): mixed
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User || (int) $user->getKey() <= 0) {
            return $fields;
        }

        $fields = is_array($fields) ? $fields : [];

        return array_values(array_unique(array_merge($fields, app(AttributeSyncService::class)->lockedFields($user))));
    }

    /**
     * 이 플러그인이 잠근 칸의 사유(`sso`)를 더합니다.
     *
     * 앞선 구독자가 같은 칸에 사유를 이미 줬으면 그대로 둔다.
     *
     * @param  mixed  $reasons  앞선 필터 결과 (칸 이름 => 사유)
     * @param  mixed  ...$args  [User, string $surface]
     * @return mixed 칸 이름 => 사유
     */
    public function lockedFieldReasons(mixed $reasons, mixed ...$args): mixed
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User || (int) $user->getKey() <= 0) {
            return $reasons;
        }

        $reasons = is_array($reasons) ? $reasons : [];
        foreach (app(AttributeSyncService::class)->lockedFields($user) as $field) {
            $reasons[$field] ??= 'sso';
        }

        return $reasons;
    }
}
