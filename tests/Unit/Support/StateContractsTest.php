<?php

namespace Tests\Unit\Support;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Support\StateContracts;
use Mockery;
use Tests\TestCase;

/**
 * 전역 상태 공개 계약 집계 (#639, 결정 P8·D13)
 *
 * 코어 예약 계약과 활성 확장 manifest 의 `state_contracts` 를 모아 프론트(`G7Core.stateContracts`)와 설치 충돌
 * 검사가 함께 쓴다. 집계가 한 소스의 실패로 통째로 비면 프론트는 계약이 없는 것으로 읽는다.
 */
class StateContractsTest extends TestCase
{
    /**
     * 활성 확장을 흉내 낸 매니저 세 개를 컨테이너에 넣습니다.
     *
     * @param  array<string, array<string, mixed>>  $modules  모듈 id → 선언
     * @param  array<string, array<string, mixed>>|\Throwable  $plugins  플러그인 id → 선언 (예외면 집계 실패 흉내)
     * @param  array<string, mixed>|null  $userTemplate  사용자 템플릿 manifest
     * @return void 반환값 없음
     */
    private function fakeActiveExtensions(array $modules, array|\Throwable $plugins, ?array $userTemplate = null): void
    {
        $extension = fn (string $id, array $declared) => new class($id, $declared)
        {
            /**
             * 가짜 확장을 만듭니다.
             *
             * @param  string  $id  식별자
             * @param  array<string, mixed>  $declared  선언
             */
            public function __construct(private string $id, private array $declared) {}

            /**
             * 식별자를 반환합니다.
             *
             * @return string 식별자
             */
            public function getIdentifier(): string
            {
                return $this->id;
            }

            /**
             * 선언을 정규화해 반환합니다.
             *
             * @return array<string, mixed> 선언
             */
            public function getStateContracts(): array
            {
                return StateContracts::normalize($this->declared);
            }
        };

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn(array_map($extension, array_keys($modules), $modules));
        $this->app->instance(ModuleManager::class, $moduleManager);

        $pluginManager = Mockery::mock(PluginManager::class);
        if ($plugins instanceof \Throwable) {
            $pluginManager->shouldReceive('getActivePlugins')->andThrow($plugins);
        } else {
            $pluginManager->shouldReceive('getActivePlugins')->andReturn(array_map($extension, array_keys($plugins), $plugins));
        }
        $this->app->instance(PluginManager::class, $pluginManager);

        $templateManager = Mockery::mock(TemplateManager::class);
        $templateManager->shouldReceive('getActiveTemplate')->with('admin')->andReturn(null);
        $templateManager->shouldReceive('getActiveTemplate')->with('user')->andReturn($userTemplate);
        $this->app->instance(TemplateManager::class, $templateManager);
    }

    /**
     * 코어 예약 계약과 활성 확장 선언을 소유자와 함께 모은다.
     *
     * @scenario case=aggregate
     *
     * @effects contracts_are_aggregated_with_owner
     *
     * @return void 반환값 없음
     */
    public function test_aggregates_core_and_active_extension_contracts(): void
    {
        $this->fakeActiveExtensions(
            ['acme-shop' => ['preferredCurrency' => ['description' => ['ko' => '통화'], 'schema' => ['type' => 'string'], 'writers' => ['@template']]]],
            [],
            ['identifier' => 'acme-theme', 'state_contracts' => ['shopBase' => ['schema' => ['type' => 'string']]]],
        );

        $all = StateContracts::all();

        $this->assertSame('core', $all['consent']['owner']);
        $this->assertSame('core', $all['identityChallenge']['owner']);
        $this->assertSame(['@template'], $all['currentUser']['writers']);
        $this->assertSame('module:acme-shop', $all['preferredCurrency']['owner']);
        $this->assertSame(['@template'], $all['preferredCurrency']['writers']);
        $this->assertSame('template:acme-theme', StateContracts::ownerOf('shopBase'));
        $this->assertNull(StateContracts::ownerOf('nobody'));
    }

    /**
     * 코어 예약 이름은 D13 목록 그대로다.
     *
     * @scenario case=core_reserved_list
     *
     * @effects core_reserved_contracts_are_fixed
     *
     * @return void 반환값 없음
     */
    public function test_core_reserved_names(): void
    {
        $this->assertSame(
            ['consent', 'identityChallenge', 'currentUser', 'settings', 'plugins', 'modules', 'appConfig'],
            array_keys(StateContracts::CORE_CONTRACTS),
        );
    }

    /**
     * 소스 하나가 실패해도 나머지는 집계한다.
     *
     * @scenario case=failing_source
     *
     * @effects aggregation_survives_one_failing_source
     *
     * @return void 반환값 없음
     */
    public function test_one_failing_source_does_not_empty_the_aggregate(): void
    {
        $this->fakeActiveExtensions(
            ['acme-shop' => ['preferredCurrency' => ['schema' => ['type' => 'string']]]],
            new \RuntimeException('plugin table missing'),
        );

        $all = StateContracts::all();

        $this->assertArrayHasKey('preferredCurrency', $all);
        $this->assertArrayHasKey('consent', $all);
    }

    /**
     * 형식이 틀린 선언(이름 규칙 위반·정의가 배열 아님)은 버리고 writers 는 문자열만 남긴다.
     *
     * @scenario case=malformed
     *
     * @effects malformed_declarations_are_dropped
     *
     * @return void 반환값 없음
     */
    public function test_normalize_drops_malformed_entries(): void
    {
        $normalized = StateContracts::normalize([
            'goodName' => ['description' => '설명', 'writers' => ['@template', 3, '']],
            'Bad-Name' => ['schema' => []],
            '_private' => [],
            'notArray' => 'x',
        ]);

        $this->assertSame(['goodName'], array_keys($normalized));
        $this->assertSame(['ko' => '설명'], $normalized['goodName']['description']);
        $this->assertSame(['@template'], $normalized['goodName']['writers']);
        $this->assertSame([], StateContracts::normalize('nope'));
    }
}
