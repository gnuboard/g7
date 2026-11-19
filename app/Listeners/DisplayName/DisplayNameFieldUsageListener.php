<?php

namespace App\Listeners\DisplayName;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\FieldDefinition;
use App\Services\DisplayNameSettingsService;

/**
 * 표시 이름 종류로 쓰이는 추가 항목의 삭제를 막는 리스너.
 *
 * 항목 삭제 전 필터 `core.field_definition.filter_usages` 에 사용처(회원이 고를 수 있는 종류, 회원 기본 종류,
 * 그 종류로 고정된 영역과 인스턴스)를 보탠다. 사용처가 하나라도 있으면 삭제 API 가 409 와 그 목록으로 답한다.
 *
 * @since 7.2.0
 */
class DisplayNameFieldUsageListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.field_definition.filter_usages' => ['method' => 'filterUsages', 'type' => 'filter', 'priority' => 10],
        ];
    }

    /**
     * 기본 핸들러 (사용하지 않는다 — 훅마다 메서드를 지정했다)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 항목의 표시 이름 사용처를 보탭니다.
     *
     * @param  mixed  $usages  지금까지 모인 사용처
     * @param  mixed  $definition  삭제하려는 항목
     * @return mixed 사용처 목록
     */
    public function filterUsages(mixed $usages, mixed $definition = null): mixed
    {
        if (! is_array($usages) || ! $definition instanceof FieldDefinition) {
            return $usages;
        }

        return [...$usages, ...app(DisplayNameSettingsService::class)->usagesOf($definition)];
    }
}
