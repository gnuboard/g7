<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * 좀비 커넥션 정리 실행 여부 (프로세스당 1회)
     *
     * @var bool
     */
    private static bool $staleConnectionsCleaned = false;

    /**
     * 테스트에 필요한 확장 목록
     *
     * 각 테스트 클래스에서 오버라이드하여 필요한 확장의 마이그레이션을 로드합니다.
     * 경로는 프로젝트 루트 기준 상대 경로입니다.
     *
     * 예: ['plugins/sirsoft-marketing', 'modules/sirsoft-ecommerce']
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [];

    /**
     * 테스트 환경 설정
     *
     * RefreshDatabase 트레이트가 migrate:fresh를 실행하기 전에
     * g7_testing DB의 좀비 커넥션을 정리합니다.
     *
     * 근본 원인: 테스트 프로세스 강제 종료 시 MySQL 커넥션이
     * 트랜잭션 락을 유지한 채 남아있어 DROP TABLE이 metadata lock 대기에 빠짐
     */
    protected function setUp(): void
    {
        if (! self::$staleConnectionsCleaned) {
            // Laravel 앱 부팅 전이므로 부트스트랩 후 콜백으로 등록
            $this->afterApplicationCreated(function () {
                $this->killStaleTestingConnections();
                self::$staleConnectionsCleaned = true;
            });
        }

        parent::setUp();
    }

    /**
     * RefreshDatabase 등 트레이트 초기화 전에 확장 마이그레이션을 등록합니다.
     *
     * Laravel의 setUpTraits()는 RefreshDatabase::refreshDatabase()를 호출하여
     * migrate:fresh를 실행합니다. 확장 마이그레이션 경로는 그 전에 등록되어야 합니다.
     *
     * PHP 메서드 해석 순서: 클래스 > 트레이트 > 부모 클래스
     * → beforeRefreshingDatabase()는 RefreshDatabase 트레이트에 의해 가려지므로
     *   setUpTraits() 오버라이드로 마이그레이션 경로를 먼저 등록합니다.
     *
     * @return void
     */
    protected function setUpTraits()
    {
        // RefreshDatabase가 migrate:fresh를 실행하기 전에 확장 마이그레이션 경로 등록
        if (! empty($this->requiredExtensions)) {
            $this->loadExtensionMigrations();
        }

        return parent::setUpTraits();
    }

    /**
     * requiredExtensions에 선언된 확장의 마이그레이션 경로를 등록합니다.
     *
     * RefreshDatabase의 migrate:fresh 실행 시 확장 테이블도 함께 생성되도록
     * 마이그레이션 경로를 migrator에 등록합니다.
     */
    private function loadExtensionMigrations(): void
    {
        $migrator = app('migrator');

        foreach ($this->requiredExtensions as $extension) {
            $migrationPath = base_path($extension.'/database/migrations');

            if (is_dir($migrationPath)) {
                $migrator->path($migrationPath);
            }
        }
    }

    /**
     * g7_testing DB의 좀비 커넥션을 정리합니다.
     *
     * 현재 프로세스의 커넥션은 제외하고,
     * g7_testing DB에 연결된 다른 모든 커넥션을 KILL합니다.
     */
    private function killStaleTestingConnections(): void
    {
        // phpunit.xml에서 DB_DATABASE=g7_testing으로 설정됨
        $testingDb = config('database.connections.mysql.database');

        try {
            $currentId = DB::selectOne('SELECT CONNECTION_ID() as id')->id;
            $processes = DB::select('SHOW PROCESSLIST');
            $killed = 0;

            foreach ($processes as $process) {
                if (($process->db ?? '') === $testingDb && $process->Id !== $currentId) {
                    try {
                        DB::statement('KILL ' . $process->Id);
                        $killed++;
                    } catch (\Throwable) {
                        // 이미 종료된 커넥션은 무시
                    }
                }
            }

            if ($killed > 0) {
                fwrite(STDERR, "\n[TestCase] Killed {$killed} stale g7_testing connection(s)\n");
            }
        } catch (\Throwable) {
            // DB 연결 실패 시 무시 (첫 마이그레이션에서 처리됨)
        }
    }
}
