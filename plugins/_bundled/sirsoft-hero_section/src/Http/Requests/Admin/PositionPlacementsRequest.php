<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 대상 목록 요청 — 동적 위치에서 배너가 있는 대상 목록의 쪽 번호.
 */
class PositionPlacementsRequest extends FormRequest
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
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
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
            'page' => __('sirsoft-hero_section::validation.attributes.page'),
        ];
    }
}
