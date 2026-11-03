<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;
use Plugins\Sirsoft\HeroSection\Rules\CompletePositionOrder;

/**
 * 순서 변경 요청 — 그 위치의 배너 전체 id 를 새 순서대로 받는다.
 */
class BannerReorderRequest extends FormRequest
{
    /**
     * 권한은 라우트 permission 미들웨어가 판정합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $position = HeroPosition::tryFrom((string) $this->input('position'))?->value;

        return [
            'position' => ['required', Rule::enum(HeroPosition::class)],
            'ids' => ['required', 'array', 'min:1', new CompletePositionOrder($position)],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * 검증 오류에 쓸 필드 이름을 반환합니다.
     *
     * @return array<string, string> 필드 => 이름
     */
    public function attributes(): array
    {
        return [
            'position' => __('sirsoft-hero_section::validation.attributes.position'),
            'ids' => __('sirsoft-hero_section::validation.attributes.ids'),
            'ids.*' => __('sirsoft-hero_section::validation.attributes.ids_item'),
        ];
    }
}
