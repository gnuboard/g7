<?php

namespace Tests;

use App\Contracts\Notifications\ChannelReadinessCheckerInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\Testing\ExtensionTestAllowlist;
use App\Helpers\PermissionHelper;
use App\Listeners\Identity\EnforceIdentityPolicyListener;
use App\Support\AssetUrl;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
     *
     * settings 디스크는 앱 부팅 직후 페이크로 대체합니다 (아래 fakeSettingsDisk).
     */
    protected function setUp(): void
    {
        // 앱 생성 전에 확장 allowlist 설정 (provider register 가드 적용 시점 보장)
        ExtensionTestAllowlist::set($this->resolveAllowedExtensions());

        $this->afterApplicationCreated(function () {
            $this->fakeSettingsDisk();
            $this->pinEnvironmentDependentSettings();

            // 권한 스코프 캐시는 정적이라 프로세스 전체에 남는다 — 케이스마다 DB 는
            // 초기화되는데 캐시만 남으면 앞 케이스의 권한 행 상태가 뒤로 새어,
            // 스코프 검사가 조용히 건너뛰어진다 (개별 통과 / 스위트 실패).
            PermissionHelper::clearPermissionScopeCache();
        });

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
     * settings 디스크를 페이크로 대체하여 실제 설정 파일을 보호합니다.
     *
     * `settings` 디스크의 root 는 `storage/app/settings` 로, 개발/운영 환경이 실제로
     * 사용하는 설정 파일 그 자체입니다. 페이크로 대체하지 않으면 설정 저장 경로를 타는
     * 테스트(설정 API 호출, ConfigRepository::saveCategory 등)가 실제 설정을 덮어쓰고,
     * RefreshDatabase 는 DB 만 되돌리므로 그 오염이 그대로 남습니다.
     *
     * 실제 피해 사례: 설정 저장 테스트가 `general.json` 의 사이트명/언어를 테스트 값으로
     * 덮어써 개발 사이트 언어가 ja 로 바뀌어 있었고, SEO/캐시 TTL 도 테스트 값으로 대체됨.
     *
     * 설정 *읽기* 는 부팅 시 Config(`g7_settings.*`)로 이미 적재된 뒤이므로 이 페이크의
     * 영향을 받지 않습니다. 저장소를 직접 읽어야 하는 테스트는 페이크 디스크에 직접
     * 값을 넣고 검증합니다.
     */
    private function fakeSettingsDisk(): void
    {
        Storage::fake('settings');
    }

    /**
     * 개발 사이트 설정이 테스트 결과를 좌우하는 항목을 기본값으로 고정합니다.
     *
     * 설정 *읽기* 는 부팅 시 `g7_settings.*` Config 로 적재되므로 디스크 페이크보다 앞섭니다
     * (fakeSettingsDisk 주석 참조). 즉 개발자가 자기 사이트에 어떤 값을 저장해 두었는지가
     * 그대로 테스트에 흘러듭니다.
     *
     * `general.asset_url_mode` 가 그 예다. 개발 사이트를 확장자 없는 모드로 운영하면
     * 기본 모드를 전제한 테스트(자산 URL 생성·blade 렌더)가 그 환경에서만 무더기로 깨진다.
     * 실제로 이 환경에서 8건이 그렇게 실패했다.
     *
     * 다른 모드를 검증해야 하는 테스트는 `AssetUrl::forceMode()` 로 자기 전제를 명시한다.
     */
    private function pinEnvironmentDependentSettings(): void
    {
        config(['g7_settings.core.general.asset_url_mode' => AssetUrl::MODE_EXTENSION]);
    }

    /**
     * mail 채널을 발송 준비 완료 상태로 만듭니다.
     *
     * 테스트 환경의 mail 설정은 from_address 가 플레이스홀더(noreply@example.com)라
     * ChannelReadinessService::checkMail() 이 not-ready 로 판정하고, GenericNotification::via()
     * 가 mail 채널을 제외합니다. 즉 메일 발송을 단언하는 테스트는 이 헬퍼로 명시적으로
     * "메일이 설정된 사이트" 를 만들어야 합니다.
     *
     * (설정 미비 시 발송하지 않는 것은 의도된 제품 동작이므로 기본값은 그대로 둡니다)
     */
    protected function enableMailChannelReadiness(): void
    {
        app(ConfigRepositoryInterface::class)->saveCategory('mail', [
            'mailer' => 'smtp',
            'host' => 'smtp.test.local',
            'port' => 587,
            'encryption' => 'tls',
            'from_address' => 'test@g7.test',
            'from_name' => 'G7 Test',
        ]);

        app(ChannelReadinessCheckerInterface::class)->check('mail');
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

        $this->purgeIdleDatabaseConnections();

        parent::tearDown();
    }

    /**
     * 트랜잭션이 걸려 있지 않은 DB 연결을 모두 닫습니다.
     *
     * 트랜잭션 트레이트 없이 DB 를 쓴 테스트의 연결을 닫는다. RefreshDatabase 계열은
     * 롤백 후 스스로 disconnect 하지만, 순수 TestCase 는 아무도 닫지 않아 프로세스 내
     * 테스트 수만큼 연결이 누적된다 — DB 를 쓰는 테스트가 151개(max_connections)를
     * 넘는 클래스/스위트는 그 지점부터 전부 1040 Too many connections 로 실패한다.
     * 트랜잭션 진행 중인 연결은 건드리지 않는다 (트레이트의 롤백 콜백이 처리).
     */
    protected function purgeIdleDatabaseConnections(): void
    {
        if ($this->app === null) {
            return;
        }

        $db = $this->app['db'];
        foreach ($db->getConnections() as $name => $connection) {
            if ($connection->transactionLevel() === 0) {
                $db->purge($name);
            }
        }
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
     * 테스트 DB 의 좀비 커넥션을 정리합니다.
     *
     * 현재 프로세스의 커넥션은 제외하고,
     * 테스트 DB 에 연결된 다른 모든 커넥션을 KILL 합니다.
     */
    private function killStaleTestingConnections(): void
    {
        // config('database.connections.mysql.database') 를 읽지 않는다 — mysql 커넥션은
        // read/write 분리 구조라 최상위 'database' 키가 존재하지 않아 항상 null 이 된다.
        // null 이면 아래 비교(`($process->db ?? '') === $testingDb`)가 어떤 커넥션과도
        // 매칭되지 않아, 좀비 정리가 조용히 전면 무력화된다(예외도 나지 않는다).
        // 실제 접속 DB 이름은 커넥션에 직접 묻는다(write 설정이 반영된 값).
        try {
            $testingDb = DB::connection()->getDatabaseName();

            if ($testingDb === '') {
                return;
            }

            $currentId = DB::selectOne('SELECT CONNECTION_ID() as id')->id;

            // 정리 결과를 출력하지 않는다 — PHPUnit 은 테스트 실행 중의 예기치 않은 STDOUT/STDERR
            // 출력을 오류(`PHPUnit\Framework\Exception`)로 처리한다. 과거에는 대상 DB 판정이
            // null 이라 아무것도 KILL 하지 못해 출력이 없었고, 판정을 고치자 그 출력 때문에
            // 무관한 테스트들이 무더기로 깨졌다.
            // 실제 정리 여부는 tests/Unit/TestCaseKillStaleConnectionsTest 가 행동으로 검증한다.
            foreach (DB::select('SHOW PROCESSLIST') as $process) {
                if (($process->db ?? '') === $testingDb && $process->Id !== $currentId) {
                    try {
                        DB::statement('KILL '.$process->Id);
                    } catch (\Throwable) {
                        // 이미 종료된 커넥션은 무시
                    }
                }
            }
        } catch (\Throwable) {
            // DB 연결 실패 시 무시 (첫 마이그레이션에서 처리됨)
        }
    }
}
