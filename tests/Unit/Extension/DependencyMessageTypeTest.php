<?php

namespace Tests\Unit\Extension;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\Concerns\ChecksDependencyVersions;
use App\Extension\TemplateManager;
use Illuminate\Support\Facades\App;
use Mockery;
use Tests\TestCase;

/**
 * 의존 미충족 문구가 의존 대상의 실제 종류를 현지화해 부르는지 검증 (#153 D4).
 *
 * 종전 `modules.dependency_not_active` 는 종류와 무관하게 「모듈」이라 적어, 모듈이 플러그인(sirsoft-ckeditor5)에 의존해 막히면
 * 「의존하는 "sirsoft-ckeditor5" 모듈이 설치되지 않았거나…」로 안내했다.
 */
class DependencyMessageTypeTest extends TestCase
{
    /**
     * 미설치 모듈 1·플러그인 1 을 의존으로 둔 판정기를 만든다(설치 기록 없음).
     *
     * @return object 판정기
     */
    private function checker(): object
    {
        $modules = Mockery::mock(ModuleRepositoryInterface::class);
        $modules->shouldReceive('findByIdentifier')->andReturnNull();
        $plugins = Mockery::mock(PluginRepositoryInterface::class);
        $plugins->shouldReceive('findByIdentifier')->andReturnNull();
        $this->app->instance(ModuleRepositoryInterface::class, $modules);
        $this->app->instance(PluginRepositoryInterface::class, $plugins);

        return new class
        {
            use ChecksDependencyVersions {
                unmetDependencyMessages as public;
            }
        };
    }

    /**
     * 플러그인 의존이 모자라면 ko 「플러그인」, en `plugin` 으로, 모듈 의존은 「모듈」·`module` 로 부른다 — 모듈·플러그인 문구 파일 모두.
     *
     * @scenario surface=installer, edge=module_to_plugin
     *
     * @effects unmet_dependency_message_names_actual_extension_type
     *
     * @return void 반환값 없음
     */
    public function test_unmet_dependency_message_names_actual_type(): void
    {
        $dependencies = ['modules' => ['sirsoft-board' => '*'], 'plugins' => ['sirsoft-ckeditor5' => '>=1.0.0']];
        $checker = $this->checker();

        foreach (['modules', 'plugins'] as $langPrefix) {
            App::setLocale('ko');
            $ko = $checker->unmetDependencyMessages($dependencies, $langPrefix);
            $this->assertSame([
                '의존하는 모듈 "sirsoft-board"이(가) 설치되지 않았거나 비활성 상태입니다.',
                '의존하는 플러그인 "sirsoft-ckeditor5"이(가) 설치되지 않았거나 비활성 상태입니다.',
            ], $ko, $langPrefix);

            App::setLocale('en');
            $en = $checker->unmetDependencyMessages($dependencies, $langPrefix);
            $this->assertSame([
                'Dependency module "sirsoft-board" is not installed or inactive.',
                'Dependency plugin "sirsoft-ckeditor5" is not installed or inactive.',
            ], $en, $langPrefix);
        }
    }

    /**
     * 템플릿 의존 미충족 문구는 종류를 영어 원문(`module`) 대신 현지화 이름으로 싣는다.
     *
     * @scenario surface=installer, edge=admin_template_to_module
     *
     * @effects unmet_dependency_message_names_actual_extension_type
     *
     * @return void 반환값 없음
     */
    public function test_template_dependency_message_uses_localized_type(): void
    {
        $this->checker();
        $template = [
            'identifier' => 'sirsoft-admin_basic',
            'dependencies' => ['modules' => ['sirsoft-board' => '*'], 'plugins' => ['sirsoft-ckeditor5' => '>=1.0.0']],
        ];
        // 판정은 의존 판정식(DependencyEnricher)만 쓰므로 생성자 의존 없이 checkDependencies 를 직접 부른다
        $manager = (new \ReflectionClass(TemplateManager::class))->newInstanceWithoutConstructor();
        $check = new \ReflectionMethod(TemplateManager::class, 'checkDependencies');

        foreach ([
            'ko' => "필수 의존성 sirsoft-board (모듈)이 충족되지 않았습니다.\n필수 의존성 sirsoft-ckeditor5 (플러그인)이 충족되지 않았습니다.",
            'en' => "Required dependency sirsoft-board (module) not met.\nRequired dependency sirsoft-ckeditor5 (plugin) not met.",
        ] as $locale => $expected) {
            App::setLocale($locale);
            $message = null;
            try {
                $check->invoke($manager, $template);
            } catch (\Exception $e) {
                $message = $e->getMessage();
            }
            $this->assertSame($expected, $message, $locale);
        }
    }
}
