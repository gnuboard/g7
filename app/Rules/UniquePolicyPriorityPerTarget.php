<?php

namespace App\Rules;

use App\Models\IdentityPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 같은 적용 지점(scope + target)에 동률 우선순위(priority) 정책이 둘 이상 활성화되는 것을 차단하는 Rule.
 *
 * 배경: 본인인증 정책은 같은 scope+target 에 여러 개 걸릴 수 있는데, 동일 priority 면
 * 어느 정책이 먼저 enforce 되는지(어떤 purpose 로 challenge 되는지)가 비결정적이 된다. 운영자가
 * 우선순위 입력으로 순서를 정하더라도 같은 값을 넣으면 동률이 재발하므로, 저장 시점에 동률을
 * 차단해 "의도한 인증(예: 성인 인증)이 무시되고 임의 정책이 적용"되는 결함을 원천 봉쇄한다.
 *
 * 비활성(enabled=false) 정책은 enforce 되지 않아 동률이어도 무해하므로, 저장하려는 정책이
 * 활성일 때만, 그리고 기존 활성 정책에 대해서만 충돌을 검사한다.
 */
class UniquePolicyPriorityPerTarget implements ValidationRule
{
    /**
     * @param  string  $scope  적용 시점 (route|hook) — 저장하려는 정책 기준
     * @param  string  $target  적용 위치 (route name 또는 hook name)
     * @param  bool  $enabled  저장하려는 정책의 활성 여부
     * @param  int|null  $ignoreId  수정 시 자기 자신을 충돌 검사에서 제외하기 위한 정책 ID
     */
    public function __construct(
        private string $scope,
        private string $target,
        private bool $enabled,
        private ?int $ignoreId = null,
    ) {}

    /**
     * 검증 수행 — 같은 scope+target+priority 의 활성 정책이 이미 있으면 실패.
     *
     * @param  string  $attribute  검증 대상 속성명 (priority)
     * @param  mixed  $value  검증 대상 값 (우선순위 정수)
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 저장하려는 정책이 비활성이면 동률이어도 enforce 되지 않으므로 검사 불필요.
        if (! $this->enabled) {
            return;
        }

        // scope/target 미상(수정 요청에 미포함 등)이면 검사 불가 — 통과시키고 다른 규칙에 위임.
        if ($this->scope === '' || $this->target === '') {
            return;
        }

        if ($value === null || $value === '') {
            return;
        }

        $priority = (int) $value;

        $conflict = IdentityPolicy::query()
            ->where('scope', $this->scope)
            ->where('target', $this->target)
            ->where('priority', $priority)
            ->where('enabled', true)
            ->when($this->ignoreId !== null, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($conflict) {
            $fail(__('validation.identity_policy.priority_duplicate', [
                'target' => $this->target,
                'priority' => $priority,
            ]));
        }
    }
}
