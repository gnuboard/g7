<?php

namespace Tests\Feature\Analytics\Concerns;

use App\Contracts\Analytics\DimensionRegistryInterface;
use App\Contracts\Analytics\GoalRegistryInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;

/**
 * 방문자 통계 확장 선언을 가짜 확장으로 주입합니다.
 *
 * 등록부는 활성 모듈·플러그인의 `getAnalyticsGoals()`·`getAnalyticsDimensions()` 를 모읍니다.
 * 실제 번들 확장은 아직 선언하지 않으므로(전수 0건) 테스트는 관리자 계약을 가짜로 바꿔
 * 선언을 넣습니다. 등록부는 요청 수명 동안 결과를 들고 있으므로 바꿀 때마다 비웁니다.
 */
trait FakesAnalyticsExtensions
{
    /**
     * 선언을 가진 가짜 확장을 만듭니다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  array<int, array<string, mixed>>|\Closure  $goals  목표 선언 (던지게 하려면 클로저)
     * @param  array<int, array<string, mixed>>  $dimensions  차원 선언
     * @param  array<string, \Closure>  $methods  추가 메서드 (라벨 풀이 등)
     * @return object 가짜 확장
     */
    protected function fakeAnalyticsExtension(string $identifier, array|\Closure $goals = [], array $dimensions = [], array $methods = []): object
    {
        return new class($identifier, $goals, $dimensions, $methods)
        {
            /**
             * 가짜 확장을 만듭니다.
             *
             * @param  string  $identifier  식별자
             * @param  array<int, array<string, mixed>>|\Closure  $goals  목표 선언
             * @param  array<int, array<string, mixed>>  $dimensions  차원 선언
             * @param  array<string, \Closure>  $methods  추가 메서드
             */
            public function __construct(
                private string $identifier,
                private array|\Closure $goals,
                private array $dimensions,
                private array $methods,
            ) {}

            /**
             * 확장 식별자를 반환합니다.
             *
             * @return string 식별자
             */
            public function getIdentifier(): string
            {
                return $this->identifier;
            }

            /**
             * 목표 선언을 반환합니다.
             *
             * @return array<int, array<string, mixed>> 목표 선언
             */
            public function getAnalyticsGoals(): array
            {
                return $this->goals instanceof \Closure ? ($this->goals)() : $this->goals;
            }

            /**
             * 차원 선언을 반환합니다.
             *
             * @return array<int, array<string, mixed>> 차원 선언
             */
            public function getAnalyticsDimensions(): array
            {
                return $this->dimensions;
            }

            /**
             * 라벨 풀이 함수 이름 → 주입한 클로저.
             *
             * @param  array<int, string>  $values  원값
             * @return array<string, string> 원값 → 이름
             */
            public function resolveLabels(array $values): array
            {
                return isset($this->methods['resolveLabels']) ? ($this->methods['resolveLabels'])($values) : [];
            }
        };
    }

    /**
     * 활성 확장 목록을 바꾸고 등록부를 비웁니다.
     *
     * @param  array<int, object>  $modules  활성 모듈
     * @param  array<int, object>  $plugins  활성 플러그인
     * @return void 없음
     */
    protected function activateAnalyticsExtensions(array $modules, array $plugins = []): void
    {
        $moduleManager = \Mockery::mock(ModuleManagerInterface::class)->shouldIgnoreMissing();
        $moduleManager->shouldReceive('getActiveModules')->andReturn($modules);

        $pluginManager = \Mockery::mock(PluginManagerInterface::class)->shouldIgnoreMissing();
        $pluginManager->shouldReceive('getActivePlugins')->andReturn($plugins);

        $this->app->instance(ModuleManagerInterface::class, $moduleManager);
        $this->app->instance(PluginManagerInterface::class, $pluginManager);
        $this->app->forgetInstance(GoalRegistryInterface::class);
        $this->app->forgetInstance(DimensionRegistryInterface::class);
        $this->app->forgetScopedInstances();
    }

    /**
     * 주문 완료 목표와 게시판 차원을 선언한 이커머스·게시판 가짜 확장을 켭니다.
     *
     * @return void 없음
     */
    protected function activateSampleAnalyticsExtensions(): void
    {
        $this->activateAnalyticsExtensions([
            $this->fakeAnalyticsExtension('sirsoft-ecommerce', [[
                'key' => 'order_completed',
                'label' => ['ko' => '주문 완료', 'en' => 'Order completed'],
                'category' => 'purchase',
                'dedupe' => 'every_time',
                'attributes' => [
                    'revenue' => ['type' => 'decimal', 'min' => 0],
                    'currency' => ['type' => 'string', 'max' => 10],
                    'label' => ['type' => 'string', 'max' => 255],
                ],
            ]]),
            $this->fakeAnalyticsExtension('sirsoft-board', [], [[
                'key' => 'board_id',
                'scope' => 'action',
                'label' => ['ko' => '게시판', 'en' => 'Board'],
                'value_type' => 'int',
            ]]),
        ]);
    }
}
