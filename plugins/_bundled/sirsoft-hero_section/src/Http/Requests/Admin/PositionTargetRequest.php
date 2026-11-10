<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 대상 고르기 목록 요청 — 검색어, 그리고 이미 고른 대상 키 하나(`key` — 그 이름·사이트 주소·존재 여부를 함께 받는다).
 */
class PositionTargetRequest extends FormRequest
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
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'key' => ['nullable', 'string', 'max:100'],
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
            'keyword' => __('sirsoft-hero_section::validation.attributes.keyword'),
            'key' => __('sirsoft-hero_section::validation.attributes.target_key'),
        ];
    }
}
