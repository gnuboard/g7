<?php

namespace Tests\Feature\Services;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

/**
 * 템플릿 조작 실패 문구에 원인이 자리표시자 대신 실제 값으로 들어가는지 검증합니다 (#703 브라우저 실측).
 *
 * 관리자 템플릿 목록에서 진행 중인 템플릿을 켜자 토스트에 「템플릿 활성화에 실패했습니다: :error: "…"」가
 * 떴다. 서비스가 `:error` 자리에 값을 넘기지 않고 번역 문구 뒤에 원인을 이어 붙였기 때문이다. 모듈 서비스는
 * `__('modules.activation_failed', ['error' => …])` 로 자리에 넣는다 — 같은 방식으로 맞춘다.
 *
 * 시나리오 매니페스트: tests/scenarios/template-service-lifecycle.yaml
 */
class TemplateServiceFailureMessageTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 테스트가 바인딩한 Mockery 목을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 모든 조작에서 같은 원인으로 실패하는 템플릿 매니저 목을 바인딩합니다.
     *
     * @return void 반환값 없음
     */
    private function bindFailingManager(): void
    {
        $mock = Mockery::mock(TemplateManagerInterface::class);
        $mock->shouldReceive('loadTemplates')->andReturnNull();
        $mock->shouldReceive('ensureLoaded')->andReturnNull();
        $mock->shouldReceive('getTemplateInfo')->andReturn(null);
        foreach (['installTemplate', 'activateTemplate', 'deactivateTemplate', 'uninstallTemplate', 'refreshTemplateLayouts'] as $method) {
            $mock->shouldReceive($method)->andThrow(new \RuntimeException('boom'));
        }

        $this->app->instance(TemplateManagerInterface::class, $mock);
    }

    /**
     * 같은 유형의 활성 템플릿 두 개를 두고 첫 번째를 돌려줍니다 — 「마지막 활성」 가드를 지나 매니저까지 가게 한다.
     *
     * @return Template 조작 대상 템플릿
     */
    private function arrangeTarget(): Template
    {
        Template::query()->where('type', 'user')->update(['status' => ExtensionStatus::Inactive->value]);

        $target = Template::factory()->create([
            'identifier' => 'failmsg-a'.substr(uniqid(), -6),
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);
        Template::factory()->create([
            'identifier' => 'failmsg-b'.substr(uniqid(), -6),
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);

        return $target;
    }

    /**
     * 조작을 실행하고 검증 예외의 첫 문구를 돌려줍니다.
     *
     * @param  callable  $operation  실패해야 하는 서비스 호출
     * @return string 첫 오류 문구
     */
    private function failureMessageOf(callable $operation): string
    {
        try {
            $operation();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        $this->fail('조작이 실패하지 않았습니다.');
    }

    /**
     * 설치·활성화·비활성화·삭제·레이아웃 갱신 실패 문구는 `:error` 자리에 원인을 넣고, 자리표시자를 남기지 않는다.
     *
     * @scenario operation=failure_message
     *
     * @effects template_failure_message_fills_error_placeholder
     *
     * @return void 반환값 없음
     */
    public function test_failure_messages_fill_the_error_placeholder_with_the_cause(): void
    {
        $this->bindFailingManager();
        $service = app(TemplateService::class);
        $target = $this->arrangeTarget();

        $cases = [
            'templates.errors.installation_failed' => fn () => $service->installTemplate($target->identifier),
            'templates.errors.activation_failed' => fn () => $service->activateTemplate($target->identifier),
            'templates.errors.deactivation_failed' => fn () => $service->deactivateTemplate($target->identifier),
            'templates.errors.uninstallation_failed' => fn () => $service->uninstallTemplate($target->identifier),
            'templates.errors.refresh_layouts_failed' => fn () => $service->refreshTemplateLayouts($target->identifier),
        ];

        foreach ($cases as $key => $operation) {
            $message = $this->failureMessageOf($operation);

            $this->assertSame(__($key, ['error' => 'boom']), $message, "{$key} 문구가 원인을 자리에 넣지 않았습니다.");
            $this->assertStringNotContainsString(':error', $message, "{$key} 문구에 자리표시자가 남았습니다.");
        }
    }
}
