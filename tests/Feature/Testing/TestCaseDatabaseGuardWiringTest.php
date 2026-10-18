<?php

namespace Tests\Feature\Testing;

use Illuminate\Database\QueryException;
use RuntimeException;
use Tests\TestCase;

/**
 * 테스트 기반 클래스가 앱을 만들 때 테스트 전용 DB 판정을 실제로 거치는지 검증한다.
 *
 * 판정기(TestingDatabaseGuard) 단위 테스트만으로는 기반 클래스에 연결이 빠져도 통과한다. DB 이름 환경 변수를
 * 없는 DB 이름으로 바꾼 채 앱을 다시 만들어, 서비스 제공자가 부팅하며 DB 를 읽기 전(설정 적재 직후)에 판정기 예외로
 * 멈추는지 본다. 판정이 부팅 뒤에 있으면 없는 DB 접속 오류가 먼저 나므로 구분된다. 어떤 DB 에도 쓰지 않는다.
 *
 * @scenario-manifest tests/scenarios/testing-database-guard.yaml
 */
class TestCaseDatabaseGuardWiringTest extends TestCase
{
    /**
     * 쓰기·읽기 DB 이름이 개발 DB 면 앱 생성 단계에서 예외로 멈춘다.
     *
     * @scenario database=test_case_wiring
     *
     * @effects testing_database_guard_blocks_development_db
     *
     * @return void 반환값 없음
     */
    public function test_create_application_stops_on_development_database(): void
    {
        $keys = ['DB_WRITE_DATABASE', 'DB_READ_DATABASE'];
        $original = [];
        foreach ($keys as $key) {
            $original[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv("{$key}=g7_2_dev_probe");
            $_ENV[$key] = 'g7_2_dev_probe';
            $_SERVER[$key] = 'g7_2_dev_probe';
        }

        try {
            $this->createApplication();
            $this->fail('개발 DB 이름으로 앱이 만들어졌다 — 기반 클래스가 판정을 거치지 않는다');
        } catch (RuntimeException $e) {
            // DB 오류(QueryException 도 RuntimeException)로 먼저 멈췄다면 판정 전에 부팅이 DB 에 붙은 것이다
            $this->assertNotInstanceOf(QueryException::class, $e, '판정보다 부팅의 DB 접속이 먼저 일어났다: '.$e->getMessage());
            $this->assertStringContainsString('테스트 전용이 아닌 DB', $e->getMessage());
            $this->assertStringContainsString('g7_2_dev_probe', $e->getMessage());
        } finally {
            foreach ($original as $key => [$env, $envArr, $server]) {
                $env === false ? putenv($key) : putenv("{$key}={$env}");
                if ($envArr === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $envArr;
                }
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
            }
        }
    }
}
