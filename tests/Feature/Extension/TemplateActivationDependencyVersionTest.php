<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\ExtensionStatus;
use App\Extension\TemplateManager;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 템플릿 활성화가 의존 확장의 버전 제약까지 본다 — 설치·활성화·런타임 판정 일치 (#152)
 *
 * 회귀: `TemplateManager::activateTemplate()` 의 의존 루프가 설치·활성 여부만 보고 버전 제약을 버려서,
 * 의존 모듈이 요구 버전에 못 미쳐도 활성화가 200 으로 통과하고 직후 런타임 미들웨어가 같은 미달로 사이트 전체를 503 으로 내렸다.
 * 트러블슈팅 `[case:backend-60]` 의 회귀 그물이다.
 *
 * 시나리오 매니페스트: tests/scenarios/template-dependency-version.yaml
 */
class TemplateActivationDependencyVersionTest extends TestCase
{
    use DatabaseTransactions;

    private const TEMPLATE = 'test-dep152-tpl';

    private TemplateManager $manager;

    /**
     * 의존 대상(모듈 0.1.0 활성·플러그인 1.1.2 활성·꺼진 모듈)과 비활성 사용자 템플릿 행을 만든다.
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
        Module::create([
            'identifier' => 'test-dep152-off', 'vendor' => 'test', 'name' => ['ko' => '꺼진 모듈', 'en' => 'Off Module'],
            'version' => '1.0.0', 'status' => ExtensionStatus::Inactive->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
        Plugin::create([
            'identifier' => 'test-dep152-plg', 'vendor' => 'test', 'name' => ['ko' => '의존 플러그인', 'en' => 'Dep Plugin'],
            'version' => '1.1.2', 'status' => ExtensionStatus::Active->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
        Template::factory()->create([
            'identifier' => self::TEMPLATE,
            'vendor' => 'test',
            'type' => 'user',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
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
     * 메모리 템플릿 목록에 의존 선언만 다른 manifest 를 싣는다.
     *
     * @param  array<string, mixed>  $dependencies  manifest 의 dependencies
     * @param  string  $type  템플릿 타입 (user|admin)
     * @return void 반환값 없음
     */
    private function declare(array $dependencies, string $type = 'user'): void
    {
        $templates = new ReflectionProperty(TemplateManager::class, 'templates');
        $loaded = $templates->getValue($this->manager);
        $loaded[self::TEMPLATE] = [
            'identifier' => self::TEMPLATE,
            'vendor' => 'test',
            'name' => ['ko' => '시험 템플릿', 'en' => 'Test Template'],
            'version' => '1.0.0',
            'type' => $type,
            'dependencies' => $dependencies,
        ];
        $templates->setValue($this->manager, $loaded);
    }

    /**
     * 의존 모듈·플러그인이 요구 버전에 못 미치면 활성화가 경고(409 원천)로 막히고 사유·버전을 싣는다.
     *
     * @scenario surface=activate_version_mismatch
     *
     * @effects activation_blocks_version_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_activation_reports_version_mismatch_for_modules_and_plugins(): void
    {
        $this->declare(['modules' => ['test-dep152-mod' => '>=9.9.9'], 'plugins' => ['test-dep152-plg' => '>=9.0.0']]);

        $result = $this->manager->activateTemplate(self::TEMPLATE);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['warning']);
        $this->assertSame('user', $result['template_type']);
        $this->assertSame([
            'identifier' => 'test-dep152-mod',
            'name' => '의존 모듈',
            'type' => 'module',
            'status' => 'version_mismatch',
            'required_version' => '>=9.9.9',
            'installed_version' => '0.1.0',
        ], array_intersect_key($result['missing_modules'][0], array_flip(['identifier', 'name', 'type', 'status', 'required_version', 'installed_version'])));
        $this->assertSame('version_mismatch', $result['missing_plugins'][0]['status']);
        $this->assertSame('1.1.2', $result['missing_plugins'][0]['installed_version']);
        $this->assertSame('>=9.0.0', $result['missing_plugins'][0]['required_version']);

        $this->assertSame(ExtensionStatus::Inactive->value, Template::where('identifier', self::TEMPLATE)->value('status'), '막혔으면 상태 불변');
    }

    /**
     * 꺼진 의존·미설치 의존도 같은 항목 모양으로 사유를 싣는다.
     *
     * @scenario surface=activate_inactive_missing
     *
     * @effects activation_reports_inactive_and_missing
     *
     * @return void 반환값 없음
     */
    public function test_activation_reports_inactive_and_not_installed(): void
    {
        $this->declare(['modules' => ['test-dep152-off' => '>=1.0.0', 'test-dep152-missing' => '>=1.0.0']]);

        $result = $this->manager->activateTemplate(self::TEMPLATE);

        $this->assertSame(['inactive', 'not_installed'], array_column($result['missing_modules'], 'status'));
        $this->assertSame('1.0.0', $result['missing_modules'][0]['installed_version']);
        $this->assertNull($result['missing_modules'][1]['installed_version']);
        $this->assertSame([], $result['missing_plugins']);
    }

    /**
     * 강제 활성화는 버전 미달이어도 켠다 (P2 — 모듈·플러그인과 같은 계약).
     *
     * @scenario surface=activate_force
     *
     * @effects force_activation_bypasses_version_check
     *
     * @return void 반환값 없음
     */
    public function test_force_activation_bypasses_version_mismatch(): void
    {
        $this->declare(['modules' => ['test-dep152-mod' => '>=9.9.9']]);

        $result = $this->manager->activateTemplate(self::TEMPLATE, true);

        $this->assertTrue($result['success']);
        $this->assertSame(ExtensionStatus::Active->value, Template::where('identifier', self::TEMPLATE)->value('status'));
    }

    /**
     * 설치 검사·활성화·런타임 미충족 판정이 같은 manifest 에 대해 같은 답을 낸다 — 빈 제약·null 은 셋 다 충족, 예외 없음.
     *
     * @scenario surface=judgement_agree
     *
     * @effects install_activate_runtime_agree
     *
     * @return void 반환값 없음
     */
    public function test_install_activation_and_runtime_judgements_agree(): void
    {
        $cases = [
            'empty' => [['modules' => ['test-dep152-mod' => '']], true],
            'null' => [['modules' => ['test-dep152-mod' => null]], true],
            'star' => [['modules' => ['test-dep152-mod' => '*']], true],
            'list' => [['modules' => ['test-dep152-mod']], true],
            'met' => [['modules' => ['test-dep152-mod' => '>=0.1.0']], true],
            'version_mismatch' => [['modules' => ['test-dep152-mod' => '>=9.9.9']], false],
        ];
        $install = new ReflectionMethod(TemplateManager::class, 'checkDependencies');

        foreach ($cases as $label => [$dependencies, $expectedMet]) {
            $this->declare($dependencies);

            try {
                $install->invoke($this->manager, $this->manager->getTemplate(self::TEMPLATE));
                $installMet = true;
            } catch (\TypeError $e) {
                $this->fail("{$label}: 설치 검사가 TypeError — {$e->getMessage()}");
            } catch (\Exception $e) {
                $installMet = false;
            }

            $activation = $this->manager->activateTemplate(self::TEMPLATE);
            $activationMet = ($activation['success'] ?? false) === true;
            if ($activationMet) {
                // 다음 경우를 위해 되돌린다 — 성공 경로는 활성으로 바꾼다
                Template::where('identifier', self::TEMPLATE)->update(['status' => ExtensionStatus::Inactive->value]);
                TemplateManager::invalidateTemplateStatusCache();
            }

            $unmet = $this->manager->getUnmetDependencies(self::TEMPLATE);
            $runtimeMet = $unmet['modules'] === [] && $unmet['plugins'] === [];

            $this->assertSame(
                ['install' => $expectedMet, 'activate' => $expectedMet, 'runtime' => $expectedMet],
                ['install' => $installMet, 'activate' => $activationMet, 'runtime' => $runtimeMet],
                $label
            );
        }
    }

    /**
     * 관리자 템플릿 활성화 경고는 template_type=admin 을 실어 강제 창이 관리자 503 문구를 고르게 한다.
     *
     * @scenario surface=activate_admin_type
     *
     * @effects admin_template_warning_carries_type
     *
     * @return void 반환값 없음
     */
    public function test_admin_template_warning_carries_template_type(): void
    {
        Template::where('identifier', self::TEMPLATE)->update(['type' => 'admin']);
        $this->declare(['modules' => ['test-dep152-mod' => '>=9.9.9']], 'admin');

        $result = $this->manager->activateTemplate(self::TEMPLATE);

        $this->assertTrue($result['warning']);
        $this->assertSame('admin', $result['template_type']);
    }
}
