<?php

namespace App\Rules;

use App\Enums\IdentityPolicyScope;
use App\Identity\IdentityCheckpointRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 본인인증 정책 범위가 「사용자 지정」이면 대상이 등록된 확장 지점 키여야 한다는 규칙 (7.2.1)
 *
 * 범위가 사용자 지정이 아니면 판정하지 않는다. 수정 요청에서 대상이 저장값과 같으면 통과한다 — 확장을 끄거나 지워 키가 사라진
 * 기존 정책도 다른 칸(켜기/끄기 등)은 고칠 수 있어야 한다.
 */
class RegisteredIdentityCheckpointRule implements ValidationRule
{
    /**
     * @param  Closure(): ?string  $scope  실효 범위를 돌려주는 함수 (요청값 또는 저장값)
     * @param  string|null  $unchangedTarget  수정 요청의 저장된 대상 (같으면 통과)
     */
    public function __construct(
        private readonly Closure $scope,
        private readonly ?string $unchangedTarget = null,
    ) {}

    /**
     * 검증을 수행합니다.
     *
     * @param  string  $attribute  속성 이름
     * @param  mixed  $value  대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (($this->scope)() !== IdentityPolicyScope::Custom->value) {
            return;
        }

        if ($this->unchangedTarget !== null && $value === $this->unchangedTarget) {
            return;
        }

        if (! is_string($value) || ! app(IdentityCheckpointRegistry::class)->has($value)) {
            $fail(__('validation.identity_policy.custom_target_unregistered'));
        }
    }
}
