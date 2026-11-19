<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\HeroSection\Http\Requests\Concerns\PlacementRules;
use Plugins\Sirsoft\HeroSection\Support\Positions\Placement;

/**
 * 자리 하나(위치 + 대상)를 받는 요청 — 미리보기·순서 변경 창 목록. 등록된 위치만 받는다. 동적 위치에서 대상을 고르기 전은 빈 목록이다.
 */
class BannerPositionRequest extends FormRequest
{
    use PlacementRules;

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
        return $this->placementRules(false, null, false, false);
    }

    /**
     * 검증된 자리를 반환합니다.
     *
     * @return Placement 자리
     */
    public function placement(): Placement
    {
        $data = $this->normalizePlacement($this->validated());

        return Placement::of($data['position'], $data['target_key']);
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
            'target_key' => __('sirsoft-hero_section::validation.attributes.target_key'),
        ];
    }
}
