<?php

namespace Tests;

use App\Extension\Testing\ExtensionTestAllowlist;
use App\Listeners\Identity\EnforceIdentityPolicyListener;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * 좀비 커넥션 정리 실행 여부 (프로세스당 1회)
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
     *
     * 확장 로딩 allowlist 도 여기서 주입합니다. parent::setUp() 의
     * createApplication() 단계에서 모든 ServiceProvider 의 register()/boot() 가
     * 실행되므로, allowlist 는 그 이전(= parent::setUp() 호출 전)에 설정되어야
     * provider 자동 등록 가드가 올바른 시점에 적용됩니다.
     */
    protected function setUp(): void
    {
        // 앱 생성 전에 확장 allowlist 설정 (provider register 가드 적용 시점 보장)
        ExtensionTestAllowlist::set($this->resolveAllowedExtensions());

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
     * 테스트 종료 시 확장 allowlist 를 초기화합니다.
     *
     * 프로세스 내 다음 테스트 클래스가 stale allowlist 를 물려받지 않도록
     * 보장합니다.
     */
    protected function tearDown(): void
    {
        ExtensionTestAllowlist::reset();

        // IDV 동적 hook 구독 정적 상태를 초기화한다. 프로세스 내 다음 테스트가
        // 이전 테스트에서 등록된 모듈 hook 콜백을 물려받아 의도치 않게 발화되는
        // 누수를 차단한다 (프로덕션은 단일 부팅이라 무관 — 테스트 격리 전용).
        EnforceIdentityPolicyListener::resetDynamicSubscriptions();

        parent::tearDown();
    }

    /**
     * 이 테스트가 허용하는 확장 목록을 계산합니다.
     *
     * requiredExtensions 선언에 더해, 확장 자체 테스트 베이스 클래스가
     * selfExtension() 으로 반환한 자기 확장을 자동으로 포함합니다.
     * → 확장 테스트 클래스는 자기 확장을 명시하지 않아도 격리에서 허용됩니다.
     *
     * @return array<string>
     */
    protected function resolveAllowedExtensions(): array
    {
        $extensions = $this->requiredExtensions;

        $self = $this->selfExtension();
        if ($self !== null) {
            $extensions[] = $self;
        }

        return array_values(array_unique($extensions));
    }

    /**
     * 이 테스트 클래스가 속한 확장의 경로를 반환합니다.
     *
     * 테스트 클래스 파일의 물리 경로를 검사하여 `modules/<id>` 또는
     * `plugins/<id>` (활성 디렉토리) / `modules/_bundled/<id>` 등을
     * 자동 탐지합니다. 확장 자체 테스트는 이 메서드 덕분에 자기 확장을
     * 명시하지 않아도 격리 allowlist 에 자동 포함됩니다.
     *
     * 코어 테스트(tests/ 하위)는 패턴에 매칭되지 않으므로 null 을 반환합니다.
     *
     * @return string|null 'modules/<id>' / 'plugins/<id>' 또는 null
     */
    protected function selfExtension(): ?string
    {
        try {
            $file = (new \ReflectionClass($this))->getFileName();
        } catch (\ReflectionException) {
            return null;
        }

        if ($file === false) {
            return null;
        }

        // 경로 구분자 정규화 (Windows 백슬래시 → 슬래시)
        $normalized = str_replace('\\', '/', $file);

        // modules/<id>/... 또는 modules/_bundled/<id>/... 에서 확장 식별자 추출
        if (preg_match('#/(modules|plugins)/(?:_bundled/)?([^/]+)/#', $normalized, $m)) {
            return $m[1].'/'.$m[2];
        }

        return null;
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
        // RefreshDatabase가 migrate:fresh를 실행하기 전에 확장 마이그레이션 경로 등록.
        // ① 이 클래스가 선언한 requiredExtensions ② 모든 번들 확장(_bundled) 을 함께 등록한다.
        // RefreshDatabase 는 프로세스당 migrate:fresh 를 1회만 실행하므로, 그 1회를
        // 트리거하는 첫 테스트 클래스가 어떤 확장을 선언했는지에 따라 다른 클래스가
        // 쓰는 확장 테이블이 누락될 수 있다(테스트 순서 의존 — RefreshDatabase 공유 상태).
        // 번들 확장 마이그레이션을 항상 등록해 어떤 순서로 실행되든 모든 번들 테이블이
        // 생성되게 한다(테이블 생성은 provider 무관 — Schema 빌더만 실행, 격리 무영향).
        $this->loadExtensionMigrations();

        return parent::setUpTraits();
    }

    /**
     * 확장 마이그레이션 경로를 migrator 에 등록합니다.
     *
     * RefreshDatabase의 migrate:fresh 실행 시 확장 테이블도 함께 생성되도록
     * 마이그레이션 경로를 등록한다. 대상:
     *  - 이 클래스가 선언한 `requiredExtensions` (활성 디렉토리 경로)
     *  - 모든 번들 확장(`modules|plugins|templates/_bundled/*`) — 테스트 순서에 무관하게
     *    번들 테이블이 항상 생성되도록(공유 RefreshDatabase 상태 누락 방지).
     *
     * `$migrator->path()` 는 동일 경로 중복 등록을 무시하므로 안전하다. selfExtension()
     * (확장 자체 테스트) 은 ModuleTestCase / PluginTestCase 가 migrateFreshUsing() 으로
     * 자기 마이그레이션을 직접 처리하므로 여기서 별도 추가하지 않는다.
     */
    private function loadExtensionMigrations(): void
    {
        $migrator = app('migrator');

        $paths = [];

        // ① 명시 선언한 확장(활성 디렉토리 경로)
        foreach ($this->requiredExtensions as $extension) {
            $paths[] = base_path($extension.'/database/migrations');
        }

        // ② 모든 번들 확장(_bundled) — 테스트 순서 무관 테이블 보장
        foreach (['modules', 'plugins', 'templates'] as $kind) {
            $bundledRoot = base_path($kind.'/_bundled');
            if (! is_dir($bundledRoot)) {
                continue;
            }
            foreach (glob($bundledRoot.'/*', GLOB_ONLYDIR) ?: [] as $extDir) {
                $paths[] = $extDir.'/database/migrations';
            }
        }

        foreach (array_unique($paths) as $migrationPath) {
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
                        DB::statement('KILL '.$process->Id);
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
