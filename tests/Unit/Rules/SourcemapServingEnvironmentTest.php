<?php

namespace Tests\Unit\Rules;

use App\Rules\AllowedModuleFileType;
use App\Rules\AllowedPluginFileType;
use App\Rules\AllowedTemplateFileType;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 소스맵 에셋 서빙의 환경별 허용 계약 테스트.
 *
 * 소스맵에는 원본 TS/TSX 전문(`sourcesContent`)이 담기고, 확장 에셋 서빙에서
 * 이 확장자 화이트리스트가 유일한 방어선이다. 따라서 운영 환경에서는 어떤
 * 경우에도 `.map` 이 서빙되면 안 된다.
 *
 * 동시에 로컬 개발 환경의 dev 빌드 산출물은 `//# sourceMappingURL` 이 개별
 * 에셋 서빙 URL 을 가리키므로, 차단하면 브라우저 콘솔에 404 가 쌓인다.
 * 두 요구를 환경으로 가른다.
 */
class SourcemapServingEnvironmentTest extends TestCase
{
    /**
     * 검증 대상 규칙 클래스 목록
     *
     * @return array<string, array{class-string, string}>
     */
    public static function ruleProvider(): array
    {
        return [
            'module' => [AllowedModuleFileType::class, 'dist/js/module.iife.js.map'],
            'plugin' => [AllowedPluginFileType::class, 'dist/js/plugin.iife.js.map'],
            'template' => [AllowedTemplateFileType::class, 'dist/js/components.iife.js.map'],
        ];
    }

    /**
     * 운영 환경에서 소스맵이 차단되는지 테스트
     *
     * @param  class-string  $ruleClass  규칙 클래스
     * @param  string  $sourcemapPath  소스맵 경로
     */
    #[DataProvider('ruleProvider')]
    public function test_sourcemap_is_blocked_in_production(string $ruleClass, string $sourcemapPath): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertNotContains('map', $ruleClass::getAllowedExtensions());

        $validator = Validator::make(['path' => $sourcemapPath], ['path' => new $ruleClass]);

        $this->assertFalse(
            $validator->passes(),
            $ruleClass.': 운영 환경에서 소스맵이 서빙되면 원본 코드 전문이 노출됩니다.'
        );
    }

    /**
     * 로컬 개발 환경에서 소스맵이 허용되는지 테스트
     *
     * @param  class-string  $ruleClass  규칙 클래스
     * @param  string  $sourcemapPath  소스맵 경로
     */
    #[DataProvider('ruleProvider')]
    public function test_sourcemap_is_allowed_in_local(string $ruleClass, string $sourcemapPath): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->assertContains('map', $ruleClass::getAllowedExtensions());

        $validator = Validator::make(['path' => $sourcemapPath], ['path' => new $ruleClass]);

        $this->assertTrue(
            $validator->passes(),
            $ruleClass.': 로컬 개발 환경에서는 dev 빌드의 소스맵 참조가 404 가 되지 않도록 허용해야 합니다.'
        );
    }

    /**
     * 소스맵 외 확장자는 환경과 무관하게 동일하게 허용되는지 테스트
     *
     * 환경 분기가 소스맵에만 적용되고 다른 확장자에는 영향을 주지 않아야 한다.
     *
     * @param  class-string  $ruleClass  규칙 클래스
     */
    #[DataProvider('ruleProvider')]
    public function test_non_sourcemap_extensions_are_environment_independent(string $ruleClass): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $production = $ruleClass::getAllowedExtensions();

        $this->app->detectEnvironment(fn () => 'local');
        $local = $ruleClass::getAllowedExtensions();

        $this->assertSame(
            ['map'],
            array_values(array_diff($local, $production)),
            $ruleClass.': 환경에 따라 달라지는 확장자는 소스맵 하나뿐이어야 합니다.'
        );
    }
}
