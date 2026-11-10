<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Extension\TemplateManager;
use App\Http\Middleware\CheckTemplateDependencies;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * sirsoft-basic 모듈별 단독 사용 — 게시판만 켠 사이트 (#822)
 *
 * sirsoft-basic 의 manifest 가 게시판·쇼핑몰·페이지 모듈과 우편번호 플러그인을 **필수 의존**으로 선언하던 동안
 * 게시판만 켠 사이트는 템플릿을 설치할 수 없었고(의존 검사 예외), 이미 켠 사이트는 매 요청 의존 미들웨어가
 * 사용자 화면 전체를 503 으로 돌려주었다. 넷은 선택 연동(`integrations`)이어야 한다.
 *
 * 실제 번들 manifest(`templates/_bundled/sirsoft-basic/template.json`)를 읽어 판정한다 — 사본을 만들면
 * manifest 가 다시 필수 의존을 선언해도 이 테스트는 모른다.
 *
 * 시나리오 매니페스트: templates/_bundled/sirsoft-basic/tests/scenarios/standalone-module-guards.yaml
 */
class TemplateStandaloneModulesTest extends TestCase
{
    use DatabaseTransactions;

    /** 선택 연동 대상 모듈 */
    private const INTEGRATION_MODULES = ['sirsoft-board', 'sirsoft-ecommerce', 'sirsoft-page'];

    /** 선택 연동 대상 플러그인 */
    private const INTEGRATION_PLUGINS = ['sirsoft-daum_postcode'];

    private TemplateManager $manager;

    /** @var array<string, mixed> 번들 manifest */
    private array $manifest;

    /**
     * 게시판만 켠 사이트를 만든다 — 게시판 모듈 활성, 쇼핑몰·페이지·우편번호 없음, sirsoft-basic 이 활성 사용자 템플릿.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->manifest = json_decode(
            (string) file_get_contents(base_path('templates/_bundled/sirsoft-basic/template.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        Module::query()->whereIn('identifier', self::INTEGRATION_MODULES)->delete();
        Plugin::query()->whereIn('identifier', self::INTEGRATION_PLUGINS)->delete();
        Module::factory()->create([
            'identifier' => 'sirsoft-board',
            'vendor' => 'sirsoft',
            'version' => '1.2.1',
            'status' => 'active',
        ]);

        Template::query()->where('type', 'user')->update(['status' => 'inactive']);
        Template::query()->where('identifier', 'sirsoft-basic')->delete();
        Template::factory()->create([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'type' => 'user',
            'version' => $this->manifest['version'],
            'status' => 'active',
        ]);

        TemplateManager::invalidateTemplateStatusCache();

        $manager = app(TemplateManagerInterface::class);
        $this->assertInstanceOf(TemplateManager::class, $manager);
        $this->manager = $manager;

        // 메모리 템플릿 목록에 번들 manifest 를 그대로 싣는다(활성 디렉토리 상태와 무관하게 판정)
        $templates = new ReflectionProperty(TemplateManager::class, 'templates');
        $loaded = $templates->getValue($this->manager);
        $loaded['sirsoft-basic'] = array_merge($this->manifest, ['identifier' => 'sirsoft-basic']);
        $templates->setValue($this->manager, $loaded);
    }

    /**
     * 테스트 뒤 상태 캐시를 비운다(다음 테스트가 이 사이트의 활성 목록을 읽지 않게).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        TemplateManager::invalidateTemplateStatusCache();
        parent::tearDown();
    }

    /**
     * 넷은 필수 의존이 아니라 버전 제약을 가진 선택 연동으로 선언된다.
     *
     * @scenario surface=manifest
     *
     * @effects integrations_not_dependencies
     *
     * @return void 반환값 없음
     */
    public function test_manifest_declares_modules_as_integrations_not_dependencies(): void
    {
        $this->assertSame([], $this->manifest['dependencies']['modules'] ?? null);
        $this->assertSame([], $this->manifest['dependencies']['plugins'] ?? null);

        $this->assertSame([
            'sirsoft-board' => '>=1.2.1',
            'sirsoft-ecommerce' => '>=1.3.4',
            'sirsoft-page' => '>=1.2.0',
        ], $this->manifest['integrations']['modules'] ?? null);
        $this->assertSame(['sirsoft-daum_postcode' => '>=1.2.0'], $this->manifest['integrations']['plugins'] ?? null);

        // 옛 코어에서도 설치된다 — 단독 사용 판정 키가 없으면 「모두 켜짐」으로 본다(P5)
        $this->assertSame('>=7.1.1', $this->manifest['g7_version']);
    }

    /**
     * 게시판만 켠 사이트에서 템플릿 설치 의존 검사가 통과한다(예외 없음).
     *
     * @scenario surface=install
     *
     * @effects board_only_site_installs_template
     *
     * @return void 반환값 없음
     */
    public function test_template_dependency_check_passes_with_board_only(): void
    {
        $check = new ReflectionMethod(TemplateManager::class, 'checkDependencies');
        $check->invoke($this->manager, $this->manager->getTemplate('sirsoft-basic'));

        $this->assertSame(['modules' => [], 'plugins' => []], $this->manager->getUnmetDependencies('sirsoft-basic'));
    }

    /**
     * 게시판만 켠 사이트의 사용자 화면이 503 이 아니라 그대로 진행된다.
     *
     * @scenario surface=middleware
     *
     * @effects board_only_site_serves_user_routes
     *
     * @return void 반환값 없음
     */
    public function test_user_routes_are_not_503_with_board_only(): void
    {
        $middleware = new CheckTemplateDependencies($this->manager);

        foreach (['/', '/board/free', '/login'] as $path) {
            $response = $middleware->handle(Request::create($path, 'GET'), fn () => response('ok', 200), 'user');

            $this->assertSame(200, $response->getStatusCode(), "{$path} 가 503 이 아니어야 한다");
        }
    }
}
