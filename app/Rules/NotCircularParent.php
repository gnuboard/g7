<?php

namespace App\Rules;

use App\Models\Menu;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 순환 참조를 방지하는 Custom Rule
 *
 * 메뉴를 이동할 때, 자기 자신의 자손(descendant)에게 이동하는 것을 방지합니다.
 * NotSelfParent는 자기 자신만 방지하지만, 이 규칙은 모든 자손을 검사합니다.
 */
class NotCircularParent implements ValidationRule
{
    /**
     * NotCircularParent 생성자
     *
     * @param  int  $menuId  이동하려는 메뉴의 ID
     */
    public function __construct(
        private int $menuId
    ) {}

    /**
     * 검증 수행
     *
     * @param  string  $attribute  검증 대상 속성명
     * @param  mixed  $value  검증 대상 값 (새 부모 ID)
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // null인 경우 최상위로 이동 → 순환 참조 없음
        if ($value === null) {
            return;
        }

        // 자기 자신으로 이동 방지
        if ((int) $value === $this->menuId) {
            $fail(__('validation.not_self_parent'));

            return;
        }

        // 이동하려는 메뉴의 모든 자손 ID를 수집
        $descendantIds = $this->collectDescendantIds($this->menuId);

        // 새 부모가 자손에 포함되면 순환 참조
        if (in_array((int) $value, $descendantIds, true)) {
            $fail(__('validation.not_circular_parent'));
        }
    }

    /**
     * 특정 메뉴의 모든 자손 ID를 재귀적으로 수집합니다.
     *
     * @param  int  $menuId  대상 메뉴 ID
     * @return array<int> 자손 메뉴 ID 목록
     */
    private function collectDescendantIds(int $menuId): array
    {
        $descendants = [];
        $childIds = Menu::where('parent_id', $menuId)->pluck('id')->toArray();

        foreach ($childIds as $childId) {
            $descendants[] = $childId;
            $descendants = array_merge($descendants, $this->collectDescendantIds($childId));
        }

        return $descendants;
    }
}
