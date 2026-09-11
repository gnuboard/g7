<?php

namespace Tests\Unit\Extension;

use App\Extension\PluginManager;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 플러그인 레이아웃의 partial 해석이 모듈·템플릿과 **같은 규칙**을 쓰는지 봉인한다.
 *
 * `PluginManager` 는 `ValidatesLayoutFiles` 트레이트를 쓰면서도 `resolveAllPartials`·
 * `resolvePartialPath` 를 **자기 클래스에 한 벌 더** 들고 있었다. PHP 는 클래스 메서드가 트레이트를
 * 이기므로, 트레이트에만 들어간 개선(`partials/…` 는 layouts 루트 기준으로 폴백)이 플러그인 설치
 * 경로에는 **닿지 않았다**. 같은 작성 관례가 확장 유형에 따라 다르게 동작하는 상태였다.
 *
 * 복제본을 지워 트레이트 한 벌만 남겼고, 이 테스트가 그 규칙 동일성을 지킨다.
 */
class PluginPartialResolutionParityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // plugins/{name}/resources/layouts 모양을 갖춘 임시 트리 (경로 모양이 판정에 쓰인다)
        $this->root = storage_path('framework/testing/partial_parity_'.uniqid().'/plugins/acme-demo/resources/layouts');
        File::makeDirectory($this->root.'/admin/partials/detail', 0777, true);
        File::makeDirectory($this->root.'/partials/shared', 0777, true);
    }

    protected function tearDown(): void
    {
        $base = dirname($this->root, 4);

        if (File::isDirectory($base)) {
            File::deleteDirectory($base);
        }

        parent::tearDown();
    }

    /**
     * 하위 디렉토리의 조각이 **layouts 루트 기준** `partials/…` 를 참조하면 해석돼야 한다.
     *
     * 이것이 트레이트에만 있던 폴백이다 — 복제본을 쓰던 시절 플러그인에서는 이 참조가 깨졌고,
     * `app.debug=false` 에서는 에러 컴포넌트조차 없이 **빈 배열로 사라졌다**.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects plugin_partial_resolution_matches_module_and_template
     */
    public function test_root_relative_partial_resolves_from_nested_directory(): void
    {
        File::put($this->root.'/partials/shared/_badge.json', json_encode([
            'type' => 'basic',
            'name' => 'Span',
            'text' => 'BADGE',
        ]));

        $layout = [
            'version' => '1.0.0',
            'layout_name' => 'acme_detail',
            'components' => [
                ['type' => 'basic', 'name' => 'Div', 'children' => [
                    ['partial' => 'partials/shared/_badge.json'],
                ]],
            ],
        ];

        $resolver = new class extends PluginManager
        {
            public function __construct() {}

            /**
             * @param  array<string, mixed>  $data  레이아웃 데이터
             * @param  string  $basePath  기준 디렉토리
             * @return array<string, mixed> 조각이 인라인된 결과
             */
            public function run(array $data, string $basePath): array
            {
                $this->partialStack = [];

                return $this->resolveAllPartials($data, $basePath, 0);
            }
        };

        // 진입 레이아웃이 `admin/` 하위에 있어 기준 디렉토리가 layouts 루트가 아니다 —
        // 폴백이 없으면 `admin/partials/shared/_badge.json` 을 찾다가 실패한다.
        $resolved = $resolver->run($layout, $this->root.'/admin');

        $this->assertStringContainsString('BADGE', json_encode($resolved), '루트 기준 partial 이 해석되지 않았습니다.');
        $this->assertStringNotContainsString('partial', json_encode($resolved), '미해석 partial 키가 남았습니다.');
    }
}
