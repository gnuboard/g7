<?php

namespace App\Listeners\Identity;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use App\Services\IdentityClaimsService;
use App\Services\UserLockedFieldsService;

/**
 * 본인인증 값으로 채워진 회원 칸을 내 정보 수정에서 잠급니다.
 *
 * 필터 `core.user.locked_fields` 와 `core.user.locked_field_reasons` 를 구독한다. 표면은 내 정보(`profile`)뿐이다 —
 * 관리자 화면에서는 잠그지 않는다. 다른 구독자(회사 계정 연동 등)가 이미 잠근 칸은 그대로 두고 합집합을 돌려주며,
 * 사유는 다른 구독자가 주지 않은 칸에만 `identity` 를 준다.
 *
 * 운영자 추가 항목처럼 회원 칸 수정 잠금의 허용 목록 밖에 있는 항목은 항목 규칙 팩토리가 같은 판정으로 잠근다.
 *
 * @since 7.2.0
 */
class LockIdentityVerifiedFieldsListener implements HookListenerInterface
{
    /**
     * @param  IdentityClaimsService  $claims  본인인증 값 Service
     */
    public function __construct(
        private readonly IdentityClaimsService $claims,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.locked_fields' => ['method' => 'lockedFields', 'priority' => 10, 'type' => 'filter'],
            'core.user.locked_field_reasons' => ['method' => 'lockedReasons', 'priority' => 10, 'type' => 'filter'],
        ];
    }

    /**
     * 기본 핸들러 — 필터 전용이라 호출되지 않습니다.
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 필터 전용
    }

    /**
     * 본인인증으로 잠긴 칸을 잠금 목록에 더합니다.
     *
     * @param  mixed  $fields  지금까지 모인 잠긴 칸 이름
     * @param  mixed  ...$args  [0]=User 대상 회원, [1]=string 표면
     * @return array<int, string> 잠긴 칸 이름 (중복 없음)
     */
    public function lockedFields(mixed $fields = [], ...$args): array
    {
        $fields = is_array($fields) ? $fields : [];

        return array_values(array_unique([...$fields, ...$this->identityLocked($args)]));
    }

    /**
     * 본인인증으로 잠긴 칸의 사유를 더합니다 (다른 구독자가 준 사유는 덮지 않는다).
     *
     * @param  mixed  $reasons  지금까지 모인 `칸 이름 => 사유`
     * @param  mixed  ...$args  [0]=User 대상 회원, [1]=string 표면
     * @return array<string, string> 칸 이름 => 사유
     */
    public function lockedReasons(mixed $reasons = [], ...$args): array
    {
        $reasons = is_array($reasons) ? $reasons : [];

        foreach ($this->identityLocked($args) as $field) {
            $reasons[$field] ??= UserLockedFieldsService::REASON_IDENTITY;
        }

        return $reasons;
    }

    /**
     * 그 회원의 내 정보 표면에서 본인인증으로 잠긴 칸을 반환합니다.
     *
     * @param  array<int, mixed>  $args  훅 인자 ([0]=User, [1]=표면)
     * @return array<int, string> 잠긴 칸 이름 (다른 표면이면 빈 배열)
     */
    private function identityLocked(array $args): array
    {
        $user = $args[0] ?? null;
        $surface = $args[1] ?? null;

        if (! $user instanceof User || $surface !== UserLockedFieldsService::SURFACE_PROFILE) {
            return [];
        }

        return $this->claims->lockedKeysFor($user);
    }
}
