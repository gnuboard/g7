<?php

namespace Modules\Sirsoft\Ecommerce\TestFixtures\Listeners {

    use App\ActivityLog\Traits\ResolvesActivityLogType;

    /**
     * 모듈 namespace 의 fake listener.
     *
     * `ExtensionManager::resolveExtensionByFqcn()` 가 본 FQCN 을
     * `sirsoft-ecommerce` 로 해석하는 것을 활용하여 trait 의 origin 자동 주입을 검증.
     */
    class FakeEcommerceListener
    {
        use ResolvesActivityLogType;

        public function fire(string $action, array $context = []): void
        {
            $this->logActivity($action, $context);
        }
    }
}

namespace App\TestFixtures\CoreListeners {

    use App\ActivityLog\Traits\ResolvesActivityLogType;

    /**
     * 코어 namespace 의 fake listener — origin 미주입 검증용.
     */
    class FakeCoreListener
    {
        use ResolvesActivityLogType;

        public function fire(string $action, array $context = []): void
        {
            $this->logActivity($action, $context);
        }
    }
}

namespace Tests\Unit\ActivityLog {

    use App\TestFixtures\CoreListeners\FakeCoreListener;
    use Illuminate\Support\Facades\Log;
    use Mockery;
    use Modules\Sirsoft\Ecommerce\TestFixtures\Listeners\FakeEcommerceListener;
    use Psr\Log\LoggerInterface;
    use Tests\TestCase;

    /**
     * ResolvesActivityLogType trait 의 properties.extension_origin 자동 주입 검증.
     *
     * 호출 클래스 FQCN 이 모듈/플러그인 namespace 일 때만 origin 이 주입되며,
     * 코어 클래스는 주입하지 않음 (코어 lang fallback 으로 충분).
     */
    class ResolvesActivityLogTypeOriginTest extends TestCase
    {
        protected function tearDown(): void
        {
            Mockery::close();
            parent::tearDown();
        }

        public function test_module_listener_injects_extension_origin_into_properties(): void
        {
            $captured = null;

            $logChannel = Mockery::mock(LoggerInterface::class);
            Log::shouldReceive('channel')->with('activity')->andReturn($logChannel);
            $logChannel->shouldReceive('info')
                ->once()
                ->withArgs(function (string $action, array $context) use (&$captured) {
                    $captured = $context;

                    return $action === 'mileage.earn';
                });

            (new FakeEcommerceListener)->fire('mileage.earn');

            $this->assertIsArray($captured);
            $this->assertSame('sirsoft-ecommerce', $captured['properties']['extension_origin']);
        }

        public function test_core_listener_does_not_inject_extension_origin(): void
        {
            $captured = null;

            $logChannel = Mockery::mock(LoggerInterface::class);
            Log::shouldReceive('channel')->with('activity')->andReturn($logChannel);
            $logChannel->shouldReceive('info')
                ->once()
                ->withArgs(function (string $action, array $context) use (&$captured) {
                    $captured = $context;

                    return true;
                });

            (new FakeCoreListener)->fire('user.create');

            $this->assertIsArray($captured);
            $this->assertArrayNotHasKey('extension_origin', $captured['properties'] ?? []);
        }

        public function test_explicit_extension_origin_is_preserved(): void
        {
            $captured = null;

            $logChannel = Mockery::mock(LoggerInterface::class);
            Log::shouldReceive('channel')->with('activity')->andReturn($logChannel);
            $logChannel->shouldReceive('info')
                ->once()
                ->withArgs(function (string $action, array $context) use (&$captured) {
                    $captured = $context;

                    return true;
                });

            (new FakeEcommerceListener)->fire('mileage.earn', [
                'properties' => ['extension_origin' => 'manual-override'],
            ]);

            $this->assertSame('manual-override', $captured['properties']['extension_origin']);
        }

        public function test_module_listener_preserves_existing_properties(): void
        {
            $captured = null;

            $logChannel = Mockery::mock(LoggerInterface::class);
            Log::shouldReceive('channel')->with('activity')->andReturn($logChannel);
            $logChannel->shouldReceive('info')
                ->once()
                ->withArgs(function (string $action, array $context) use (&$captured) {
                    $captured = $context;

                    return true;
                });

            (new FakeEcommerceListener)->fire('mileage.earn', [
                'properties' => ['extra' => 'value'],
            ]);

            $this->assertSame('sirsoft-ecommerce', $captured['properties']['extension_origin']);
            $this->assertSame('value', $captured['properties']['extra']);
        }
    }
}
