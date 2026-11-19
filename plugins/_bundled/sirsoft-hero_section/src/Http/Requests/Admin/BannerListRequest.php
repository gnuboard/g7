<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\HeroSection\Enums\BannerStatus;
use Plugins\Sirsoft\HeroSection\Http\Requests\Concerns\PlacementRules;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;
use Plugins\Sirsoft\HeroSection\Support\Positions\Placement;

/**
 * 배너 목록 요청 — 위치·대상·상태·제목 검색·정렬·쪽.
 *
 * 동적 위치에서 대상을 고르기 전(대상 없음)은 빈 목록이다 — 통계(위치별·대상별 건수)는 그대로 실린다. 지워진 「없는 대상」도 연다.
 * 배너가 남은 등록되지 않은 위치도 열고, 그때는 대상을 가리지 않는다(D7).
 */
class BannerListRequest extends FormRequest
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
     * 위치가 없으면 사이트 홈으로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('position')) {
            $this->merge(['position' => PositionRegistry::DEFAULT_POSITION]);
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
            ...$this->placementRules(false, null, true, false),
            'status' => ['nullable', Rule::enum(BannerStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'sort_by' => ['nullable', Rule::in(['sort_order', 'created_at', 'status'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * 검증된 값을 반환합니다 — 대상 키를 위치에 맞추고, 등록되지 않은 위치면 대상 전체(`all_targets`)로 본다.
     *
     * @param  string|null  $key  특정 키만 반환
     * @param  mixed  $default  기본값
     * @return mixed 검증 값
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);
        if ($key !== null) {
            return $data;
        }

        $data = $this->normalizePlacement($data);
        $data['all_targets'] = ! app(PositionRegistry::class)->isRegistered((string) $data['position']);
        if ($data['all_targets']) {
            unset($data['target_key']);
        }

        return $data;
    }

    /**
     * 검증된 자리(위치 + 대상, 등록되지 않은 위치는 대상 전체)를 반환합니다.
     *
     * @return Placement 자리
     */
    public function placement(): Placement
    {
        $data = $this->validated();

        return $data['all_targets'] ? Placement::allTargets($data['position']) : Placement::of($data['position'], $data['target_key'] ?? null);
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
            'status' => __('sirsoft-hero_section::validation.attributes.status'),
            'search' => __('sirsoft-hero_section::validation.attributes.search'),
            'sort_by' => __('sirsoft-hero_section::validation.attributes.sort_by'),
            'sort_order' => __('sirsoft-hero_section::validation.attributes.sort_order'),
            'page' => __('sirsoft-hero_section::validation.attributes.page'),
            'per_page' => __('sirsoft-hero_section::validation.attributes.per_page'),
        ];
    }
}
