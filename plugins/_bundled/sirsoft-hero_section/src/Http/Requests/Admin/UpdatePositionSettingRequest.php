<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\HeroSection\Enums\SlideEffect;
use Plugins\Sirsoft\HeroSection\Enums\SlidePagination;
use Plugins\Sirsoft\HeroSection\Enums\SlideWidth;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;

/**
 * 위치별 슬라이더 설정 저장 요청 — 간격은 3·5·7초만, 최대 높이는 160~2000px(비우면 비율대로).
 */
class UpdatePositionSettingRequest extends FormRequest
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
            'autoplay' => ['required', 'boolean'],
            'interval_seconds' => ['required', 'integer', Rule::in(PositionSetting::INTERVALS)],
            'effect' => ['required', Rule::enum(SlideEffect::class)],
            // 생략하면 지금 값을 유지한다(처음 저장이면 기본값 content) — 표시 폭을 모르는 이전 호출도 그대로 통과
            'width' => ['sometimes', 'required', Rule::enum(SlideWidth::class)],
            // 메뉴 겹치기 — 생략하면 지금 값 유지(처음 저장이면 꺼짐). 화면 전체 폭일 때만 사이트에 적용되지만 본문 폭에서도 저장은 받는다
            'header_overlay' => ['sometimes', 'required', 'boolean'],
            // 페이징 모양 — 생략하면 지금 값 유지(처음 저장이면 점)
            'pagination' => ['sometimes', 'required', Rule::enum(SlidePagination::class)],
            // 기기별 최대 높이(px) — 생략하면 지금 값 유지, null 이면 비율대로(화면 전체 폭은 640px 에서 멈춤)
            'max_height_pc' => ['sometimes', 'nullable', 'integer', 'between:'.PositionSetting::MAX_HEIGHT_MIN.','.PositionSetting::MAX_HEIGHT_MAX],
            'max_height_mobile' => ['sometimes', 'nullable', 'integer', 'between:'.PositionSetting::MAX_HEIGHT_MIN.','.PositionSetting::MAX_HEIGHT_MAX],
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
            'autoplay' => __('sirsoft-hero_section::validation.attributes.autoplay'),
            'interval_seconds' => __('sirsoft-hero_section::validation.attributes.interval_seconds'),
            'effect' => __('sirsoft-hero_section::validation.attributes.effect'),
            'width' => __('sirsoft-hero_section::validation.attributes.width'),
            'header_overlay' => __('sirsoft-hero_section::validation.attributes.header_overlay'),
            'pagination' => __('sirsoft-hero_section::validation.attributes.pagination'),
            'max_height_pc' => __('sirsoft-hero_section::validation.attributes.max_height_pc'),
            'max_height_mobile' => __('sirsoft-hero_section::validation.attributes.max_height_mobile'),
        ];
    }
}
