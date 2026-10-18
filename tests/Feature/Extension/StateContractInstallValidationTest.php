<?php

namespace Tests\Feature\Extension;

use App\Exceptions\StateContractConflictException;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Traits\ValidatesStateContracts;
use App\Support\StateContracts;
use Mockery;
use Tests\TestCase;

/**
 * 설치·활성화 때 전역 상태 공개 계약 이름 충돌 검사 (#639, 결정 P8)
 *
 * 두 확장이 같은 전역 상태를 「내 것」이라고 선언하면, 한쪽이 쓴 값을 다른 쪽이 자기 모양으로 읽어 조용히
 * 깨진다. 모듈·플러그인 매니저는 설치(SEO 변수 검사 옆)와 활성화 때, 템플릿 매니저는 활성화 때 이 검사를
 * 부른다. 여기서는 매니저가 쓰는 트레이트 판정을 활성 확장 모집단을 흉내 내 직접 잰다.
 */
class StateContractInstallValidationTest extends TestCase
{
    /**
     * 활성 모듈 acme-shop(preferredCurrency 소유)과 활성 사용자 템플릿 old-theme(shopBase 소유)을 흉내 냅니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $module = new class
        {
            /**
             * 식별자를 반환합니다.
             *
             * @return string 식별자
             */
            public function getIdentifier(): string
            {
                return 'acme-shop';
            }

            /**
             * 상태 계약을 반환합니다.
             *
             * @return array<string, mixed> 선언
             */
            public function getStateContracts(): array
            {
                return StateContracts::normalize(['preferredCurrency' => ['schema' => ['type' => 'string']]]);
            }
        };

        $moduleManager = Mockery::mock(ModuleManager::class);
        $moduleManager->shouldReceive('getActiveModules')->andReturn([$module]);
        $this->app->instance(ModuleManager::class, $moduleManager);

        $pluginManager = Mockery::mock(PluginManager::class);
        $pluginManager->shouldReceive('getActivePlugins')->andReturn([]);
        $this->app->instance(PluginManager::class, $pluginManager);

        $templateManager = Mockery::mock(TemplateManager::class);
        $templateManager->shouldReceive('getActiveTemplate')->with('admin')->andReturn(null);
        $templateManager->shouldReceive('getActiveTemplate')->with('user')->andReturn([
            'identifier' => 'old-theme',
            'state_contracts' => ['shopBase' => ['schema' => ['type' => 'string']]],
        ]);
        $this->app->instance(TemplateManager::class, $templateManager);
    }

    /**
     * 트레이트 판정을 부릅니다.
     *
     * @param  string  $owner  선언하는 확장의 소유자 키
     * @param  array<string, mixed>  $declared  선언
     * @param  array<int, string>  $ignored  검사에서 뺄 소유자
     * @return void 반환값 없음
     */
    private function validate(string $owner, array $declared, array $ignored = []): void
    {
        $validator = new class
        {
            use ValidatesStateContracts;

            /**
             * 트레이트의 보호 메서드를 엽니다.
             *
             * @param  string  $owner  소유자 키
             * @param  array<string, mixed>  $declared  선언
             * @param  array<int, string>  $ignored  뺄 소유자
             * @return void 반환값 없음
             */
            public function run(string $owner, array $declared, array $ignored): void
            {
                $this->validateStateContracts($owner, $declared, $ignored);
            }
        };

        $validator->run($owner, StateContracts::normalize($declared), $ignored);
    }

    /**
     * 다른 활성 확장이 소유한 이름을 선언하면 설치가 막히고 메시지에 소유자가 있다.
     *
     * @scenario case=foreign_owned
     *
     * @effects conflicting_declaration_blocks_install_with_owner_in_message
     *
     * @return void 반환값 없음
     */
    public function test_declaring_a_name_owned_by_another_extension_fails(): void
    {
        try {
            $this->validate('plugin:acme-pay', ['preferredCurrency' => []]);
            $this->fail('충돌 선언이 통과했습니다.');
        } catch (StateContractConflictException $e) {
            $this->assertStringContainsString('module:acme-shop', $e->getMessage());
            $this->assertStringContainsString('preferredCurrency', $e->getMessage());
        }
    }

    /**
     * 코어 예약 이름은 어느 확장도 선언할 수 없다.
     *
     * @scenario case=core_reserved
     *
     * @effects core_reserved_name_cannot_be_declared
     *
     * @return void 반환값 없음
     */
    public function test_core_reserved_name_cannot_be_declared(): void
    {
        $this->expectException(StateContractConflictException::class);
        $this->expectExceptionMessage('core');

        $this->validate('plugin:acme-consent', ['consent' => []]);
    }

    /**
     * 자기 자신의 재설치·재활성화는 막지 않는다.
     *
     * @scenario case=own
     *
     * @effects reinstall_of_same_owner_passes
     *
     * @return void 반환값 없음
     */
    public function test_reinstalling_the_same_owner_passes(): void
    {
        $this->validate('module:acme-shop', ['preferredCurrency' => []]);

        $this->addToAssertionCount(1);
    }

    /**
     * 같은 유형의 활성 템플릿을 갈아끼우면 교체될 템플릿의 선언은 검사에서 뺀다.
     *
     * @scenario case=replaced_template
     *
     * @effects replacing_template_is_excluded_from_conflict_check
     *
     * @return void 반환값 없음
     */
    public function test_replaced_template_declarations_are_ignored(): void
    {
        $this->validate('template:new-theme', ['shopBase' => []], ['template:old-theme']);
        $this->addToAssertionCount(1);

        $this->expectException(StateContractConflictException::class);
        $this->validate('template:new-theme', ['shopBase' => []]);
    }
}
