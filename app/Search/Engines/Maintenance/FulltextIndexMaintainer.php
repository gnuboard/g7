<?php

namespace App\Search\Engines\Maintenance;

use App\Enums\SearchIndexStatus;
use App\Search\Contracts\SearchIndexMaintainer;
use App\Search\DTO\FulltextIndexDefinition;
use App\Search\DTO\SearchIndexHealth;
use App\Search\Engines\DatabaseFulltextEngine;
use App\Search\FulltextIndexInspector;

/**
 * `mysql-fulltext` 드라이버의 인덱스 유지보수기
 *
 * 엔진 중립 계약(`SearchIndexMaintainer`)을 MySQL FULLTEXT 방식으로 구현합니다.
 * 판정 방법(자기 매칭 프로브)과 재생성 방법(같은 정의로 DROP/ADD)은
 * `FulltextIndexInspector` 가 갖고 있고, 이 클래스는 그 결과를 엔진 중립 DTO 로 옮깁니다.
 *
 * 다른 엔진(Meilisearch 등)은 이 클래스를 상속하지 않고 계약을 각자 구현합니다 —
 * 인덱스의 실체가 다르므로 공유할 구현이 없습니다.
 */
class FulltextIndexMaintainer implements SearchIndexMaintainer
{
    /** 이 유지보수기가 담당하는 Scout 드라이버명 */
    public const DRIVER = 'mysql-fulltext';

    /**
     * @param  FulltextIndexInspector  $inspector  FULLTEXT 인덱스 점검기
     */
    public function __construct(private readonly FulltextIndexInspector $inspector) {}

    /**
     * {@inheritDoc}
     */
    public function driver(): string
    {
        return self::DRIVER;
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return DatabaseFulltextEngine::supportsFulltext();
    }

    /**
     * {@inheritDoc}
     */
    public function unavailableReason(): ?string
    {
        return $this->isAvailable()
            ? null
            : __('search.index.fulltext.unsupported_driver');
    }

    /**
     * {@inheritDoc}
     *
     * 지원 필터: `table`(테이블명, 프리픽스 생략 가능) · `index`(인덱스명) · `samples`(표본 행 수)
     */
    public function inspect(array $filters = []): array
    {
        $sampleSize = (int) ($filters['samples'] ?? FulltextIndexInspector::DEFAULT_SAMPLE_SIZE);

        $results = $this->inspector->inspectAll(
            table: isset($filters['table']) ? (string) $filters['table'] : null,
            index: isset($filters['index']) ? (string) $filters['index'] : null,
            sampleSize: max(1, $sampleSize),
        );

        return array_map(fn (array $result) => $this->toHealth($result), $results);
    }

    /**
     * {@inheritDoc}
     */
    public function rebuild(SearchIndexHealth $health): void
    {
        $context = $health->context;

        $this->inspector->rebuild(new FulltextIndexDefinition(
            table: (string) ($context['table'] ?? ''),
            name: (string) ($context['name'] ?? ''),
            columns: (array) ($context['columns'] ?? []),
            parser: isset($context['parser']) ? (string) $context['parser'] : null,
        ));
    }

    /**
     * 점검기 결과를 엔진 중립 판정 DTO 로 옮깁니다.
     *
     * @param  array{definition: FulltextIndexDefinition, status: SearchIndexStatus, probed: int, found: int, reason: ?string}  $result  점검기 결과
     * @return SearchIndexHealth
     */
    private function toHealth(array $result): SearchIndexHealth
    {
        $definition = $result['definition'];
        $status = $result['status'];

        $measurement = $status === SearchIndexStatus::Skipped
            ? (string) $result['reason']
            : __('search.index.fulltext.self_match', ['found' => $result['found'], 'probed' => $result['probed']]);

        return new SearchIndexHealth(
            driver: self::DRIVER,
            identifier: $definition->label(),
            status: $status,
            measurement: $measurement,
            details: [
                'table' => $definition->table,
                'index' => $definition->name,
                'columns' => $definition->columns,
                'parser' => $definition->parser,
                'probed' => $result['probed'],
                'found' => $result['found'],
            ],
            context: [
                'table' => $definition->table,
                'name' => $definition->name,
                'columns' => $definition->columns,
                'parser' => $definition->parser,
            ],
        );
    }
}
