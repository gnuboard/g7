<?php

namespace Plugins\Sirsoft\HeroSection\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;

/**
 * 대상 키가 위치에 맞는지 확인한다 — 정적 위치는 대상을 받지 않고, 동적 위치는 해석기가 「있다」고 한 대상만 받는다.
 *
 * 값이 비어 있으면 돌지 않는다(동적 위치의 필수는 `required_if` 가 맡는다). 수정하는 배너의 저장된 위치·대상을 그대로 두면 통과한다 —
 * 대상이 지워져(게시판 삭제 등) 「없는 대상」이 된 배너도 문구만 고쳐 저장할 수 있게(D7).
 */
class ValidTarget implements ValidationRule
{
    /**
     * ValidTarget 생성자
     *
     * @param  string  $position  요청한 위치 키
     * @param  bool  $checkExists  해석기로 대상이 있는지까지 볼지 (저장 요청만 — 목록·미리보기는 없는 대상도 연다)
     * @param  Banner|null  $stored  수정 대상 배너 (등록이면 null)
     */
    public function __construct(private string $position, private bool $checkExists = true, private ?Banner $stored = null) {}

    /**
     * 검증을 실행합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  대상 키
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $registry = app(PositionRegistry::class);
        $definition = $registry->find($this->position);
        $unchanged = $this->stored !== null && $this->stored->position === $this->position && (string) $this->stored->target_key === (string) $value;

        if ($definition === null) {
            // 등록되지 않은 위치는 저장된 대상 그대로만 둘 수 있다(위치 규칙이 따로 막는다)
            if ($this->stored !== null && ! $unchanged) {
                $fail(__('sirsoft-hero_section::validation.target.not_found'));
            }

            return;
        }

        if (! $definition->isDynamic()) {
            $fail(__('sirsoft-hero_section::validation.target.not_allowed', ['position' => $definition->label() ?? $this->position]));

            return;
        }

        if ($this->checkExists && ! $unchanged && ! $registry->resolver($this->position)?->exists((string) $value)) {
            $fail(__('sirsoft-hero_section::validation.target.not_found'));
        }
    }
}
