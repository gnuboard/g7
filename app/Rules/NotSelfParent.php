<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 자기 자신을 부모로 설정할 수 없도록 검증하는 Custom Rule
 *
 * 메뉴, 카테고리 등 계층 구조에서 자기참조를 방지합니다.
 *
 * @deprecated 자기 자신만 검사하므로 자손을 부모로 지정하는 순환은 막지 못합니다.
 *             신규 코드는 자손 전체를 검사하는 NotCircularParent 를 사용하세요.
 *             확장이 훅으로 이 규칙을 소비할 수 있어 클래스는 제거하지 않습니다.
 */
class NotSelfParent implements ValidationRule
{
    /**
     * NotSelfParent 생성자
     *
     * @param  int|null  $currentId  현재 엔티티의 ID
     */
    public function __construct(
        private ?int $currentId = null
    ) {}

    /**
     * 검증 수행
     *
     * @param  string  $attribute  검증 대상 속성명
     * @param  mixed  $value  검증 대상 값 (부모 ID)
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->currentId === null) {
            return;
        }

        if ($value !== null && (int) $value === $this->currentId) {
            $fail(__('validation.not_self_parent'));
        }
    }
}
