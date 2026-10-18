<?php

namespace Tests\Unit\Resources;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Http\Resources\TemplateResource;
use App\Models\Module;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

/**
 * 템플릿 목록 응답의 의존성 이름이 항상 글자인지 고정하는 회귀 테스트 (#727 실측 중 발견 — React #31).
 *
 * 결함: 의존 모듈의 이름이 비어 있으면(`getExtensionName()` 이 null) 목록 응답의 의존성 이름이 null 로 나갔고,
 * 목록 카드 식(`d.name ?? d`)이 의존성 객체 통째를 글자 자리에 넣어 카드 목록 전체가 React #31 로 그려지지 않았다.
 * 이름이 비면 식별자로 대신한다 — 설치되지 않은 의존성이 식별자를 쓰는 것과 같은 규칙이다.
 */
class TemplateResourceDependencyNameTest extends TestCase
{
    /**
     * 의존 모듈 이름이 비어 있으면 목록 응답의 의존성 이름은 식별자다.
     *
     * @scenario surface=template_list_api
     *
     * @effects template_list_dependency_name_is_always_text
     *
     * @return void 반환값 없음
     */
    public function test_dependency_summary_name_falls_back_to_identifier(): void
    {
        $modules = Mockery::mock(ModuleRepositoryInterface::class);
        $modules->shouldReceive('findByIdentifier')->with('gnuboard7-hello_module')
            ->andReturn(new Module(['name' => ['ko' => '', 'en' => '']]));
        $modules->shouldReceive('findByIdentifier')->with('sirsoft-board')
            ->andReturn(new Module(['name' => ['ko' => '게시판', 'en' => 'Board']]));
        $modules->shouldReceive('findActiveByIdentifier')->andReturn(null);
        $plugins = Mockery::mock(PluginRepositoryInterface::class);
        $plugins->shouldReceive('findByIdentifier')->andReturn(null);
        $plugins->shouldReceive('findActiveByIdentifier')->andReturn(null);
        $this->app->instance(ModuleRepositoryInterface::class, $modules);
        $this->app->instance(PluginRepositoryInterface::class, $plugins);

        $array = (new TemplateResource([
            'identifier' => 'gnuboard7-hello_user_template',
            'name' => ['ko' => 'Hello 사용자 템플릿', 'en' => 'Hello User Template'],
            'version' => '0.2.0',
            'type' => 'user',
            'status' => 'active',
            'dependencies' => [
                'modules' => ['gnuboard7-hello_module' => '>=0.1.0', 'sirsoft-board' => '>=1.0.0'],
                'plugins' => ['sirsoft-daum_postcode' => '>=1.0.0'],
            ],
        ]))->toArray(Request::create('/api/admin/templates'));

        $this->assertSame(
            ['gnuboard7-hello_module', '게시판'],
            array_column($array['dependencies']['modules'], 'name')
        );
        $this->assertSame(['sirsoft-daum_postcode'], array_column($array['dependencies']['plugins'], 'name'));
    }
}
