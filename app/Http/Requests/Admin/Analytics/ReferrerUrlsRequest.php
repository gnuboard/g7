<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Extension\HookManager;

/**
 * 유입처 전체 주소 목록 요청
 *
 * 기간 규약에 유입처 호스트 하나와 쪽 규약을 얹습니다. 집계표에는 호스트까지만
 * 쌓이므로 이 요청만 원장을 직접 읽습니다.
 */
class ReferrerUrlsRequest extends PeriodRequest
{
    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = array_merge(parent::rules(), [
            // 호스트는 집계표의 `referrer_name` 값 그대로다 — 목록의 행에서 받아 오므로
            // 형식을 더 좁히지 않는다(내부 유입 이름은 도메인이 아닐 수 있다).
            'host' => ['required', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다(리포트 요청과 같은 규약).
        return HookManager::applyFilters('core.analytics.referrer_urls_validation_rules', $rules, $this);
    }
}
