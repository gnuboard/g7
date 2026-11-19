<?php

namespace App\Rules;

use App\Fields\FieldContext;
use App\Models\User;
use App\Services\NicknamePolicyService;

/**
 * 닉네임 정책 규칙 묶음 (환경설정 > 회원 > 닉네임 정책).
 *
 * 요청 규칙과 서비스 계층이 같은 판정({@see NicknamePolicyService::violation()})을 쓴다.
 *
 * @since 7.2.0
 */
final class NicknamePolicy
{
    /**
     * 닉네임 칸에 붙일 정책 규칙을 반환합니다.
     *
     * @param  User|null  $target  닉네임을 바꿀 회원 (가입, 관리자 회원 추가면 null)
     * @param  string  $surface  표면 ({@see FieldContext}::SURFACE_*)
     * @param  User|null  $actor  요청한 사람
     * @return array<int, mixed> 규칙 배열 (금지어, 중복, 변경 주기 순)
     */
    public static function rules(?User $target, string $surface, ?User $actor = null): array
    {
        return [
            new NicknameForbiddenWords($target, $surface, $actor),
            new UniqueNickname($target, $surface, $actor),
            new NicknameChangeInterval($target, $surface, $actor),
        ];
    }

    /**
     * 닉네임이 정책에 걸리면 사유를 반환합니다.
     *
     * @param  User|null  $target  닉네임을 바꿀 회원
     * @param  string  $nickname  새 닉네임
     * @param  string  $surface  표면
     * @param  User|null  $actor  요청한 사람
     * @return string|null 위반 사유 ({@see NicknamePolicyService}::REASON_*), 통과면 null
     */
    public static function check(?User $target, string $nickname, string $surface, ?User $actor = null): ?string
    {
        return app(NicknamePolicyService::class)->check($target, $nickname, $surface, $actor);
    }
}
