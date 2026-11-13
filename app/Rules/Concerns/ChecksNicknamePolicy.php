<?php

namespace App\Rules\Concerns;

use App\Models\User;
use App\Services\NicknamePolicyService;
use Closure;

/**
 * 닉네임 정책 판정에서 자기 사유만 골라 실패시키는 규칙의 공통 부분.
 *
 * 판정은 {@see NicknamePolicyService::violation()} 한 곳이 하고, 규칙 클래스는 자기 사유일 때만 실패한다.
 * 판정은 첫 위반 하나만 돌려주므로 한 닉네임에 오류 문구가 두 개 붙지 않는다.
 *
 * @since 7.2.0
 */
trait ChecksNicknamePolicy
{
    /**
     * @param  User|null  $target  닉네임을 바꿀 회원 (새로 만드는 중이면 null)
     * @param  string  $surface  표면
     * @param  User|null  $actor  요청한 사람
     */
    public function __construct(
        private ?User $target,
        private string $surface,
        private ?User $actor = null,
    ) {}

    /**
     * 검증 규칙을 실행합니다.
     *
     * @param  string  $attribute  검증할 속성명
     * @param  mixed  $value  검증할 값
     * @param  Closure  $fail  실패 콜백
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $violation = app(NicknamePolicyService::class)->violation($this->target, $value, $this->surface, $this->actor);

        if ($violation !== null && $violation['reason'] === $this->reason()) {
            $fail($violation['message']);
        }
    }

    /**
     * 이 규칙이 맡는 위반 사유를 반환합니다.
     *
     * @return string 사유 ({@see NicknamePolicyService}::REASON_*)
     */
    abstract protected function reason(): string;
}
