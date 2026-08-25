<?php

namespace Tests\Concerns;

use PDO;
use Throwable;

/**
 * 확장 테이블이 없는 테스트 DB 에서 스스로 건너뛴다.
 *
 * ## 왜 필요한가
 *
 * 실행 모드 축의 테스트는 Laravel 앱을 실제로 부팅한다(`Tests\TestCase` 또는
 * `InProcessCommandRunner`). 그 부팅에서 `CoreServiceProvider` 가 활성 확장 목록을 읽으므로
 * `g7_modules` · `g7_plugins` 가 없으면 **부팅 자체가 죽는다** — 테스트 메서드 안이 아니라
 * `setUp()` 의 `createApplication()` 에서 `QueryException` 이 난다.
 *
 * 그런데 이 저장소의 리허설 DB 는 계획서가 P0 설치 리허설용으로 지정한 것과 **같은 스키마**라
 * (`.claude/plans` 인계 메모: "PHPUnit 과 리허설을 동시에 돌리지 말 것"), 리허설이 `-Fresh` 로
 * 스키마를 비운 직후에 테스트를 돌리면 그 축이 통째로 붉어진다. 실패 메시지는
 * "Table 'g7_modules' doesn't exist" 라 **코드 결함처럼 보이고**, 진짜 회귀와 구분되지 않는다.
 *
 * 그래서 준비되지 않은 DB 에서는 실패가 아니라 **명시적 건너뜀**으로 표시한다 — 초록을 위조하는
 * 것이 아니라, 측정하지 않았다는 사실을 그대로 드러내기 위해서다.
 *
 * ## 사용
 *
 * 앱 부팅 **전**에 판정해야 하므로 `parent::setUp()` 보다 먼저 부른다.
 *
 * ```php
 * protected function setUp(): void
 * {
 *     $this->skipWhenExtensionTablesAreMissing();
 *     parent::setUp();
 * }
 * ```
 */
trait SkipsWhenExtensionTablesAreMissing
{
    /**
     * 부팅이 읽는 확장 테이블 — 하나라도 없으면 CoreServiceProvider 가 죽는다.
     *
     * @var array<int, string>
     */
    private array $requiredExtensionTables = ['modules', 'plugins'];

    /**
     * 확장 테이블이 없으면 테스트를 건너뛴다.
     *
     * 판정은 Laravel 을 거치지 않는다 — 부팅이 바로 그 실패 지점이므로 앱을 만들기 전에
     * 원시 PDO 로 확인해야 한다. 접속 자체가 불가하면 판정하지 않고 통과시킨다(그 실패는
     * 이 트레이트가 다룰 문제가 아니고, 감추면 진짜 설정 오류를 숨기게 된다).
     */
    protected function skipWhenExtensionTablesAreMissing(): void
    {
        $missing = $this->missingExtensionTables();

        if ($missing === []) {
            return;
        }

        $this->markTestSkipped(
            '테스트 DB 에 확장 테이블이 없어 앱 부팅이 불가하다 (없는 표: '.implode(', ', $missing).'). '
            .'설치 리허설이 스키마를 비운 뒤일 수 있다 — `php artisan migrate --env=testing` 후 다시 실행할 것. '
            .'이 축의 수치는 마이그레이션된 DB 를 전제한다.'
        );
    }

    /**
     * 없는 확장 테이블 목록을 돌려준다 (판정 불가면 빈 배열).
     *
     * @return array<int, string> 접두사를 붙인 테이블명
     */
    private function missingExtensionTables(): array
    {
        // Laravel 의 env() 를 쓰지 않는다 — 이 판정은 첫 부팅 **전**에도 돌아야 하는데
        // 그 시점의 env() 는 비어 있어 "판정 불가" 로 통과시킨다. 그러면 클래스의 첫
        // 테스트만 가드를 벗어나 부팅에서 죽는다(실측으로 확인한 사각).
        $config = $this->readEnvFile();

        $database = $config['DB_WRITE_DATABASE'] ?? $config['DB_DATABASE'] ?? '';
        if ($database === '') {
            return [];
        }

        $prefix = $config['DB_PREFIX'] ?? 'g7_';

        try {
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s',
                    $config['DB_WRITE_HOST'] ?? $config['DB_HOST'] ?? '127.0.0.1',
                    $config['DB_WRITE_PORT'] ?? $config['DB_PORT'] ?? '3306',
                    $database
                ),
                $config['DB_WRITE_USERNAME'] ?? $config['DB_USERNAME'] ?? '',
                $config['DB_WRITE_PASSWORD'] ?? $config['DB_PASSWORD'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
            );
        } catch (Throwable) {
            // 접속 불가는 이 트레이트의 관심사가 아니다 — 그대로 진행시켜 실제 사유가 드러나게 한다
            return [];
        }

        $missing = [];

        foreach ($this->requiredExtensionTables as $table) {
            $name = $prefix.$table;

            try {
                $statement = $pdo->prepare('SHOW TABLES LIKE :name');
                $statement->execute([':name' => $name]);

                if ($statement->fetchColumn() === false) {
                    $missing[] = $name;
                }
            } catch (Throwable) {
                return [];
            }
        }

        return $missing;
    }

    /**
     * 테스트 환경 파일에서 DB 접속 값을 읽는다.
     *
     * `.env.testing` 이 이 저장소의 테스트 DB SSoT 다(phpunit.xml 의 DB 하드코딩은 제거됨).
     * 없으면 `.env` 로 떨어지고, 둘 다 없으면 빈 배열 — 판정하지 않는다.
     *
     * @return array<string, string> 키-값
     */
    private function readEnvFile(): array
    {
        $root = dirname(__DIR__, 2);

        foreach (['.env.testing', '.env'] as $candidate) {
            $path = $root.'/'.$candidate;

            if (! is_file($path)) {
                continue;
            }

            // parse_ini_file 은 못 쓴다 — `.env` 는 ini 문법이 아니라서 괄호 등이 들어간
            // 값 하나에 전체 파싱이 실패하고, 그러면 판정 불가로 조용히 통과한다.
            $config = self::parseEnvLines(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

            if ($config !== []) {
                return $config;
            }
        }

        return [];
    }

    /**
     * `.env` 줄 목록을 키-값으로 해석한다.
     *
     * 파싱을 별도 메서드로 둔 이유: 이 트레이트가 잘못 판정하면 실행 모드 축의 클래스들이
     * **통째로 건너뛰어지고 종료 코드는 0** 이다 — 초록이 위조된다. 그런데 판정 본체는 실제
     * `.env` 와 실 DB 접속에 묶여 있어 테스트가 어렵다. 순수한 파싱만 떼어내면 가장 깨지기
     * 쉬운 부분(값 안의 `=`·따옴표·주석·괄호)을 픽스처로 고정할 수 있다.
     *
     * @param  array<int, string>  $lines  파일 줄 목록
     * @return array<string, string> 키-값
     */
    protected static function parseEnvLines(array $lines): array
    {
        $config = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if ($key === '') {
                continue;
            }

            // 인라인 주석은 값이 아니다 (`DB_PREFIX=g7_ # 접두사`). Laravel dotenv 가 허용하는
            // 표기라 언제든 들어올 수 있는데, 값으로 읽으면 표 이름이 어긋나 트레이트가
            // 「표가 없다」로 판정하고 그 축의 클래스를 통째로 skip 한다 — 종료 코드는 0 이다.
            // 따옴표로 감싼 값 안의 `#` 는 값의 일부이므로 따옴표를 먼저 판정한다.
            $quoted = strlen($value) >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[strlen($value) - 1] === $value[0];

            if (! $quoted && ($hash = strpos($value, '#')) !== false) {
                $value = rtrim(substr($value, 0, $hash));
            }

            $config[$key] = trim($value, "\"'");
        }

        return $config;
    }
}
