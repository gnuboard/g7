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

        /**
         * 넘겨받은 액션과 컨텍스트로 트레이트의 활동 로그 기록을 호출한다.
         *
         * @param  string  $action  기록할 액션 키
         * @param  array  $context  로그 컨텍스트
         * @return void 반환값 없음
         */
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

        /**
         * 넘겨받은 액션과 컨텍스트로 트레이트의 활동 로그 기록을 호출한다.
         *
         * @param  string  $action  기록할 액션 키
         * @param  array  $context  로그 컨텍스트
         * @return void 반환값 없음
         */
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
        /**
         * Mockery 기대를 검증하고 닫은 뒤 부모 정리를 실행한다.
         *
         * @return void 반환값 없음
         */
        protected function tearDown(): void
        {
            try {
                Mockery::close();
            } finally {
                parent::tearDown();
            }
        }

        /**
         * 모듈 네임스페이스의 리스너가 기록하면 properties.extension_origin 에 모듈 식별자가 자동으로 들어간다.
         *
         * @scenario origin_type=module, loggable_state=properties_origin_empty
         *
         * @effects module_listener_auto_injects_extension_origin
         *
         * @return void 반환값 없음
         */
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

        /**
         * 코어 네임스페이스의 리스너가 기록하면 properties 에 extension_origin 이 들어가지 않는다.
         *
         * @scenario origin_type=core, loggable_state=properties_origin_empty
         *
         * @effects core_listener_does_not_inject_extension_origin_into_properties
         *
         * @return void 반환값 없음
         */
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

        /**
         * 호출자가 직접 넣은 extension_origin 은 자동 주입이 덮어쓰지 않는다.
         *
         * @scenario origin_type=module, loggable_state=properties_origin_set
         *
         * @effects explicit_extension_origin_in_properties_preserved_over_auto_resolve
         *
         * @return void 반환값 없음
         */
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

        /**
         * 자동 주입이 호출자가 넘긴 다른 properties 값을 지우지 않는다.
         *
         * @scenario origin_type=module, loggable_state=properties_origin_empty
         *
         * @effects module_listener_preserves_user_supplied_properties
         *
         * @return void 반환값 없음
         */
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
