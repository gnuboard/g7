<?php

namespace App\Contracts\Analytics;

use App\Analytics\DTO\DimensionDefinition;

/**
 * 방문자 통계 분류 기준(dimension) 등록부 계약
 *
 * 활성 모듈·플러그인의 `getAnalyticsDimensions()` 선언을 매번 모읍니다. 등록되지 않은
 * 차원 값은 원장에 남지 않습니다.
 */
interface DimensionRegistryInterface
{
    /**
     * 등록된 차원 전부를 원장 이름 기준으로 반환합니다.
     *
     * @return array<string, DimensionDefinition> `{식별자}:{키}` → 정의
     */
    public function all(): array;

    /**
     * 전송된 차원 이름을 등록된 정의로 풉니다.
     *
     * `{식별자}:{키}` 는 그대로 찾고, 짧은 키(`{키}`)는 그 키를 선언한 확장이 하나뿐일 때만
     * 받습니다 — 둘 이상이면 어느 확장의 값인지 정할 수 없으므로 모호함을 사유로 돌려줍니다.
     *
     * @param  string  $name  전송된 차원 이름
     * @return array{0: DimensionDefinition|null, 1: string|null} [정의, 실패 사유]
     */
    public function resolve(string $name): array;

    /**
     * 집계 표의 차원 이름(`ext:{식별자}:{키}`)으로 정의를 찾습니다.
     *
     * @param  string  $statsDimension  집계 차원 이름
     * @return DimensionDefinition|null 등록되지 않았으면 null
     */
    public function findByStatsDimension(string $statsDimension): ?DimensionDefinition;

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
