<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Models\LanguagePack;
use App\Services\Extension\ExtensionIconResolver;
use App\Services\LanguagePackService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Helpers\ProtectsExtensionDirectories;
use Tests\TestCase;

/**
 * 언어팩 `icon_source` 4갈래 **파생** 테스트
 *
 * 계획 §5.4 는 언어팩 행의 아이콘을 네 갈래로 정한다 — ① 자기 선언이 유효하면
 * `manifest` ② 코어 팩이면 `core` ③ 대상 확장(module·plugin·template)의 아이콘이
 * 해석되면 `target` ④ 그 외 `none`. 이 순서가 곧 D7 의 「대상 확장 아이콘 + 로케일
 * 배지」 규정이 발화하는 분기점이다.
 *
 * 종전에는 이 축을 재는 테스트가 **리소스 직렬화뿐**이었다 — 행에 `icon_source` 를
 * 직접 세팅하고 같은 값이 되읽히는지만 봐서(`ExtensionIconExposureTest` 의
 * `language_pack_icon_source_is_preserved`) 값이 **어떻게 정해지는가**는 아무도
 * 재지 않았다. `annotateIcon()` 을 부르는 테스트가 저장소에 0건이라, `core` 분기를
 * `module` 로 바꾸거나 `target` 분기의 스코프 배열을 비워도 전부 초록이었다.
 * 여기서는 실제 서비스 메서드를 불러 네 갈래를 각각 잠근다.
 *
 * 소유 시나리오: `extension-icons` (축 매핑은 각 테스트 메서드에 있다)
 *
 * @effects language_pack_icon_source_derivation
 */
class LanguagePackIconSourceDerivationTest extends TestCase
{
    use ProtectsExtensionDirectories;

    /** @var string 정사각 유효 SVG */
    private const VALID_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><title>t</title><rect width="64" height="64" fill="#336699"/></svg>';

    /** @var list<string> 정리 대상 픽스처 디렉터리 */
    private array $fixtureDirs = [];

    private LanguagePackService $service;

    /**
     * 확장 디렉터리를 보호하고 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExtensionProtection();
        $this->service = app(LanguagePackService::class);
    }

    /**
     * 픽스처를 지우고 보호를 해제합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->fixtureDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }
        $this->fixtureDirs = [];

        $this->tearDownExtensionProtection();

        parent::tearDown();
    }

    /**
     * 자기 아이콘을 동봉한 팩은 `manifest` 로 판정되고 자기 주소를 받습니다.
     *
     * @scenario extension_type=language_pack, declaration=convention_svg, surface=list
     *
     * @effects language_pack_icon_source_derivation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function pack_with_own_icon_derives_manifest_source(): void
    {
        $this->makeIconRoot('lang-packs/_bundled/g7-test-own-ja');

        $pack = $this->makePack([
            'identifier' => 'g7-test-own-ja',
            'scope' => 'plugin',
            'target_identifier' => 'test-icon-target-plugin',
            'version' => '2.3.4',
        ]);

        $this->service->annotateIcon($pack);

        $this->assertSame('manifest', $pack->getAttribute('icon_source'));
        $this->assertSame(
            '/api/extensions/language-pack/g7-test-own-ja/icon?v=2.3.4',
            $pack->getAttribute('icon_url'),
            '자기 아이콘을 가진 팩은 자기 주소를 받아야 합니다.'
        );
    }

    /**
     * 코어 팩은 `core` 로 판정되고 정적 코어 아이콘 주소를 받습니다.
     *
     * 자기 선언이 없을 때만 이 갈래로 온다 — 순서가 뒤집히면 위 케이스가 red 가 된다.
     *
     * @scenario extension_type=language_pack, declaration=none, surface=list
     *
     * @effects language_pack_icon_source_derivation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function core_pack_derives_core_source(): void
    {
        $pack = $this->makePack([
            'identifier' => 'g7-test-core-ja',
            'scope' => 'core',
            'target_identifier' => null,
        ]);

        $this->service->annotateIcon($pack);

        $this->assertSame('core', $pack->getAttribute('icon_source'));
        $this->assertSame(
            ExtensionIconResolver::CORE_ICON_URL,
            $pack->getAttribute('icon_url'),
            '코어 팩은 정적 코어 아이콘을 가리켜야 합니다.'
        );
    }

    /**
     * 대상 확장의 아이콘이 해석되면 `target` 으로 판정되고 그 확장의 주소를 받습니다.
     *
     * 스코프 3종(module·plugin·template)을 **모두** 돈다 — 배열에서 하나만 빠져도
     * 그 유형의 언어팩이 배지 없는 글리프로 떨어진다.
     *
     * @scenario extension_type=language_pack, declaration=none, surface=list
     *
     * @effects language_pack_icon_source_derivation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function pack_without_own_icon_borrows_target_extension_icon(): void
    {
        $scopes = [
            'module' => 'modules/_bundled/test-icon-target-mod',
            'plugin' => 'plugins/_bundled/test-icon-target-plug',
            'template' => 'templates/_bundled/test-icon-target-tpl',
        ];

        // 스코프 목록이 비면 이 케이스가 아무것도 재지 않는다 — 하한을 먼저 잠근다.
        $this->assertCount(3, $scopes, '대상 아이콘을 빌려 오는 스코프는 세 가지입니다.');

        foreach ($scopes as $scope => $relativeRoot) {
            $target = basename($relativeRoot);
            $this->makeIconRoot($relativeRoot);

            $pack = $this->makePack([
                'identifier' => 'g7-test-borrow-'.$scope.'-ja',
                'scope' => $scope,
                'target_identifier' => $target,
            ]);

            $this->service->annotateIcon($pack);

            $this->assertSame(
                'target',
                $pack->getAttribute('icon_source'),
                $scope.' 팩이 대상 확장의 아이콘을 빌려 오지 않습니다.'
            );
            $this->assertSame(
                '/api/extensions/'.$scope.'/'.$target.'/icon',
                $pack->getAttribute('icon_url'),
                $scope.' 팩의 아이콘 주소가 대상 확장을 가리키지 않습니다.'
            );
        }
    }

    /**
     * 자기 아이콘도 대상 아이콘도 없으면 `none` 이고 주소는 null 입니다.
     *
     * 화면은 이때 유형 글리프 + 로케일 배지로 합성한다(D7).
     *
     * @scenario extension_type=language_pack, declaration=none, surface=list
     *
     * @effects language_pack_icon_source_derivation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function pack_without_any_icon_derives_none(): void
    {
        $pack = $this->makePack([
            'identifier' => 'g7-test-bare-ja',
            'scope' => 'module',
            'target_identifier' => 'test-icon-absent-mod',
        ]);

        $this->service->annotateIcon($pack);

        $this->assertSame('none', $pack->getAttribute('icon_source'));
        $this->assertNull($pack->getAttribute('icon_url'));
    }

    /**
     * 코어가 아닌 스코프는 코어 아이콘으로 떨어지지 않습니다.
     *
     * `core` 갈래의 조건을 넓히면(예: `!== 'core'` 로 뒤집거나 스코프 비교를 지우면)
     * 대상 아이콘이 없는 팩이 **코어 그림**을 달고 나온다 — 그 방향을 잠근다.
     *
     * @scenario extension_type=language_pack, declaration=none, surface=list
     *
     * @effects language_pack_icon_source_derivation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function non_core_pack_never_falls_back_to_core_icon(): void
    {
        foreach (['module', 'plugin', 'template'] as $scope) {
            $pack = $this->makePack([
                'identifier' => 'g7-test-noncore-'.$scope.'-ja',
                'scope' => $scope,
                'target_identifier' => 'test-icon-absent-'.$scope,
            ]);

            $this->service->annotateIcon($pack);

            $this->assertNotSame(
                'core',
                $pack->getAttribute('icon_source'),
                $scope.' 팩이 코어 아이콘으로 떨어졌습니다.'
            );
            $this->assertNotSame(
                ExtensionIconResolver::CORE_ICON_URL,
                $pack->getAttribute('icon_url'),
                $scope.' 팩이 코어 아이콘 주소를 받았습니다.'
            );
        }
    }

    /**
     * 아이콘 관례 파일을 담은 확장 루트를 만듭니다.
     *
     * @param  string  $relativeRoot  base_path 기준 상대 경로
     * @return void 반환값 없음
     */
    private function makeIconRoot(string $relativeRoot): void
    {
        $root = base_path($relativeRoot);
        File::ensureDirectoryExists($root.'/assets');
        $this->fixtureDirs[] = $root;

        File::put($root.'/assets/icon.svg', self::VALID_SVG);
    }

    /**
     * 저장하지 않은 언어팩 모델을 만듭니다.
     *
     * DB 를 타지 않는다 — 이 축은 파생 로직이지 영속성이 아니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return LanguagePack 언어팩 모델
     */
    private function makePack(array $attributes): LanguagePack
    {
        $pack = new LanguagePack;
        $pack->forceFill(array_merge([
            'vendor' => 'g7',
            'locale' => 'ja',
            'locale_native_name' => '日本語',
            'version' => '1.0.0',
        ], $attributes));

        return $pack;
    }
}
