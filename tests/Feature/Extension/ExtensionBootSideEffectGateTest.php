<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\BasePluginServiceProvider;
use App\Extension\PluginManager;
use App\Models\Plugin;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 비활성 확장의 boot 부수효과 게이트 (#860 W4, #730 결정)
 *
 * 비활성 확장도 provider 를 등록해 바인딩·번역은 쓸 수 있게 두되(설치 시더·관리 화면이 쓴다), 요청 처리에 끼어드는
 * boot 부수효과(레이트리미터·쿠키 예외·큐 훅 등)는 활성일 때만 건다. 상태를 알 수 없으면 지금처럼 건다.
 */
class ExtensionBootSideEffectGateTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 설치 완료 상태로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.installer_completed' => true]);
        PluginManager::invalidatePluginStatusCache();
    }

    /**
     * 설치 완료 플래그·환경 플래그를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        config(['app.installer_completed' => false]);
        putenv('G7_UPDATE_IN_PROGRESS');
        unset($_ENV['G7_UPDATE_IN_PROGRESS'], $_SERVER['G7_UPDATE_IN_PROGRESS']);

        parent::tearDown();
    }

    /**
     * 시험용 플러그인 행을 만듭니다.
     *
     * @param  ExtensionStatus  $status  상태
     * @return void 반환값 없음
     */
    private function recordPlugin(ExtensionStatus $status): void
    {
        Plugin::query()->where('identifier', 'w4-gate-test')->delete();
        Plugin::factory()->create(['identifier' => 'w4-gate-test', 'status' => $status->value]);
        PluginManager::invalidatePluginStatusCache();
    }

    /**
     * 번들 확장과 같은 방식으로 게이트를 쓰는 시험용 provider 를 등록·부팅합니다.
     *
     * @param  string  $limiter  등록할 레이트리미터 이름
     * @return BasePluginServiceProvider 부팅된 provider
     */
    private function bootProvider(string $limiter): BasePluginServiceProvider
    {
        $provider = new class($this->app, $limiter) extends BasePluginServiceProvider
        {
            protected string $pluginIdentifier = 'w4-gate-test';

            /**
             * 앱과 시험용 레이트리미터 이름을 받습니다.
             *
             * @param  Application  $app  앱
             * @param  string  $limiter  레이트리미터 이름
             */
            public function __construct($app, private string $limiter)
            {
                parent::__construct($app);
            }

            /**
             * 바인딩(선언)은 상태와 무관하게 등록합니다.
             *
             * @return void 반환값 없음
             */
            public function register(): void
            {
                parent::register();
                $this->app->bind('w4-gate-test.binding', fn () => 'bound');
            }

            /**
             * 요청 처리에 끼어드는 부수효과는 활성일 때만 등록합니다.
             *
             * @return void 반환값 없음
             */
            public function boot(): void
            {
                parent::boot();

                if (! method_exists($this, 'isExtensionActive') || $this->isExtensionActive()) {
                    \Illuminate\Support\Facades\RateLimiter::for($this->limiter, fn () => Limit::perMinute(1));
                    EncryptCookies::except([$this->limiter.'_cookie']);
                }
            }

            /**
             * 게이트 판정을 밖에서 읽습니다.
             *
             * @return bool 활성 판정
             */
            public function gate(): bool
            {
                return $this->isExtensionActive();
            }
        };

        $provider->register();
        $provider->boot();

        return $provider;
    }

    /**
     * 비활성 플러그인은 바인딩은 해석되고 레이트리미터·쿠키 암호화 예외는 등록되지 않습니다.
     *
     * @effects inactive_extension_keeps_bindings_without_request_side_effects
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비활성_확장은_바인딩만_있고_부수효과는_없다(): void
    {
        $this->recordPlugin(ExtensionStatus::Inactive);

        $this->bootProvider('w4-gate-inactive');

        $this->assertSame('bound', $this->app->make('w4-gate-test.binding'));
        $this->assertNull(app(RateLimiter::class)->limiter('w4-gate-inactive'));
        $this->assertFalse(app(EncryptCookies::class)->isDisabled('w4-gate-inactive_cookie'));
    }

    /**
     * 활성화(상태 쓰기 + 무효화) 뒤 처음 부팅하면 부수효과가 등록됩니다.
     *
     * @effects side_effects_register_on_first_boot_after_activation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 활성화_뒤_첫_부팅에서_부수효과가_등록된다(): void
    {
        $this->recordPlugin(ExtensionStatus::Inactive);
        $this->bootProvider('w4-gate-before');
        $this->assertNull(app(RateLimiter::class)->limiter('w4-gate-before'));
        $this->assertFalse(app(EncryptCookies::class)->isDisabled('w4-gate-before_cookie'));

        Plugin::query()->where('identifier', 'w4-gate-test')->update(['status' => ExtensionStatus::Active->value]);
        PluginManager::invalidatePluginStatusCache();

        // 다음 요청(새 부팅)
        $this->app->forgetScopedInstances();
        $this->bootProvider('w4-gate-after');
        $this->assertNotNull(app(RateLimiter::class)->limiter('w4-gate-after'));
        $this->assertTrue(app(EncryptCookies::class)->isDisabled('w4-gate-after_cookie'));
    }

    /**
     * 상태를 알 수 없으면(설치 전·스키마 변경 명령·코어 업데이트 중·조회 실패) 지금처럼 활성으로 봅니다.
     *
     * @effects unknown_extension_state_defaults_to_active
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 상태를_모르면_활성으로_본다(): void
    {
        $this->recordPlugin(ExtensionStatus::Inactive);
        $provider = $this->bootProvider('w4-gate-unknown');
        $this->assertFalse($provider->gate(), '확인 가능한 비활성은 false');

        config(['app.installer_completed' => false]);
        $this->assertTrue($provider->gate(), '설치 미완료');
        config(['app.installer_completed' => true]);

        $argv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['artisan', 'migrate'];
        try {
            $this->assertTrue($provider->gate(), '스키마 변경 명령');
        } finally {
            $_SERVER['argv'] = $argv;
        }

        putenv('G7_UPDATE_IN_PROGRESS=1');
        $_ENV['G7_UPDATE_IN_PROGRESS'] = '1';
        $this->assertTrue($provider->gate(), '코어 업데이트 중');
        putenv('G7_UPDATE_IN_PROGRESS');
        unset($_ENV['G7_UPDATE_IN_PROGRESS']);

        // 조회 실패 — 테이블이 없는 연결처럼 예외가 나면 true
        config(['database.connections.w4_broken' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'none', 'username' => 'none', 'password' => '']]);
        $default = config('database.default');
        config(['database.default' => 'w4_broken']);
        PluginManager::invalidatePluginStatusCache();
        try {
            $this->assertTrue($provider->gate(), '조회 실패');
        } finally {
            config(['database.default' => $default]);
        }
    }
}
