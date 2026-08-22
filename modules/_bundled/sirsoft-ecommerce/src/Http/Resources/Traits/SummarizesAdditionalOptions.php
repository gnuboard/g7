<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources\Traits;

use Modules\Sirsoft\Ecommerce\Models\OrderOption;

/**
 * 주문 목록의 추가옵션 요약 트레이트
 *
 * 마이페이지 주문리스트·관리자 주문관리 목록의 대표 옵션(first_option) 아래
 * 추가옵션을 compact 요약으로 노출하기 위한 공용 로직입니다.
 * 첫 추가옵션 1건의 라벨 + "외 N건" 축약(Q-E2)으로 표기하며,
 * 직접입력(custom_text)이 있으면 선택지명에 병기합니다.
 */
trait SummarizesAdditionalOptions
{
    /**
     * 대표 주문옵션의 추가옵션을 목록용으로 요약합니다.
     *
     * 주문 시점 동결된 additional_options_snapshot 을 기준으로 하며(관리자 수정/삭제 무관 D8),
     * 첫 추가옵션의 라벨과 나머지 건수를 반환합니다. 추가옵션이 없으면 null.
     *
     * @param  OrderOption|null  $option  대표 주문옵션
     * @return array{label: string, extra_count: int, total_count: int}|null
     */
    protected function summarizeAdditionalOptions(?OrderOption $option): ?array
    {
        if (! $option) {
            return null;
        }

        $snapshot = $option->additional_options_snapshot ?? [];

        if (! is_array($snapshot) || empty($snapshot)) {
            return null;
        }

        $locale = app()->getLocale();
        $first = $snapshot[0];

        $name = $first['name'] ?? '';
        if (is_array($name)) {
            $name = $name[$locale]
                ?? $name[config('app.fallback_locale', 'ko')]
                ?? (! empty($name) ? reset($name) : '');
        }

        $label = (string) $name;

        // 직접입력 텍스트가 있으면 선택지명에 병기 (예: "각인 추가: 홍길동")
        $customText = trim((string) ($first['custom_text'] ?? ''));
        if ($customText !== '') {
            $label = $label !== '' ? $label.': '.$customText : $customText;
        }

        $totalCount = count($snapshot);

        return [
            'label' => $label,
            'extra_count' => max(0, $totalCount - 1),
            'total_count' => $totalCount,
        ];
    }
}
