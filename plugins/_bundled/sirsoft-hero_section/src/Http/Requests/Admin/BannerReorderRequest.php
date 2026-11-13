<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\HeroSection\Http\Requests\Concerns\PlacementRules;
use Plugins\Sirsoft\HeroSection\Rules\CompletePositionOrder;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;
use Plugins\Sirsoft\HeroSection\Support\Positions\Placement;

/**
 * 순서 변경 요청 — 그 자리(위치 + 대상)의 배너 전체 id 를 새 순서대로 받는다. 등록된 위치만 받는다.
 */
class BannerReorderRequest extends FormRequest
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
        return [
            ...$this->placementRules(false),
            'ids' => ['required', 'array', 'min:1', new CompletePositionOrder($this->inputPlacement())],
            'ids.*' => ['integer', 'distinct'],
        ];
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
     * 검증 전 입력으로 자리를 만듭니다 — 위치가 등록되지 않았거나 동적 위치의 대상이 비었으면 null(위치·대상 규칙이 따로 막는다).
     *
     * @return Placement|null 자리
     */
    private function inputPlacement(): ?Placement
    {
        $position = (string) $this->input('position');
        $definition = app(PositionRegistry::class)->find($position);
        if ($definition === null) {
            return null;
        }
        if (! $definition->isDynamic()) {
            return Placement::of($position);
        }

        $target = $this->input('target_key');

        return is_string($target) && $target !== '' ? Placement::of($position, $target) : null;
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
            'ids' => __('sirsoft-hero_section::validation.attributes.ids'),
            'ids.*' => __('sirsoft-hero_section::validation.attributes.ids_item'),
        ];
    }
}
