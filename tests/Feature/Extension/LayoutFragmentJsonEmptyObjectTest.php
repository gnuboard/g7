<?php

namespace Tests\Feature\Extension;

use App\Extension\Traits\CollectsFragmentSources;
use App\Services\LayoutService;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `.json` 단독 레이아웃 소스의 빈 객체 보존 테스트 (#758)
 *
 * `.g7.html` 짝이 없는 `.json` 조각과 플러그인 설정 레이아웃 `.json` 은 `json_decode(true)` 로 읽혀,
 * 등록·응답하는 순간부터 `{}` 가 `[]` 였다. 번들은 모두 `.g7.html` 짝이 있어 0건이지만 외부 확장에서 잠복한다.
 */
class LayoutFragmentJsonEmptyObjectTest extends TestCase
{
    /** 테스트용 임시 디렉터리 */
    private string $dir;

    /**
     * 임시 디렉터리를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/issue758_'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    /**
     * 임시 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * 짝 마크업이 없는 `.json` 조각을 등록용으로 읽어도 빈 객체가 남는다 — 캐스트로 저장하면 `{}` 다.
     *
     * @scenario path=fragment_json
     *
     * @effects write_path_keeps_empty_object_fragment_json
     *
     * @return void 반환값 없음
     */
    public function test_json_only_fragment_keeps_empty_objects(): void
    {
        $path = $this->dir.'/probe_point.json';
        File::put($path, '{"extension_point":"probe_point","components":[{"id":"a","type":"basic","name":"Div","actions":[{"handler":"setState","params":{"target":"local","selection":{}}}]}],"list":[]}');

        $loader = new class
        {
            use CollectsFragmentSources;

            /**
             * 보호 메서드를 시험에서 부릅니다.
             *
             * @param  string  $path  `.json` 경로
             * @return array<string, mixed>|null 읽은 정의
             */
            public function load(string $path): ?array
            {
                return $this->loadFragmentSource(['path' => $path, 'markup' => false]);
            }
        };

        $data = $loader->load($path);

        $this->assertIsArray($data);
        $this->assertSame('probe_point', $data['extension_point'], '`is_array` 검사와 키 접근은 종전대로');
        $encoded = json_encode($data);
        $this->assertStringContainsString('"selection":{}', $encoded);
        $this->assertStringContainsString('"list":[]', $encoded);
    }

    /**
     * 플러그인 설정 레이아웃 `.json` 을 읽어 응답용으로 정리해도 빈 객체가 남는다.
     *
     * @scenario path=fragment_json
     *
     * @effects write_path_keeps_empty_object_fragment_json
     *
     * @return void 반환값 없음
     */
    public function test_plugin_settings_json_layout_keeps_empty_objects(): void
    {
        $path = $this->dir.'/settings.json';
        File::put($path, '{"version":"1.0.0","initLocal":{"form":{}},"components":[{"id":"f","type":"basic","name":"Div","props":{"dataMap":{}}}]}');

        $parse = new \ReflectionMethod(PluginSettingsService::class, 'parseLayoutFile');
        $layout = $parse->invoke(app(PluginSettingsService::class), $path);

        $this->assertIsArray($layout);
        $encoded = json_encode(app(LayoutService::class)->sanitizeLayoutJson($layout));
        $this->assertStringContainsString('"form":{}', $encoded);
        $this->assertStringContainsString('"dataMap":{}', $encoded, 'sanitize 를 거쳐도 props 값 빈 객체가 남는다');
    }
}
