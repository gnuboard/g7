<?php

namespace Tests\Unit\Extension;

use App\Services\Extension\ExtensionIconResolver;
use App\Support\ExtensionIcon\ExtensionIconValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 번들 확장 아이콘 계약 테스트
 *
 * 제3자 확장은 아이콘을 넣지 않아도 되지만(유형 글리프로 표시), 번들 확장 21개는
 * 전부 `assets/icon.svg` 를 동봉하고 규격을 지킨다 — 이 테스트가 그 계약이다.
 *
 * 소유 시나리오: `extension-icons` (축 매핑은 각 테스트 메서드에 있다)
 *
 * @effects icon_resolves_manifest_over_convention
 */
class BundledExtensionIconTest extends TestCase
{
    /** @var int 번들 확장 모집단 하한 (모듈 4 + 플러그인 13 + 템플릿 4) */
    private const MINIMUM_POPULATION = 20;

    /**
     * 저장소 루트 절대 경로를 돌려줍니다.
     *
     * 데이터 프로바이더는 애플리케이션 부팅 전에 돌아 base_path() 를 쓸 수 없다.
     *
     * @return string 저장소 루트
     */
    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 번들 확장(모듈·플러그인·템플릿)의 루트와 매니페스트 경로를 돌려줍니다.
     *
     * @return array<string, array{0: string, 1: string}> 식별자 → [루트, 매니페스트 경로]
     */
    public static function bundledExtensionProvider(): array
    {
        $cases = [];

        foreach (['modules' => 'module.json', 'plugins' => 'plugin.json', 'templates' => 'template.json'] as $dir => $manifest) {
            foreach (glob(self::repositoryRoot().'/'.$dir.'/_bundled/*/'.$manifest) ?: [] as $manifestPath) {
                $root = dirname($manifestPath);
                $cases[$dir.'/'.basename($root)] = [$root, $manifestPath];
            }
        }

        return $cases;
    }

    /**
     * 번들 언어팩의 루트와 매니페스트 경로를 돌려줍니다.
     *
     * @return array<string, array{0: string, 1: string}> 식별자 → [루트, 매니페스트 경로]
     */
    public static function bundledLanguagePackProvider(): array
    {
        $cases = [];

        foreach (glob(self::repositoryRoot().'/lang-packs/_bundled/*/language-pack.json') ?: [] as $manifestPath) {
            $root = dirname($manifestPath);
            $cases[basename($root)] = [$root, $manifestPath];
        }

        return $cases;
    }

    /**
     * 번들 확장 모집단이 하한 이상인지 확인합니다.
     *
     * @scenario location=bundled, declaration=convention_svg
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function bundled_population_meets_the_lower_bound(): void
    {
        $this->assertGreaterThanOrEqual(
            self::MINIMUM_POPULATION,
            count(self::bundledExtensionProvider()),
            '번들 확장 모집단이 하한 아래입니다 — glob 경로가 바뀌었는지 확인하세요.'
        );
    }

    /**
     * 번들 확장마다 규격을 지킨 관례 아이콘이 있습니다.
     *
     * @scenario location=bundled, declaration=convention_svg, validity=valid
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @param  string  $root  확장 루트 절대 경로
     * @param  string  $manifestPath  매니페스트 파일 경로
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('bundledExtensionProvider')]
    public function every_bundled_extension_ships_a_valid_icon(string $root, string $manifestPath): void
    {
        $iconPath = $root.'/assets/icon.svg';

        $this->assertFileExists($iconPath, basename($root).' 에 assets/icon.svg 가 없습니다.');

        $result = ExtensionIconValidator::validateFile($iconPath);

        $this->assertTrue(
            $result['valid'],
            basename($root).' 의 아이콘이 규격 위반입니다: '.implode(', ', $result['errors'])
        );
    }

    /**
     * 매니페스트 icon 선언이 있으면 규칙을 지키고 실재합니다.
     *
     * @scenario location=bundled, declaration=manifest_path
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @param  string  $root  확장 루트 절대 경로
     * @param  string  $manifestPath  매니페스트 파일 경로
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('bundledExtensionProvider')]
    public function declared_manifest_icon_is_well_formed_and_present(string $root, string $manifestPath): void
    {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest, $manifestPath.' 를 JSON 으로 읽을 수 없습니다.');

        $icon = $manifest['icon'] ?? null;

        if (! is_string($icon) || trim($icon) === '') {
            $this->assertTrue(true, '선언이 없으면 관례로 해석되므로 통과입니다.');

            return;
        }

        $this->assertNull(
            ExtensionIconValidator::manifestPathViolation($icon),
            basename($root).' 의 icon 경로가 규칙 위반입니다: '.$icon
        );
        $this->assertNotNull(
            ExtensionIconValidator::resolveInsideRoot($root, $icon),
            basename($root).' 의 icon 이 가리키는 파일이 없습니다: '.$icon
        );
    }

    /**
     * 템플릿 매니페스트에 죽은 preview 선언이 남아 있지 않습니다.
     *
     * @scenario extension_type=template, location=bundled, declaration=none
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function template_manifests_no_longer_declare_a_preview_block(): void
    {
        foreach (glob(self::repositoryRoot().'/templates/_bundled/*/template.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);

            $this->assertIsArray($manifest, $manifestPath.' 를 JSON 으로 읽을 수 없습니다.');
            $this->assertArrayNotHasKey(
                'preview',
                $manifest,
                basename(dirname($manifestPath)).' 이 preview 를 선언했습니다 — 코드가 읽지 않는 죽은 선언입니다. 아이콘은 assets/icon.svg 관례를 씁니다.'
            );
        }
    }

    /**
     * 언어팩이 icon 을 선언했으면 그 파일이 실재합니다.
     *
     * @scenario extension_type=language_pack, location=bundled, declaration=manifest_path
     *
     * @effects language_pack_icon_source_derivation
     *
     * @param  string  $root  확장 루트 절대 경로
     * @param  string  $manifestPath  매니페스트 파일 경로
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('bundledLanguagePackProvider')]
    public function declared_language_pack_icon_is_present(string $root, string $manifestPath): void
    {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest, $manifestPath.' 를 JSON 으로 읽을 수 없습니다.');

        $icon = $manifest['icon'] ?? null;

        if (! is_string($icon) || trim($icon) === '') {
            // 언어팩은 대상 확장 아이콘 + 로케일 배지로 합성되므로 선언이 없는 것이 기본이다.
            $this->assertTrue(true);

            return;
        }

        $this->assertNull(ExtensionIconValidator::manifestPathViolation($icon));
        $this->assertNotNull(ExtensionIconValidator::resolveInsideRoot($root, $icon));
    }

    /**
     * 코어 아이콘이 실재하고 규격을 지킵니다.
     *
     * @scenario extension_type=language_pack, declaration=convention_svg, validity=valid
     *
     * @effects language_pack_icon_source_derivation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function core_icon_exists_and_is_valid(): void
    {
        $path = public_path(ltrim(ExtensionIconResolver::CORE_ICON_URL, '/'));

        $this->assertFileExists($path, '코어 아이콘이 없습니다 — 코어 언어팩 행이 그림을 잃습니다.');

        $result = ExtensionIconValidator::validateFile($path);

        $this->assertTrue($result['valid'], '코어 아이콘이 규격 위반입니다: '.implode(', ', $result['errors']));
    }
}
