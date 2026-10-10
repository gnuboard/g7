<?php

namespace App\Contracts\Analytics;

use App\Analytics\DTO\GoalDefinition;

/**
 * 방문자 통계 전환(목표) 등록부 계약
 *
 * 코어 `config/analytics.php` 의 `goals` 와 활성 모듈·플러그인의 `getAnalyticsGoals()`
 * 선언을 매번 모읍니다(DB 에 적재하지 않습니다). 확장이 비활성·제거되면 그 목표는
 * 등록부에서 사라지고, 이미 쌓인 전환은 그대로 남습니다.
 */
interface GoalRegistryInterface
{
    /**
     * 등록된 목표 전부를 정규화 키 기준으로 반환합니다.
     *
     * @return array<string, GoalDefinition> 정규화 키(`{공급자}.{키}`) → 정의
     */
    public function all(): array;

    /**
     * 정규화 키로 목표를 찾습니다.
     *
     * @param  string  $qualifiedKey  `{공급자}.{키}`
     * @return GoalDefinition|null 등록되지 않았으면 null
     */
    public function find(string $qualifiedKey): ?GoalDefinition;

    /**
     * 수집 중 배제된 선언의 사유를 반환합니다.
     *
     * @return array<int, string> 경고 메시지
     */
    public function warnings(): array;

    /**
     * 모아 둔 선언을 버리고 다음 조회에서 다시 모읍니다.
     *
     * @return void 없음
     */
    public function flush(): void;
}
