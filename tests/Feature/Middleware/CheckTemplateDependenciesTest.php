<?php

namespace Tests\Feature\Middleware;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\ExtensionStatus;
use App\Extension\TemplateManager;
use App\Http\Middleware\CheckTemplateDependencies;
use App\Models\Module;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 템플릿 의존 미들웨어 — 버전 미달 503 데이터·업데이트 중 완화 (#152)
 *
 * 503 안내 화면은 항목별 사유(미설치·꺼짐·버전 미달)를 그리므로 뷰 데이터의 각 항목에 `status`·`type` 이 실려야 한다.
 * 의존 모듈이 업데이트하는 동안(`updating`)에는 런타임 판정만 활성으로 봐 사이트가 잠깐 503 이 되지 않게 한다(D2).
 *
 * 시나리오 매니페스트: tests/scenarios/template-dependency-version.yaml
 */
class CheckTemplateDependenciesTest extends TestCase
{
    use DatabaseTransactions;

    private const TEMPLATE = 'test-dep152-active';

    private TemplateManager $manager;

    /**
     * 의존 모듈과, 그 모듈에 의존하는 활성 사용자 템플릿을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Module::create([
            'identifier' => 'test-dep152-mod', 'vendor' => 'test', 'name' => ['ko' => '의존 모듈', 'en' => 'Dep Module'],
            'version' => '0.1.0', 'status' => ExtensionStatus::Active->value, 'description' => ['ko' => '', 'en' => ''],
        ]);

        Template::query()->where('type', 'user')->update(['status' => ExtensionStatus::Inactive->value]);
        Template::factory()->create([
            'identifier' => self::TEMPLATE,
            'vendor' => 'test',
            'type' => 'user',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        TemplateManager::invalidateTemplateStatusCache();

        $manager = app(TemplateManagerInterface::class);
        $this->assertInstanceOf(TemplateManager::class, $manager);
        $this->manager = $manager;
    }

    /**
     * 테스트 뒤 상태 캐시를 비운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        TemplateManager::invalidateTemplateStatusCache();
        parent::tearDown();
    }

    /**
     * 활성 템플릿 manifest 의 의존 선언을 바꾼다.
     *
     * @param  array<string, mixed>  $dependencies  manifest 의 dependencies
     * @return void 반환값 없음
     */
    private function declare(array $dependencies): void
    {
        $templates = new ReflectionProperty(TemplateManager::class, 'templates');
        $loaded = $templates->getValue($this->manager);
        $loaded[self::TEMPLATE] = [
            'identifier' => self::TEMPLATE,
            'vendor' => 'test',
            'name' => ['ko' => '시험 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => 'user',
            'dependencies' => $dependencies,
        ];
        $templates->setValue($this->manager, $loaded);
    }

    /**
     * 미들웨어를 한 번 통과시킨다.
     *
     * @return Response 응답
     */
    private function pass(): Response
    {
        return (new CheckTemplateDependencies($this->manager))
            ->handle(Request::create('/', 'GET'), fn () => response('ok', 200), 'user');
    }

    /**
     * 버전 미달이면 503 이고, 뷰 데이터 항목이 사유·종류·버전을 싣는다.
     *
     * @scenario surface=runtime_version_mismatch
     *
     * @effects runtime_503_carries_reason_status
     *
     * @return void 반환값 없음
     */
    public function test_version_mismatch_returns_503_with_reason_status(): void
    {
        $this->declare(['modules' => ['test-dep152-mod' => '>=9.9.9']]);

        $response = $this->pass();

        $this->assertSame(503, $response->getStatusCode());
        $item = $response->getOriginalContent()->getData()['unmetDependencies']['modules'][0];
        $this->assertSame('version_mismatch', $item['status']);
        $this->assertSame('module', $item['type']);
        $this->assertSame('0.1.0', $item['installed_version']);
        $this->assertSame('>=9.9.9', $item['required_version']);
        $this->assertTrue($item['is_active'], '하위 호환 필드 유지');
        $this->assertFalse($item['version_met']);
        $this->assertFalse($item['met']);
    }

    /**
     * 의존 모듈이 업데이트 중(updating)이면 사이트를 503 으로 내리지 않는다.
     *
     * @scenario surface=runtime_updating
     *
     * @effects runtime_treats_updating_as_active
     *
     * @return void 반환값 없음
     */
    public function test_updating_dependency_does_not_503(): void
    {
        Module::where('identifier', 'test-dep152-mod')->update(['status' => ExtensionStatus::Updating->value]);
        $this->declare(['modules' => ['test-dep152-mod' => '>=0.1.0']]);

        $this->assertSame(200, $this->pass()->getStatusCode());
    }

    /**
     * 충족이면 그대로 통과한다 (회귀).
     *
     * @scenario surface=runtime_met
     *
     * @effects runtime_passes_when_met
     *
     * @return void 반환값 없음
     */
    public function test_met_dependency_passes(): void
    {
        $this->declare(['modules' => ['test-dep152-mod' => '>=0.1.0']]);

        $this->assertSame(200, $this->pass()->getStatusCode());
    }
}
