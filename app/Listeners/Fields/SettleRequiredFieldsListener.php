<?php

namespace App\Listeners\Fields;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use App\Services\RequiredFieldsChecker;

/**
 * 회원 값이 저장된 뒤 비어 있는 필수 항목 판정을 다시 맞춘다.
 *
 * 회원 정보 수정(본인, 관리자, 확장의 서비스 호출)과 파일 항목 올리기, 지우기 뒤에 판정 캐시를 버리고,
 * 필수 항목을 건너뛰고 만들어진 회원이 미입력을 모두 채웠으면 그 표시(`required_fields_deferred_at`)를 지운다.
 * 다음 요청이 낡은 판정을 읽지 않도록 동기로 돈다.
 *
 * @since 7.2.0
 */
class SettleRequiredFieldsListener implements HookListenerInterface
{
    /**
     * @param  RequiredFieldsChecker  $checker  비어 있는 필수 항목 판정
     */
    public function __construct(
        private readonly RequiredFieldsChecker $checker,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        $subscription = ['method' => 'settle', 'priority' => 90, 'sync' => true];

        return [
            'core.user.after_update' => $subscription,
            'core.user.fields.after_update' => $subscription,
            'core.user.field_file.after_upload' => $subscription,
            'core.user.field_file.after_delete' => $subscription,
        ];
    }

    /**
     * 기본 핸들러 — 훅마다 `settle` 을 쓰므로 호출되지 않습니다.
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 훅별 메서드(settle)를 쓴다
    }

    /**
     * 값이 저장된 회원의 판정을 다시 맞춥니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 인자가 값이 저장된 회원)
     * @return void 반환값 없음
     */
    public function settle(...$args): void
    {
        $user = $args[0] ?? null;

        if ($user instanceof User && $user->exists) {
            $this->checker->settle($user);
        }
    }
}
