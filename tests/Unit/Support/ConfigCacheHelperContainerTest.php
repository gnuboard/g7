<?php

namespace Tests\Unit\Support;

use App\Support\ConfigCacheHelper;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `ConfigCacheHelper` 가 `config:cache` 뒤 전역 컨테이너 인스턴스를 되돌리는지 (#651 F2 실측 파생).
 *
 * `config:cache` 는 새 Application 을 부팅하고 그 생성자가 `Container::setInstance()` 를 호출한다.
 * 되돌리지 않으면 같은 프로세스의 후속 `app()->terminating()` 이 종료되지 않는 일회용 앱에 등록되어
 * 정적 재게시 예약이 조용히 사라진다 — 예외도 로그도 없이 "다음 렌더에야 반영" 으로만 나타난다.
 */
class ConfigCacheHelperContainerTest extends TestCase
{
    /**
     * 콜백이 전역 인스턴스를 바꿔 놓아도 실행 후 원래 앱으로 돌아온다.
     *
     * @effects config_cache_rebuild_preserves_container_instance
     */
    #[Test]
    public function 콜백이_전역_인스턴스를_바꿔도_원래_앱으로_되돌린다(): void
    {
        $original = Container::getInstance();
        $stray = new Container;

        ConfigCacheHelper::withPreservedContainer(static function () use ($stray): void {
            Container::setInstance($stray);
        });

        $this->assertSame($original, Container::getInstance(), 'config:cache 뒤 app() 이 일회용 앱을 가리킨 채 남는다 — 후속 terminating 예약이 사라진다');
        $this->assertSame($original, app());
    }

    /**
     * 콜백이 예외를 던져도 인스턴스는 되돌린다.
     *
     * @effects config_cache_rebuild_preserves_container_instance
     */
    #[Test]
    public function 콜백이_실패해도_원래_앱으로_되돌린다(): void
    {
        $original = Container::getInstance();

        try {
            ConfigCacheHelper::withPreservedContainer(static function (): void {
                Container::setInstance(new Container);

                throw new \RuntimeException('boom');
            });
            $this->fail('예외가 전파되어야 한다');
        } catch (\RuntimeException) {
            // 기대한 예외
        }

        $this->assertSame($original, Container::getInstance());
    }

    /**
     * 콜백이 파사드 애플리케이션을 바꿔 놓아도 실행 후 원래 앱으로 돌아온다.
     *
     * `config:cache` 가 부팅하는 일회용 앱의 `registerBaseBindings()` 는 컨테이너 인스턴스만이 아니라
     * `Facade::clearResolvedInstances()` + `Facade::setFacadeApplication()` 도 호출한다. 컨테이너만
     * 되돌리면 그 뒤의 **모든 파사드 호출**(`Artisan`·`Log`·`DB`·`Cache` …)이 종료된 일회용 앱에서
     * 인스턴스를 해석한다.
     *
     * 코어 업데이트에서 드러난 형태: Step 11 의 `ConfigCacheHelper::rebuild()` 뒤에 오는
     * `RouteCacheHelper::rebuild()` 의 `Artisan::call('route:clear')` 가 일회용 앱에서 **새 콘솔 Kernel**
     * 을 해석하고, 그 Kernel 이 콘솔 Application 을 처음부터 구성하며 등록된 커맨드를 전부 resolve 해
     * `Target class [command.tinker] does not exist.` 로 업데이트가 실패·롤백했다 (2026-09-07 실측).
     *
     * @effects config_cache_rebuild_preserves_facade_application
     */
    #[Test]
    public function 콜백이_파사드_애플리케이션을_바꿔도_원래_앱으로_되돌린다(): void
    {
        $original = Facade::getFacadeApplication();
        $this->assertNotNull($original, '전제: 파사드 애플리케이션이 설정되어 있다');

        ConfigCacheHelper::withPreservedContainer(static function (): void {
            // 일회용 앱 부팅이 하는 일과 같은 형태 — 해석 캐시를 비우고 다른 앱을 가리킨다.
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(new Container);
        });

        $this->assertSame(
            $original,
            Facade::getFacadeApplication(),
            'config:cache 뒤 파사드가 일회용 앱을 가리킨 채 남으면 후속 Artisan::call 이 새 콘솔 Kernel 을 만든다'
        );
    }

    /**
     * 콜백이 예외를 던져도 파사드 애플리케이션은 되돌린다.
     *
     * @effects config_cache_rebuild_preserves_facade_application
     */
    #[Test]
    public function 콜백이_실패해도_파사드_애플리케이션을_되돌린다(): void
    {
        $original = Facade::getFacadeApplication();

        try {
            ConfigCacheHelper::withPreservedContainer(static function (): void {
                Facade::setFacadeApplication(new Container);

                throw new \RuntimeException('boom');
            });
            $this->fail('예외가 전파되어야 한다');
        } catch (\RuntimeException) {
            // 기대한 예외
        }

        $this->assertSame($original, Facade::getFacadeApplication());
    }

    /**
     * 되돌린 뒤 파사드가 원래 앱의 인스턴스를 해석한다 (해석 캐시 오염 없음).
     *
     * @effects config_cache_rebuild_preserves_facade_application
     */
    #[Test]
    public function 되돌린_뒤_파사드가_원래_앱의_인스턴스를_해석한다(): void
    {
        $original = Facade::getFacadeApplication();
        $kernelBefore = app(Kernel::class);

        ConfigCacheHelper::withPreservedContainer(static function (): void {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(new Container);
        });

        $this->assertSame($original, Facade::getFacadeApplication());
        $this->assertSame($kernelBefore, app(Kernel::class), '콘솔 Kernel 이 새로 만들어지면 등록된 커맨드를 전부 다시 resolve 한다');
    }

    /**
     * `rebuild()` 가 `config:cache` 호출을 보존 래퍼로 감싼다 (소스 구조).
     *
     * @effects config_cache_rebuild_preserves_container_instance
     */
    #[Test]
    public function rebuild_는_config_cache_를_보존_래퍼로_감싼다(): void
    {
        $method = new \ReflectionMethod(ConfigCacheHelper::class, 'rebuild');
        $lines = file($method->getFileName()) ?: [];
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringContainsString("withPreservedContainer(static fn () => Artisan::call('config:cache'))", $body);
        $this->assertStringNotContainsString("\n            Artisan::call('config:cache');", $body);
    }
}
