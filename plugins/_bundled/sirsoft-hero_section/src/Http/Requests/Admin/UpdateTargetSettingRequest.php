<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\HeroSection\Enums\SlideEffect;
use Plugins\Sirsoft\HeroSection\Enums\SlidePagination;
use Plugins\Sirsoft\HeroSection\Enums\SlideWidth;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;

/**
 * 동적 위치 대상별 슬라이더 설정 저장 요청 — `overrides` 에 명시한 항목만 재정의하고, 빠진 항목은 위치 기본값을 상속한다.
 * 항목 규칙은 위치 설정({@see UpdatePositionSettingRequest})과 같다. `false`·`null`(최대 높이)도 명시값으로 받는다.
 */
class UpdateTargetSettingRequest extends FormRequest
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
        $height = ['nullable', 'integer', 'between:'.PositionSetting::MAX_HEIGHT_MIN.','.PositionSetting::MAX_HEIGHT_MAX];

        return [
            // 빈 객체면 대상 전체가 위치 기본값으로 돌아간다. 모르는 항목은 받지 않는다
            'overrides' => ['present', 'array:'.implode(',', array_keys(PositionSetting::DEFAULTS))],
            'overrides.autoplay' => ['sometimes', 'required', 'boolean'],
            'overrides.interval_seconds' => ['sometimes', 'required', 'integer', Rule::in(PositionSetting::INTERVALS)],
            'overrides.effect' => ['sometimes', 'required', Rule::enum(SlideEffect::class)],
            'overrides.width' => ['sometimes', 'required', Rule::enum(SlideWidth::class)],
            'overrides.header_overlay' => ['sometimes', 'required', 'boolean'],
            'overrides.pagination' => ['sometimes', 'required', Rule::enum(SlidePagination::class)],
            'overrides.max_height_pc' => ['sometimes', ...$height],
            'overrides.max_height_mobile' => ['sometimes', ...$height],
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
            'overrides' => __('sirsoft-hero_section::validation.attributes.overrides'),
            'overrides.autoplay' => __('sirsoft-hero_section::validation.attributes.autoplay'),
            'overrides.interval_seconds' => __('sirsoft-hero_section::validation.attributes.interval_seconds'),
            'overrides.effect' => __('sirsoft-hero_section::validation.attributes.effect'),
            'overrides.width' => __('sirsoft-hero_section::validation.attributes.width'),
            'overrides.header_overlay' => __('sirsoft-hero_section::validation.attributes.header_overlay'),
            'overrides.pagination' => __('sirsoft-hero_section::validation.attributes.pagination'),
            'overrides.max_height_pc' => __('sirsoft-hero_section::validation.attributes.max_height_pc'),
            'overrides.max_height_mobile' => __('sirsoft-hero_section::validation.attributes.max_height_mobile'),
        ];
    }

    /**
     * 검증된 재정의 항목을 반환합니다(키가 있는 항목만 — null 명시값 포함).
     *
     * @return array<string, mixed> 항목 => 값
     */
    public function overrides(): array
    {
        $input = $this->input('overrides');

        return is_array($input) ? array_intersect_key($input, PositionSetting::DEFAULTS) : [];
    }
}
