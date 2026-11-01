<?php

namespace Tests\Feature\Template;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Repositories\TemplateRepository;
use App\Services\TemplateService;
use Tests\TestCase;

/**
 * 템플릿 의존 역조회(getTemplatesDependingOnModule·Plugin)가 dependencies 목록형도 읽는다 (#822, D6 같은 결함군).
 *
 * 종전에는 `$dependencies['modules']` 를 키로 읽어, 목록형 `{"modules": ["sirsoft-board"]}` 이면 키가 0·1 이라
 * 의존 템플릿을 찾지 못했다 — 모듈 비활성화 경고에서 그 템플릿이 빠졌다.
 */
class TemplateDependingOnListFormTest extends TestCase
{
    /**
     * 설치 템플릿 목록을 주입한 TemplateService 를 만듭니다.
     *
     * @param  array<int, array<string, mixed>>  $templates  getInstalledTemplatesWithDetails 결과
     * @return TemplateService 서비스
     */
    private function serviceWith(array $templates): TemplateService
    {
        $templateManager = $this->createMock(TemplateManagerInterface::class);
        $templateManager->method('getInstalledTemplatesWithDetails')->willReturn($templates);

        return new TemplateService(
            new TemplateRepository,
            $templateManager,
            $this->createMock(ModuleManagerInterface::class),
            $this->createMock(PluginManagerInterface::class),
            app(LayoutVersionRepositoryInterface::class)
        );
    }

    /**
     * 템플릿 상세 한 건을 만듭니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  mixed  $dependencies  dependencies 선언
     * @return array<string, mixed> 템플릿 상세
     */
    private function template(string $identifier, mixed $dependencies): array
    {
        return [
            'identifier' => $identifier,
            'name' => $identifier,
            'version' => '1.0.0',
            'type' => 'user',
            'status' => 'active',
            'dependencies' => $dependencies,
        ];
    }

    /**
     * 객체형·목록형 모두에서 의존 템플릿을 찾고, 목록형의 필요 버전은 '*' 이다.
     *
     * @scenario case=dependency_list_form
     *
     * @effects list_form_dependencies_found_in_reverse_lookup
     *
     * @return void 반환값 없음
     */
    public function test_module_reverse_lookup_reads_object_and_list_forms(): void
    {
        $service = $this->serviceWith([
            $this->template('object-form', ['modules' => ['sirsoft-board' => '>=1.2.1']]),
            $this->template('list-form', ['modules' => ['sirsoft-board']]),
            $this->template('other', ['modules' => ['sirsoft-page' => '*']]),
            $this->template('none', null),
        ]);

        $found = $service->getTemplatesDependingOnModule('sirsoft-board');

        $this->assertSame(['object-form', 'list-form'], array_column($found, 'identifier'));
        $this->assertSame(['>=1.2.1', '*'], array_column($found, 'required_version'));
        // 목록형 키(0)를 식별자로 읽던 종전 동작이 되살아나지 않는다
        $this->assertSame([], $service->getTemplatesDependingOnModule('0'));
    }

    /**
     * 플러그인 역조회도 같은 규칙이다.
     *
     * @scenario case=dependency_list_form
     *
     * @effects list_form_dependencies_found_in_reverse_lookup
     *
     * @return void 반환값 없음
     */
    public function test_plugin_reverse_lookup_reads_object_and_list_forms(): void
    {
        $service = $this->serviceWith([
            $this->template('object-form', ['plugins' => ['sirsoft-daum_postcode' => '>=1.0.0']]),
            $this->template('list-form', ['plugins' => ['sirsoft-daum_postcode']]),
            $this->template('modules-only', ['modules' => ['sirsoft-daum_postcode']]),
        ]);

        $found = $service->getTemplatesDependingOnPlugin('sirsoft-daum_postcode');

        $this->assertSame(['object-form', 'list-form'], array_column($found, 'identifier'));
        $this->assertSame(['>=1.0.0', '*'], array_column($found, 'required_version'));
    }
}
