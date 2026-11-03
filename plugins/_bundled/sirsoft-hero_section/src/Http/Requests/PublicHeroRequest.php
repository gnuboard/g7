<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;

/**
 * 공개 노출 요청 — 위치 키(확장점 이름)만 받는다.
 */
class PublicHeroRequest extends FormRequest
{
    /**
     * 누구나 부를 수 있는 공개 API 입니다.
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
        return [
            'position' => ['required', Rule::enum(HeroPosition::class)],
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
        ];
    }
}
