<?php

namespace Tests\Support;

use RuntimeException;

/**
 * 테스트 앱이 테스트 전용 DB 에만 붙도록 지키는 마지막 방어선
 *
 * `tests/bootstrap.php` 는 `.env` 와 `.env.testing` 의 DB 이름이 같으면 멈추고 config 캐시 경로를 테스트 전용으로 돌린다.
 * 그래도 테스트가 도는 사이 다른 경로로 개발 설정이 실리면(같은 클론에서 확장 반영이 config 캐시를 다시 굽는 등)
 * RefreshDatabase 가 개발 DB 의 모든 테이블을 지우고 다시 만든다(2026-10-08 다른 클론에서 개발 DB 전체 초기화).
 * 테스트가 만든 권한 행이 개발 DB 권한 트리에 남은 것도 같은 경로였다(O-D2, `admin.access`).
 * 그래서 설정을 읽은 직후, 서비스 제공자 부팅과 트레이트(RefreshDatabase 등)가 DB 를 만지기 전에 실제 연결 설정의 DB 이름을 확인한다.
 */
final class TestingDatabaseGuard
{
    /**
     * 연결 설정의 DB 이름이 모두 테스트 전용인지 확인하고, 아니면 예외로 멈춥니다.
     *
     * 테스트 전용 이름은 `testing` 을 포함한다(`.env.testing.example` 의 `g7_testing`, 병렬 실행이 붙이는 `_test_N` 포함).
     * SQLite 는 `:memory:` 이거나 경로에 `testing` 이 들어가야 한다. 이름을 하나도 찾지 못해도 멈춘다.
     *
     * @param  string  $connection  연결 이름(오류 문구용)
     * @param  array<string, mixed>  $config  `database.connections.{연결}` 설정
     * @return void 반환값 없음
     *
     * @throws RuntimeException 테스트 전용이 아닌 DB 에 붙으려 할 때
     */
    public static function assertConnectionIsTesting(string $connection, array $config): void
    {
        $names = array_values(array_filter([
            $config['database'] ?? null,
            $config['write']['database'] ?? null,
            $config['read']['database'] ?? null,
        ], static fn ($name): bool => is_string($name) && $name !== ''));

        if ($names === []) {
            throw new RuntimeException("테스트 DB 이름을 찾지 못했다(연결 {$connection}) — 개발 DB 를 지우지 않도록 테스트를 멈춘다.");
        }

        foreach ($names as $name) {
            if ($name === ':memory:' || str_contains($name, 'testing')) {
                continue;
            }

            throw new RuntimeException(
                "테스트 앱이 테스트 전용이 아닌 DB '{$name}'(연결 {$connection})에 붙으려 한다 — 개발 DB 를 지우지 않도록 테스트를 멈춘다. "
                .'bootstrap/cache/config.php(config 캐시)가 있는지, .env.testing 의 DB 이름을 확인한다.'
            );
        }
    }
}
