<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Rules\RegisteredPosition;
use Plugins\Sirsoft\HeroSection\Rules\ValidTarget;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;

/**
 * 위치 + 대상(`position`·`target_key`) 공통 규칙 — 등록·수정·목록·미리보기·순서 요청이 같은 판정을 쓴다.
 *
 * 동적 위치는 대상이 필수(조회는 선택 — 대상을 고르기 전 목록은 빈 목록), 정적 위치는 대상을 받지 않는다. 등록되지 않은 위치는
 * 저장된 배너를 그대로 둘 때(수정)와 배너가 남은 위치를 여는 관리자 목록만 받는다(D7).
 */
trait PlacementRules
{
    /**
     * 위치 + 대상 검증 규칙을 반환합니다.
     *
     * @param  bool  $checkExists  해석기로 대상이 있는지까지 볼지 (저장 요청만)
     * @param  Banner|null  $stored  수정 대상 배너 (등록이면 null)
     * @param  bool  $orHasBanners  배너가 남은 등록되지 않은 위치도 받을지 (관리자 목록만)
     * @param  bool  $targetRequired  동적 위치에서 대상이 필수인지 (조회 요청은 false — 대상이 비면 빈 목록)
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    protected function placementRules(bool $checkExists, ?Banner $stored = null, bool $orHasBanners = false, bool $targetRequired = true): array
    {
        $position = (string) $this->input('position');

        return [
            'position' => ['required', 'string', 'max:50', new RegisteredPosition($stored?->position, $orHasBanners)],
            'target_key' => [
                Rule::requiredIf(fn () => $targetRequired && app(PositionRegistry::class)->isDynamic($position)),
                'nullable', 'string', 'max:100',
                new ValidTarget($position, $checkExists, $stored),
            ],
        ];
    }

    /**
     * 검증된 값의 대상 키를 위치에 맞춥니다 — 정적 위치는 null, 등록되지 않은 위치는 저장된 값 그대로.
     *
     * @param  array<string, mixed>  $data  검증된 값
     * @param  Banner|null  $stored  수정 대상 배너 (등록이면 null)
     * @return array<string, mixed> 맞춘 값
     */
    protected function normalizePlacement(array $data, ?Banner $stored = null): array
    {
        if (! isset($data['position'])) {
            return $data;
        }

        $definition = app(PositionRegistry::class)->find((string) $data['position']);
        $data['target_key'] = match (true) {
            $definition === null => $stored?->target_key,
            ! $definition->isDynamic() => null,
            default => isset($data['target_key']) && $data['target_key'] !== '' ? (string) $data['target_key'] : null,
        };

        return $data;
    }
}
