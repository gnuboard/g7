<?php

namespace Tests\Unit\Support;

use App\Support\ConfigCacheHelper;
use Illuminate\Container\Container;
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
