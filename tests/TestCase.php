<?php

namespace Tests;

use App\Contracts\Notifications\ChannelReadinessCheckerInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Extension\Testing\ExtensionTestAllowlist;
use App\Helpers\PermissionHelper;
use App\Listeners\Identity\EnforceIdentityPolicyListener;
use App\Support\AssetUrl;
use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestingDatabaseGuard;
use Tests\Support\TestingSchema\TestingSchemaCache;

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
     * 테스트 DB 의 좀비 커넥션 정리는 여기서 하지 않는다 — 테스트 스키마 판정(TestingSchemaCache::ensure)이 프로세스 잠금을
     * 얻은 뒤, 스키마를 지우기 전에 한 번 부른다(killStaleTestingConnectionsOnce). 예전에는 앱 생성 콜백으로 걸어 두어
     * 트레이트 초기화(migrate:fresh) **뒤에** 돌았다 — 강제 종료로 남은 연결의 잠금을 풀어 DROP TABLE 대기를 막는다는 의도와 반대 순서였다.
     *
     * 확장 로딩 allowlist 도 여기서 주입합니다. parent::setUp() 의
     * createApplication() 단계에서 모든 ServiceProvider 의 register()/boot() 가
     * 실행되므로, allowlist 는 그 이전(= parent::setUp() 호출 전)에 설정되어야
     * provider 자동 등록 가드가 올바른 시점에 적용됩니다.
     *
     * settings 디스크는 앱 부팅 직후 페이크로 대체합니다 (아래 fakeSettingsDisk).
     *
     * @return void 반환값 없음
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

        parent::setUp();
    }

    /**
     * 앱을 만들되, 설정을 읽은 직후 — 서비스 제공자가 부팅하며 DB 를 읽기 전에 — 테스트 전용 DB 에 붙는지 확인합니다.
     *
     * 부팅 단계부터 DB 를 읽으므로(확장 목록·설정 등) 부팅이 끝난 뒤나 `afterApplicationCreated` 콜백(트레이트 초기화
     * 뒤)에서 확인하면 이미 개발 DB 에 붙은 뒤다. 그래서 `LoadConfiguration` 부트스트래퍼 직후에 판정을 건다
     * (TestingDatabaseGuard 주석 참조). 그 밖의 본문은 프레임워크 `TestCase::createApplication()` 과 같다 —
     * 프레임워크를 올릴 때 그쪽 본문이 바뀌었는지 함께 대조한다.
     *
     * 기준 경로는 `Application::inferBasePath()` 로 추론하지 않고 저장소 루트로 고정한다. 그 추론은 등록된 Composer
     * 로더 목록의 첫 항목을 쓰는데, 업그레이드 단계처럼 확장 vendor 오토로더를 싣는 코드를 테스트가 돌리면 그 로더가
     * 맨 앞에 서서 같은 프로세스의 뒤 테스트가 전부 `<확장>/bootstrap/app.php` 를 찾다 실패했다(2026-11-03 실측).
     *
     * @return Application 테스트 앱
     */
    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';

        $app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
            $connection = (string) $app['config']->get('database.default');
            TestingDatabaseGuard::assertConnectionIsTesting(
                $connection,
                (array) $app['config']->get("database.connections.{$connection}", [])
            );
        });

        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));

        if (isset(CachedState::$cachedConfig) &&
            isset($this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }

        if (isset(CachedState::$cachedRoutes) &&
            isset($this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(fn () => $this->markRoutesCached($app));
        }

        $app->make(Kernel::class)->bootstrap();

        return $app;
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
     *
     * @return void 반환값 없음
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
     * `security.two_factor_auth` 도 같다. 켜 둔 사이트에서는 `/api/auth/login` 이 토큰이
     * 아니라 인증 요청(challenge)을 돌려주므로, 로그인 성공을 전제한 테스트가 그 환경에서만
     * 깨진다. 게다가 테스트 메일러로는 인증번호를 보낼 수 없어 503 이 되므로 실패 메시지가
     * 원인을 가리키지도 않는다. 실제로 이 환경에서 3건이 그렇게 실패했다.
     *
     * 다른 모드를 검증해야 하는 테스트는 `AssetUrl::forceMode()` 로, 2단계 인증을 켜야 하는
     * 테스트는 `config(['g7_settings.core.security.two_factor_auth' => true])` 로 자기 전제를
     * 명시한다 (자식 `setUp()` 은 `parent::setUp()` 뒤에 실행되므로 그 지정이 이긴다).
     *
     * @return void 반환값 없음
     */
    private function pinEnvironmentDependentSettings(): void
    {
        config([
            'g7_settings.core.general.asset_url_mode' => AssetUrl::MODE_EXTENSION,
            'g7_settings.core.security.two_factor_auth' => false,
            // 적용 범위도 함께 고정한다. `two_factor_auth` 만 고정하면 이 키가 사이트 저장값
            // ('admin') 을 그대로 읽어, 2단계 인증을 켠 테스트가 **일반 회원 경로에서만**
            // 조용히 건너뛴다 — 관리자 케이스는 통과하므로 원인이 더 가려진다.
            'g7_settings.core.security.two_factor_scope' => 'all',
            // IP 접근 제한도 꺼 둔다. 개발 사이트에서 켜 두면 전역 미들웨어가 테스트 요청(127.0.0.1)을
            // 관리자 영역·규칙 경로에서 무더기로 403 으로 끊는데, 실패 메시지는 원인을 가리키지 않는다.
            // 제한을 검증하는 테스트는 이 세 키를 자기 setUp 에서 명시한다.
            'g7_settings.core.security.ip_restriction_enabled' => false,
            'g7_settings.core.security.admin_allowed_ips' => [],
            'g7_settings.core.security.ip_restriction_rules' => [],
            // 사이트 주소도 고정한다. 부팅 시 개발 사이트의 `general.site_url` 이 `app.url` 을 덮어쓰는데,
            // 리다이렉트·콜백·링크·사이트맵 주소는 요청 Host 가 아니라 이 값으로 만들어지므로(SiteUrl)
            // 테스트 요청(http://localhost)과 다른 개발 사이트 주소가 결과에 섞인다. 다른 주소를 검증하는
            // 테스트는 자기 setUp 에서 `app.url` 을 명시한다.
            'app.url' => 'http://localhost',
            'g7_settings.core.general.site_url' => 'http://localhost',
            // 방문자 통계의 개인정보 모드와 DNT 존중도 기본값(IP 저장·존중)으로 고정한다. 개발 사이트를 동의 모드로 두면
            // 동의 제공자가 없는 테스트 환경에서는 익명으로 내려가 방문자 쿠키를 심지 않고, DNT 존중을 끄면 제외 판정 순서가
            // 달라져, 쿠키 발급·제외 사유를 단언한 테스트가 그 환경에서만 깨진다. 다른 값을 검증하는 테스트는 자기 setUp 이나
            // 본문에서 이 키를 명시한다.
            'g7_settings.core.analytics.privacy_mode' => 'full',
            'g7_settings.core.analytics.respect_dnt' => true,
            // 비밀번호 정책(#736)도 전부 기본값(꺼짐)으로 고정한다. 개발 사이트에서 만료·작성 규칙을 켜 두면
            // 로그인이 토큰 대신 변경 단계를 돌려주고, 가입·변경 테스트의 비밀번호가 조합·반복 규칙에 걸려
            // 그 환경에서만 무더기로 깨진다. 정책을 검증하는 테스트는 해당 키를 자기 setUp 에서 명시한다.
            'g7_settings.core.security.password_require_char_types' => false,
            'g7_settings.core.security.password_block_identifier' => false,
            'g7_settings.core.security.password_block_repeated_chars' => false,
            'g7_settings.core.security.password_repeated_chars_length' => 4,
            'g7_settings.core.security.password_block_sequential_chars' => false,
            'g7_settings.core.security.password_sequential_chars_length' => 4,
            'g7_settings.core.security.password_reuse_prevention_enabled' => false,
            'g7_settings.core.security.password_reuse_count' => 3,
            'g7_settings.core.security.password_expiry_enabled' => false,
            'g7_settings.core.security.password_expiry_days' => 90,
            'g7_settings.core.security.password_expiry_notice_days' => 7,
            'g7_settings.core.security.password_expiry_scope' => 'all',
            'g7_settings.core.security.password_expiry_action' => 'force',
            'g7_settings.core.security.password_expiry_remind_days' => 30,
            'g7_settings.core.security.password_expiry_batch_size' => 1000,
            // 활동 로그 보관 기간(#736 D14) — 사이트 저장값이 파기 테스트로 새지 않게 기본값으로 고정
            'g7_settings.core.security.activity_log_retention_days' => 365,
            'g7_settings.core.security.password_expiry_started_at' => null,
        ]);
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        // 트랜잭션 테스트 안의 DDL 은 MySQL 이 열린 트랜잭션을 암묵 커밋한다 — 그 테스트의 행이 롤백되지 않고 남는다.
        // 트랜잭션 수준은 남았는데 PDO 는 트랜잭션 밖이면 다음 테스트 전에 스키마·데이터를 다시 판정하게 표시한다.
        $this->markSchemaDirtyOnImplicitCommit();

        ExtensionTestAllowlist::reset();

        // IDV 동적 hook 구독 정적 상태를 초기화한다. 프로세스 내 다음 테스트가
        // 이전 테스트에서 등록된 모듈 hook 콜백을 물려받아 의도치 않게 발화되는
        // 누수를 차단한다 (프로덕션은 단일 부팅이라 무관 — 테스트 격리 전용).
        EnforceIdentityPolicyListener::resetDynamicSubscriptions();

        // 훅 등록 정적 상태를 초기화한다. 두 가지를 동시에 해결한다.
        //
        // ① **격리** — 테스트가 `HookManager::addAction()` 으로 붙인 스파이·스텁이 프로세스 내
        //    다음 테스트까지 살아남아 엉뚱한 곳에서 발화한다.
        // ② **메모리** — 그 클로저들이 `$this`(TestCase 인스턴스)를 캡처하므로, 정리하지 않으면
        //    **테스트마다 앱 컨테이너가 통째로 GC 되지 못하고 쌓인다**. 훅을 등록하는 테스트 파일이
        //    75개라 전량 스위트가 `memory_limit`(1G)을 소진하고 중간에 죽었다 — 그 뒤 결과 전부를
        //    신호로 쓸 수 없게 만드는 사고였다.
        //
        // 둘은 **짝으로** 비워야 한다. 등록 이력만 남으면 다음 앱 부팅에서 리스너가 "이미 등록됨"
        // 으로 건너뛰어져 훅이 통째로 사라진다.
        HookManager::resetAll();
        HookListenerRegistrar::clear();
        $this->purgeIdleDatabaseConnections();
        $this->moveRootComposerLoaderBackToFront();

        // 시험 대상 코드의 `set_time_limit(300)`(정적 게시 등)이 프로세스에 남으면 Windows PHP 는 그 시점부터 실제 경과
        // 시간을 재서, 같은 프로세스의 뒤 테스트가 300초를 넘기는 순간 PHPUnit 전체가 치명 오류로 끝난다(2026-11-03 실측).
        // CLI 기본값(무제한)으로 되돌린다.
        set_time_limit(0);

        parent::tearDown();
    }

    /**
     * 테스트가 맨 앞에 끼운 Composer 로더(확장 vendor)를 목록 뒤로 옮겨 루트 vendor 로더를 첫 자리로 되돌립니다.
     *
     * 업그레이드 단계처럼 확장 `vendor/autoload.php` 를 실제로 싣는 코드는 그 로더를 맨 앞에 등록한다. 첫 항목은
     * 기준 경로 추론(`Application::inferBasePath()`)과 `tests/bootstrap.php` 의 등록 순서 계약이 기대는 자리다
     * (ExtensionVendorAutoloadInTestsTest). 해제하지 않고 뒤로 옮기므로 그 로더가 싣는 클래스는 계속 쓸 수 있다.
     *
     * @return void 반환값 없음
     */
    private function moveRootComposerLoaderBackToFront(): void
    {
        $root = realpath(dirname(__DIR__).'/vendor');

        foreach (ClassLoader::getRegisteredLoaders() as $vendorDir => $loader) {
            if (realpath($vendorDir) === $root) {
                return;
            }

            $loader->unregister();
            $loader->register(false);
        }
    }

    /**
     * 열린 트랜잭션이 DDL 로 암묵 커밋됐으면 테스트 스키마를 다시 판정하게 표시합니다.
     *
     * 그 테스트가 정규 마이그레이션의 표·기록을 지웠으면(확장 「데이터까지 삭제」 제거 등) 빠진 것만
     * 다시 올려 둔다 — 다음 판정이 전체 재생 대신 재사용하게.
     *
     * @return void 반환값 없음
     */
    private function markSchemaDirtyOnImplicitCommit(): void
    {
        if ($this->app === null) {
            return;
        }

        foreach ($this->app['db']->getConnections() as $connection) {
            $pdo = $connection->getRawPdo();
            if ($connection->transactionLevel() > 0 && $pdo instanceof \PDO && ! $pdo->inTransaction()) {
                TestingSchemaCache::markDirty();
                TestingSchemaCache::restoreRemovedMigrations($this);

                return;
            }
        }
    }

    /**
     * 트랜잭션이 걸려 있지 않은 DB 연결을 모두 닫습니다.
     *
     * 트랜잭션 트레이트 없이 DB 를 쓴 테스트의 연결을 닫는다. RefreshDatabase 계열은
     * 롤백 후 스스로 disconnect 하지만, 순수 TestCase 는 아무도 닫지 않아 프로세스 내
     * 테스트 수만큼 연결이 누적된다 — DB 를 쓰는 테스트가 151개(max_connections)를
     * 넘는 클래스/스위트는 그 지점부터 전부 1040 Too many connections 로 실패한다.
     * 트랜잭션 진행 중인 연결은 건드리지 않는다 (트레이트의 롤백 콜백이 처리).
     *
     * @return void 반환값 없음
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
     * @return array<string> 이 테스트에서 로딩을 허용할 확장 식별자 목록
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
     * @return void 반환값 없음
     */
    protected function setUpTraits()
    {
        // 확장 마이그레이션 경로 등록 — 테스트가 직접 부르는 `migrate`(설치 경로 등)를 위해 유지한다.
        // 테스트 스키마 자체는 아래 판정이 클래스 선언과 무관하게 정규 집합(코어 + 모든 번들)으로 만든다.
        $this->loadExtensionMigrations();

        // DB 를 쓰는 테스트는 트레이트가 트랜잭션을 열기 전에 스키마·데이터 상태를 맞춘다. 마이그레이션이 바뀌지 않았으면
        // migrate:fresh(약 50초) 대신 잔여 행만 정리하고, RefreshDatabaseState::$migrated 를 세워 트레이트의 재마이그레이션을 막는다.
        if ($this->needsTestingSchema()) {
            TestingSchemaCache::ensure($this, $this->requiredTestingSchema());
        } else {
            $this->watchCommittedWritesWithoutTransaction();
        }

        return parent::setUpTraits();
    }

    /**
     * DB 트레이트 없는 테스트가 테스트 DB 에 쓰면(INSERT·UPDATE·DELETE·DDL) 끝날 때 다음 테스트가 다시 판정하게 표시합니다.
     *
     * 트레이트가 없으면 쓰기가 곧바로 커밋되고 스키마 판정도 받지 않는다. 같은 프로세스에서 이미 판정을 마친 뒤라면 다음 DB
     * 테스트는 「확인됨」으로 넘어가 남은 행과 충돌했다(2026-11-03 실측 — 실제 업그레이드 단계를 돌리는 테스트가 커밋한 역할·
     * 알림 정의 행이 뒤 테스트의 중복 키 오류로 이어짐).
     *
     * @return void 반환값 없음
     */
    private function watchCommittedWritesWithoutTransaction(): void
    {
        // 기본 연결은 읽기·쓰기 분리 설정이라 설정값 대신 연결이 보고하는 DB 이름과 비교한다
        $database = (string) $this->app['db']->connection()->getDatabaseName();

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $query) use ($database): void {
            if ($query->connection->getDatabaseName() === $database
                && preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate|rename)\b/i', $query->sql) === 1) {
                TestingSchemaCache::markDirty();
            }
        });
    }

    /**
     * 이 테스트가 테스트 DB 스키마를 쓰는지 판정합니다 — DB 트레이트(RefreshDatabase 계열·DatabaseTransactions) 사용 여부.
     *
     * `refreshDatabase()` 를 덮어써 트랜잭션만 여는 클래스도 트레이트를 쓰므로 여기서 스키마를 보장받는다. 테스트 DB 를 쓰지 않는
     * 클래스(SQLite 격리 등)는 덮어써 false 를 돌려준다.
     *
     * @return bool 스키마 판정이 필요하면 true
     */
    protected function needsTestingSchema(): bool
    {
        $traits = class_uses_recursive(static::class);

        foreach ([RefreshDatabase::class, DatabaseTransactions::class, DatabaseTruncation::class, DatabaseMigrations::class] as $trait) {
            if (isset($traits[$trait])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 이 테스트가 요구하는 데이터 프로필을 돌려줍니다.
     *
     * `migrateFreshUsing()` 이 시더를 지정했거나 시드를 켰으면 `seed:<시더>`(그 시더를 돌린 상태), 아니면 `any`(현재 상태 수용).
     * 첫 사용 때 시드 없는 상태가 필요한 베이스는 덮어써 `none-exact` 를 돌려준다.
     *
     * @return string 요구 프로필(any · none-exact · seed:<시더 FQCN>)
     */
    protected function requiredTestingSchema(): string
    {
        if (method_exists($this, 'migrateFreshUsing')) {
            $options = (array) $this->migrateFreshUsing();
            $seeder = $options['--seeder'] ?? null;

            if (is_string($seeder) && $seeder !== '') {
                return 'seed:'.ltrim($seeder, '\\');
            }

            if (! empty($options['--seed'])) {
                return 'seed:Database\\Seeders\\DatabaseSeeder';
            }
        }

        return 'any';
    }

    /**
     * 테스트 DB 의 좀비 커넥션을 프로세스당 한 번 정리합니다(테스트 스키마 판정이 잠금을 얻은 뒤 부른다).
     *
     * @return bool 이번 호출에서 정리했으면 true(이미 정리했으면 false)
     */
    public function killStaleTestingConnectionsOnce(): bool
    {
        if (self::$staleConnectionsCleaned) {
            return false;
        }

        $this->killStaleTestingConnections();
        self::$staleConnectionsCleaned = true;

        return true;
    }

    /**
     * 확장 마이그레이션 경로를 migrator 에 등록합니다.
     *
     * 테스트가 직접 실행하는 `migrate` 가 확장 테이블도 볼 수 있도록 마이그레이션 경로를 등록한다
     * (테스트 스키마는 TestingSchemaCache 가 정규 집합으로 따로 만든다). 대상:
     *  - 이 클래스가 선언한 `requiredExtensions` (활성 디렉토리 경로)
     *  - 모든 번들 확장(`modules|plugins|templates/_bundled/*`) — 테스트 순서에 무관하게
     *    번들 테이블이 항상 생성되도록(공유 RefreshDatabase 상태 누락 방지).
     *
     * `$migrator->path()` 는 동일 경로 중복 등록을 무시하므로 안전하다. selfExtension()
     * (확장 자체 테스트) 은 ModuleTestCase / PluginTestCase 가 migrateFreshUsing() 으로
     * 자기 마이그레이션을 직접 처리하므로 여기서 별도 추가하지 않는다.
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
