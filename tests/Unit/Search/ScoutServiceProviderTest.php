<?php

namespace Tests\Unit\Search;

use App\Search\Engines\DatabaseFulltextEngine;
use Laravel\Scout\EngineManager;
use Tests\TestCase;

/**
 * ScoutServiceProvider 테스트
 *
 * mysql-fulltext 드라이버가 정상 등록되고,
 * core.search.engine_drivers 필터 훅이 동작하는지 검증합니다.
 */
class ScoutServiceProviderTest extends TestCase
{
    /**
     * mysql-fulltext 드라이버가 EngineManager에 등록되는지 테스트합니다.
     */
    public function test_mysql_fulltext_driver_is_registered(): void
    {
        config(['scout.driver' => 'mysql-fulltext']);

        $manager = $this->app->make(EngineManager::class);
        $engine = $manager->engine('mysql-fulltext');

        $this->assertInstanceOf(DatabaseFulltextEngine::class, $engine);
    }

    /**
     * Scout 기본 드라이버가 mysql-fulltext로 설정되는지 테스트합니다.
     */
    public function test_default_scout_driver_is_mysql_fulltext(): void
    {
        $this->assertEquals('mysql-fulltext', config('scout.driver'));
    }

    /**
     * scout.php 설정 파일이 존재하는지 테스트합니다.
     */
    public function test_scout_config_file_exists(): void
    {
        $this->assertFileExists(config_path('scout.php'));
    }

    /**
     * Scout 큐 비활성화 설정을 테스트합니다.
     */
    public function test_scout_queue_is_disabled(): void
    {
        $this->assertFalse(config('scout.queue'));
    }

    /**
     * Scout 소프트 삭제 설정을 테스트합니다.
     */
    public function test_scout_soft_delete_is_enabled(): void
    {
        $this->assertTrue(config('scout.soft_delete'));
    }
}
