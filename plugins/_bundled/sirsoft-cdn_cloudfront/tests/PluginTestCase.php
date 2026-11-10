<?php

namespace Plugins\Sirsoft\CdnCloudfront\Tests;

use App\Enums\PermissionType;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Extension\PluginManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use Aws\CommandInterface;
use Aws\MockHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Plugins\Sirsoft\CdnCloudfront\Plugin;
use Plugins\Sirsoft\CdnCloudfront\Providers\CdnCloudfrontServiceProvider;
use Plugins\Sirsoft\CdnCloudfront\Services\CloudFrontClientFactory;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * CloudFront CDN 플러그인 테스트 베이스
 *
 * 개발 DB 에 플러그인을 설치하지 않는다 — 실 런타임에서 PluginManager 가 하는 일(플러그인 인스턴스 등록·
 * 서비스 프로바이더·라우트·다국어)을 여기서 대신 한다. 테스트 DB 는 트랜잭션으로 격리하고, 이 플러그인
 * 테이블은 시험마다 트랜잭션 전에 이 플러그인 마이그레이션으로 만들고 끝나면 지운다(setUpTraits 참고).
 *
 * CloudFront API 는 SDK 가짜 처리기(Aws\MockHandler)로만 부른다 — 실제 네트워크 요청은 나가지 않는다.
 */
abstract class PluginTestCase extends TestCase
{
    use DatabaseTransactions;

    /** 플러그인 식별자 */
    protected const IDENTIFIER = 'sirsoft-cdn_cloudfront';

    /** 시험용 CDN 주소 (실제 배포가 아니다) */
    protected const CDN = 'https://cdn.example.test';

    /** 시험용 배포 ID (실제 배포가 아니다) */
    protected const DISTRIBUTION = 'E0TESTDISTRIBUTION';

    /** SDK 가짜 처리기 — 테스트가 결과를 쌓고, 보낸 명령은 $commands 에 남는다 */
    protected MockHandler $aws;

    /** @var list<CommandInterface> 보낸 API 명령 */
    protected array $commands = [];

    /**
     * HookManager static state 스냅샷 — tearDown 에서 복원하여 테스트 간 훅 격리 보장.
     *
     * @var array{hooks: array, filters: array, dispatching: array}|null
     */
    private ?array $hookSnapshot = null;

    /**
     * 테스트 환경 설정
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotHookManager();

        Storage::fake('plugins');

        $manager = app(PluginManager::class);
        $property = new \ReflectionProperty($manager, 'plugins');
        $plugins = $property->getValue($manager);
        $plugins[self::IDENTIFIER] = new Plugin;
        $property->setValue($manager, $plugins);

        $this->app->register(CdnCloudfrontServiceProvider::class);
        $this->app['translator']->addNamespace(self::IDENTIFIER, dirname(__DIR__).'/lang');

        $this->commands = [];
        $this->aws = new MockHandler;
        $this->app->instance(CloudFrontClientFactory::class, new CloudFrontClientFactory(
            function (CommandInterface $command, RequestInterface $request) {
                $this->commands[] = $command;

                return ($this->aws)($command, $request);
            },
        ));

        Route::prefix('api/plugins/'.self::IDENTIFIER)
            ->name('api.plugins.'.self::IDENTIFIER.'.')
            ->middleware('api')
            ->group(dirname(__DIR__).'/src/routes/api.php');
    }

    /**
     * 트랜잭션을 열기 전에 플러그인 테이블을 새로 만들고, 시험이 끝나 트랜잭션을 되돌린 뒤 지웁니다.
     *
     * DDL 은 MySQL 에서 암묵 커밋을 일으키므로 트랜잭션 안에서 돌리면 격리가 깨진다 — 그래서 만들기는 트레이트
     * 초기화(트랜잭션 시작) 앞에서, 지우기는 트랜잭션 되돌리기 콜백 뒤에 등록한 콜백에서 한다(콜백은 등록 순서로 돈다).
     * 이 마이그레이션은 migrations 표에 기록되지 않으므로 시험 DB 에 남겨 두면 다음 실행이 옛 스키마(컬럼 추가 전)를
     * 그대로 쓰고, 공용 시험 DB 를 쓰는 다른 스위트에도 남는다. 시험마다 만들고 지워 늘 현재 마이그레이션 그대로 쓴다.
     *
     * @return array<class-string, class-string> 이 테스트가 쓰는 트레이트
     */
    protected function setUpTraits()
    {
        $migration = require dirname(__DIR__).'/database/migrations/2026_11_05_000000_create_cloudfront_purges_table.php';
        $migration->down();
        $migration->up();

        $uses = parent::setUpTraits();

        $this->beforeApplicationDestroyed(static fn () => $migration->down());

        return $uses;
    }

    /**
     * tearDown 에 HookManager 상태 복원.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->restoreHookManager();
        PluginManager::invalidatePluginStatusCache();

        parent::tearDown();
    }

    /**
     * 플러그인 설정을 저장합니다 (실제 설정 저장 경로 — 민감 필드 암호화 포함).
     *
     * @param  array<string, mixed>  $values  설정값
     * @return void 반환값 없음
     */
    protected function configure(array $values): void
    {
        app(PluginSettingsService::class)->save(self::IDENTIFIER, $values);
    }

    /**
     * 무효화가 동작할 설정(CDN 주소·배포 ID·무효화용 키·자동 무효화 켬)을 저장합니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return void 반환값 없음
     */
    protected function configureReady(array $overrides = []): void
    {
        $this->configure(array_merge([
            'cdn_base_url' => self::CDN,
            'distribution_id' => self::DISTRIBUTION,
            'access_key' => 'TESTACCESSKEY',
            'secret_key' => 'test-secret-value',
            'purge_enabled' => true,
        ], $overrides));
    }

    /**
     * 플러그인 훅 리스너를 실제 등록 경로(HookListenerRegistrar)로 등록합니다 — 활성 플러그인과 같은 상태.
     *
     * @return void 반환값 없음
     */
    protected function registerListeners(): void
    {
        foreach ((new Plugin)->getHookListeners() as $listener) {
            HookListenerRegistrar::register($listener, self::IDENTIFIER);
        }
    }

    /**
     * 코어 퍼지 항목 하나 — 파생본 디렉터리는 CDN 디스크에 있다.
     *
     * @param  string  $sourcePath  원본 경로
     * @param  string|null  $originalUrl  원본 공개 URL
     * @param  string  $host  파생본 URL 호스트 주소
     * @return array<string, mixed> 항목
     */
    protected function purgeItem(string $sourcePath, ?string $originalUrl = null, string $host = self::CDN): array
    {
        return [
            'source_disk' => 'cloudfront',
            'prefix' => $sourcePath,
            'thumbnail_disk' => 'cloudfront',
            'public_url_prefix' => $host.'/thumbnails/cloudfront/'.$sourcePath,
            'original_public_url' => $originalUrl,
        ];
    }

    /**
     * 지정한 권한을 가진 관리자 사용자를 만듭니다.
     *
     * @param  list<string>  $permissions  권한 식별자
     * @return User 사용자
     */
    protected function createAdminUser(array $permissions = []): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'identifier' => 'cdncf-test-'.$user->id.'-'.uniqid(),
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
        ]);
        $user->roles()->attach($role->id);

        foreach (array_merge(['admin.access'], $permissions) as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return $user;
    }

    /**
     * HookManager static $hooks / $filters / $dispatching 를 스냅샷.
     *
     * @return void 반환값 없음
     */
    private function snapshotHookManager(): void
    {
        $ref = new \ReflectionClass(HookManager::class);
        $this->hookSnapshot = [
            'hooks' => $ref->getProperty('hooks')->getValue(),
            'filters' => $ref->getProperty('filters')->getValue(),
            'dispatching' => $ref->getProperty('dispatching')->getValue(),
        ];
    }

    /**
     * 스냅샷 시점으로 HookManager 복원.
     *
     * @return void 반환값 없음
     */
    private function restoreHookManager(): void
    {
        if ($this->hookSnapshot === null) {
            return;
        }

        $ref = new \ReflectionClass(HookManager::class);
        $ref->getProperty('hooks')->setValue(null, $this->hookSnapshot['hooks']);
        $ref->getProperty('filters')->setValue(null, $this->hookSnapshot['filters']);
        $ref->getProperty('dispatching')->setValue(null, $this->hookSnapshot['dispatching']);

        $this->hookSnapshot = null;
    }
}
