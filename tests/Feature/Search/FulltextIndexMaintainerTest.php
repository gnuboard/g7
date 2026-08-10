<?php

namespace Tests\Feature\Search;

use App\Enums\SearchIndexStatus;
use App\Search\DTO\SearchIndexHealth;
use App\Search\Engines\DatabaseFulltextEngine;
use App\Search\Engines\Maintenance\FulltextIndexMaintainer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FULLTEXT 유지보수기의 발견·판정·재생성 기계적 동작 검증
 *
 * 판정 **결정 로직**(어떤 등급을 재생성 대상으로 볼지)은 엔진 중립 계층에서
 * `SearchIndexMaintenanceManagerTest` 가 고정합니다. 여기서는 FULLTEXT 고유의
 * - 스키마에서 인덱스를 전수 발견하는가
 * - 표본 행이 자기 자신을 찾는지 판정하는가
 * - 재생성이 컬럼 구성과 파서를 보존하는가
 * 를 실제 MySQL 에 대고 확인합니다.
 */
class FulltextIndexMaintainerTest extends TestCase
{
    /** 검사용 임시 테이블명 (논리명 — 물리명은 프리픽스가 붙는다) */
    private const TABLE = 'zz_ft_maintainer_probe';

    /**
     * 물리 테이블명을 반환합니다.
     *
     * raw DDL 은 Laravel 의 테이블 프리픽스를 적용하지 않으므로 직접 붙입니다.
     *
     * @return string 프리픽스가 붙은 실제 테이블명
     */
    private function physicalTable(): string
    {
        return DB::getTablePrefix().self::TABLE;
    }

    /**
     * 테스트 준비 — FULLTEXT 미지원 드라이버면 건너뜁니다.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! DatabaseFulltextEngine::supportsFulltext()) {
            $this->markTestSkipped('FULLTEXT 미지원 드라이버 — 이 검사는 MySQL/MariaDB 에서만 의미가 있습니다.');
        }

        $this->dropProbeTable();
    }

    /**
     * 테스트 정리 — 임시 테이블 제거.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->dropProbeTable();

        parent::tearDown();
    }

    /**
     * 스키마에 새로 만든 FULLTEXT 인덱스를 발견하고 정상으로 판정하는지 검증합니다.
     *
     * 대상 목록을 하드코딩하지 않으므로, 확장이 나중에 추가하는 인덱스도 자동 포함됩니다.
     *
     * @return void
     */
    public function test_새로_만든_인덱스를_발견하고_정상으로_판정한다(): void
    {
        $this->createProbeTable();

        $health = $this->findProbeHealth();

        $this->assertNotNull($health, '스키마에 만든 FULLTEXT 인덱스를 발견하지 못했습니다');
        $this->assertSame(SearchIndexStatus::Healthy, $health->status);
        $this->assertGreaterThan(0, $health->details['probed']);
        $this->assertSame($health->details['probed'], $health->details['found']);
        $this->assertSame(['body'], $health->details['columns']);
    }

    /**
     * 재생성이 컬럼 구성과 파서를 보존하는지 검증합니다.
     *
     * 재생성이 정의를 바꾸면 검색 동작이 조용히 달라집니다.
     *
     * @return void
     */
    public function test_재생성이_컬럼과_파서를_보존한다(): void
    {
        $this->createProbeTable();

        $before = $this->findProbeHealth();
        $this->assertNotNull($before);

        app(FulltextIndexMaintainer::class)->rebuild($before);

        $after = $this->findProbeHealth();

        $this->assertNotNull($after, '재생성 후 인덱스가 사라졌습니다');
        $this->assertSame($before->details['columns'], $after->details['columns']);
        $this->assertSame($before->details['parser'], $after->details['parser']);
        $this->assertSame(SearchIndexStatus::Healthy, $after->status);
    }

    /**
     * 색인할 내용이 없는 테이블은 stale 이 아니라 판정 불가로 보고하는지 검증합니다.
     *
     * 빈 테이블을 "색인 누락" 으로 보면 신규 설치본이 매번 불필요한 재생성을 하게 됩니다.
     *
     * @return void
     */
    public function test_행이_없으면_stale_이_아니라_판정_불가다(): void
    {
        $this->createProbeTable(withRows: false);

        $health = $this->findProbeHealth();

        $this->assertNotNull($health);
        $this->assertSame(SearchIndexStatus::Skipped, $health->status);
        $this->assertFalse($health->needsRebuild(), '빈 테이블이 재생성 대상이 되어서는 안 됩니다');
    }

    /**
     * 테이블 필터가 대상을 좁히는지 검증합니다.
     *
     * @return void
     */
    public function test_테이블_필터로_대상을_좁힌다(): void
    {
        $this->createProbeTable();

        $filtered = app(FulltextIndexMaintainer::class)->inspect(['table' => self::TABLE]);

        $this->assertNotEmpty($filtered);

        foreach ($filtered as $health) {
            $this->assertSame($this->physicalTable(), $health->details['table']);
        }
    }

    /**
     * 검사용 임시 테이블을 만듭니다.
     *
     * @param  bool  $withRows  행을 채울지 여부
     * @return void
     */
    private function createProbeTable(bool $withRows = true): void
    {
        $parser = DatabaseFulltextEngine::supportsNgramParser() ? ' WITH PARSER ngram' : '';

        $table = $this->physicalTable();

        DB::statement(
            'CREATE TABLE `'.$table.'` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                body TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        if ($withRows) {
            foreach ([
                '검수용 본문 이용약관 Terms of Service',
                '검수용 본문 개인정보처리방침 Privacy Policy',
                '검수용 본문 자주 묻는 질문 Frequently Asked',
            ] as $body) {
                DB::statement('INSERT INTO `'.$table.'` (body) VALUES (?)', [$body]);
            }
        }

        DB::statement(
            'ALTER TABLE `'.$table.'` ADD FULLTEXT INDEX `ft_zz_probe_body` (`body`)'.$parser
        );
    }

    /**
     * 검사용 임시 테이블을 제거합니다.
     *
     * @return void
     */
    private function dropProbeTable(): void
    {
        DB::statement('DROP TABLE IF EXISTS `'.$this->physicalTable().'`');
    }

    /**
     * 검사용 인덱스의 판정 결과를 찾습니다.
     *
     * @return SearchIndexHealth|null
     */
    private function findProbeHealth(): ?SearchIndexHealth
    {
        foreach (app(FulltextIndexMaintainer::class)->inspect() as $health) {
            if (($health->details['index'] ?? null) === 'ft_zz_probe_body') {
                return $health;
            }
        }

        return null;
    }
}
