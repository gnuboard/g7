<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\HeroSection\Enums\BannerStatus;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;

/**
 * 배너 목록 요청 — 위치·상태·제목 검색·정렬·쪽.
 */
class BannerListRequest extends FormRequest
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
     * 위치가 없으면 사이트 홈으로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('position')) {
            $this->merge(['position' => HeroPosition::HomeHero->value]);
        }
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
            'status' => ['nullable', Rule::enum(BannerStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'sort_by' => ['nullable', Rule::in(['sort_order', 'created_at', 'status'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
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
            'status' => __('sirsoft-hero_section::validation.attributes.status'),
            'search' => __('sirsoft-hero_section::validation.attributes.search'),
            'sort_by' => __('sirsoft-hero_section::validation.attributes.sort_by'),
            'sort_order' => __('sirsoft-hero_section::validation.attributes.sort_order'),
            'page' => __('sirsoft-hero_section::validation.attributes.page'),
            'per_page' => __('sirsoft-hero_section::validation.attributes.per_page'),
        ];
    }
}
