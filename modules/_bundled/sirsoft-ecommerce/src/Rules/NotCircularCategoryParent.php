<?php

namespace Modules\Sirsoft\Ecommerce\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Sirsoft\Ecommerce\Models\Category;

/**
 * 카테고리 순환 참조를 방지하는 검증 규칙
 *
 * 카테고리를 이동할 때 자기 자신 또는 자신의 자손(descendant)을 부모로 지정하는 것을 막습니다.
 * 순환이 만들어지면 path 재계산 재귀가 종료되지 않아 요청이 실패하고, 해당 노드가 트리에서
 * 사라져 관리자 화면으로 복구할 수 없게 됩니다.
 *
 * 자손 판정은 머티리얼라이즈드 path 컬럼의 prefix 매칭(쿼리 1회)으로 수행하며,
 * path 가 비어 있는 레거시 행에 대비해 parent_id 재귀 fallback 을 둡니다.
 */
class NotCircularCategoryParent implements ValidationRule
{
    /**
     * NotCircularCategoryParent 생성자
     *
     * @param  int|null  $categoryId  이동하려는 카테고리 ID (생성 시 null → 자기참조 불가하므로 통과)
     */
    public function __construct(
        private ?int $categoryId = null
    ) {}

    /**
     * 검증을 수행합니다.
     *
     * @param  string  $attribute  검증 대상 속성명
     * @param  mixed  $value  검증 대상 값 (새 부모 ID)
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 최상위로 이동 → 순환 없음
        if ($value === null || $value === '') {
            return;
        }

        // 신규 생성 → 자기 자신이 아직 없으므로 순환 불가
        if ($this->categoryId === null) {
            return;
        }

        $newParentId = (int) $value;

        if ($newParentId === $this->categoryId) {
            $fail(__('sirsoft-ecommerce::validation.category.parent_id.self'));

            return;
        }

        if ($this->isDescendant($newParentId)) {
            $fail(__('sirsoft-ecommerce::validation.category.parent_id.circular'));
        }
    }

    /**
     * 지정한 카테고리가 이동 대상의 자손인지 판정합니다.
     *
     * @param  int  $candidateId  새 부모 후보 카테고리 ID
     * @return bool 자손이면 true
     */
    private function isDescendant(int $candidateId): bool
    {
        $self = Category::find($this->categoryId);

        if (! $self) {
            return false;
        }

        // path 가 채워져 있으면 prefix 매칭 1회로 판정
        if (! empty($self->path)) {
            return Category::where('id', $candidateId)
                ->where('path', 'like', $self->path.'/%')
                ->exists();
        }

        // path 가 비어 있는 레거시 행 — parent_id 를 거슬러 올라가며 판정
        return $this->hasAncestor($candidateId, $this->categoryId);
    }

    /**
     * 후보 카테고리의 조상 중에 대상 ID 가 있는지 확인합니다.
     *
     * @param  int  $candidateId  탐색 시작 카테고리 ID
     * @param  int  $ancestorId  찾으려는 조상 ID
     * @return bool 조상에 포함되면 true
     */
    private function hasAncestor(int $candidateId, int $ancestorId): bool
    {
        $visited = [];
        $currentId = $candidateId;

        while ($currentId !== null && ! isset($visited[$currentId])) {
            $visited[$currentId] = true;

            $parentId = Category::where('id', $currentId)->value('parent_id');

            if ($parentId === null) {
                return false;
            }

            if ((int) $parentId === $ancestorId) {
                return true;
            }

            $currentId = (int) $parentId;
        }

        return false;
    }
}
