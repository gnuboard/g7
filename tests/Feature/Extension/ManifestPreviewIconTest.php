<?php

namespace Tests\Feature\Extension;

use App\Services\LanguagePackService;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * 수동 설치 미리보기의 아이콘 검사 테스트
 *
 * 미리보기는 추출본을 즉시 지우므로 아이콘 검사는 추출 직후 한 번만 할 수 있다.
 * 아이콘 규격 위반은 **설치를 막지 않는다** — `validation.is_valid` 는 매니페스트
 * 기준 그대로이고 아이콘은 경고로만 알린다.
 *
 * @scenario surface=manifest_preview
 *
 * @effects preview_reports_icon_warnings_without_blocking_install
 */
class ManifestPreviewIconTest extends TestCase
{
    use RefreshDatabase;

    /** @var string 정사각 유효 SVG */
    private const VALID_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><title>t</title><rect width="64" height="64" fill="#336699"/></svg>';

    /** @var string 이벤트 핸들러가 박힌 규격 위반 SVG */
    private const UNSAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" onload="alert(1)"/></svg>';

    /** @var list<string> 정리 대상 임시 ZIP */
    private array $tempZips = [];

    /**
     * 임시 ZIP 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->tempZips as $zip) {
            if (is_file($zip)) {
                @unlink($zip);
            }
        }
        $this->tempZips = [];

        parent::tearDown();
    }

    /**
     * 확장 유형별 미리보기 서비스와 매니페스트 파일명을 돌려줍니다.
     *
     * 언어팩도 같은 4변종을 받는다 — 종전에는 정상 아이콘 1변종만 재고 있어 규격 위반·경로
     * 탈출·아이콘 부재에서 언어팩 미리보기가 무엇을 내보내는지 아무도 확인하지 않았다.
     * 언어팩 매니페스트는 필수 키가 더 있어 세 번째 원소로 함께 넘긴다.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> 유형 이름 → [미리보기 서비스 클래스, 매니페스트 파일명, 유형별 매니페스트 필수 키]
     */
    public static function extensionTypeProvider(): array
    {
        return [
            'module' => [ModuleService::class, 'module.json', []],
            'plugin' => [PluginService::class, 'plugin.json', []],
            'template' => [TemplateService::class, 'template.json', []],
            'language_pack' => [LanguagePackService::class, 'language-pack.json', [
                'identifier' => 'g7-plugin-test-preview-ja',
                'namespace' => 'g7',
                'scope' => 'plugin',
                'target_identifier' => 'test-preview',
                'locale' => 'ja',
                'locale_native_name' => '日本語',
            ]],
        ];
    }

    /**
     * 관례 아이콘이 든 패키지는 present·valid 로 보고됩니다.
     *
     * @param  string  $serviceClass  미리보기 서비스 클래스
     * @param  string  $manifestFile  매니페스트 파일명
     * @param  array<string, mixed>  $manifestExtra  유형별 매니페스트 필수 키
     * @return void 반환값 없음
     *
     * @scenario surface=manifest_preview, declaration=convention_svg, validity=valid
     *
     * @effects preview_reports_icon_warnings_without_blocking_install
     */
    #[Test]
    #[DataProvider('extensionTypeProvider')]
    public function convention_icon_is_reported_as_present_and_valid(string $serviceClass, string $manifestFile, array $manifestExtra): void
    {
        $zip = $this->makeZip($manifestFile, $manifestExtra, ['assets/icon.svg' => self::VALID_SVG]);

        $result = app($serviceClass)->previewManifest($zip);

        $this->assertSame(true, $result['icon']['present']);
        $this->assertSame(true, $result['icon']['valid']);
        $this->assertSame('convention', $result['icon']['source']);
        $this->assertSame([], $result['icon']['warnings']);
        $this->assertTrue($result['validation']['is_valid'], '아이콘은 설치 판정에 영향을 주지 않아야 합니다.');
    }

    /**
     * 규격 위반 아이콘은 경고로 보고되되 설치 판정은 그대로입니다.
     *
     * @param  string  $serviceClass  미리보기 서비스 클래스
     * @param  string  $manifestFile  매니페스트 파일명
     * @param  array<string, mixed>  $manifestExtra  유형별 매니페스트 필수 키
     * @return void 반환값 없음
     *
     * @scenario surface=manifest_preview, declaration=convention_svg, validity=invalid_svg
     *
     * @effects preview_reports_icon_warnings_without_blocking_install
     */
    #[Test]
    #[DataProvider('extensionTypeProvider')]
    public function invalid_icon_is_warned_without_blocking_install(string $serviceClass, string $manifestFile, array $manifestExtra): void
    {
        $zip = $this->makeZip($manifestFile, $manifestExtra, ['assets/icon.svg' => self::UNSAFE_SVG]);

        $result = app($serviceClass)->previewManifest($zip);

        $this->assertSame(true, $result['icon']['present']);
        $this->assertSame(false, $result['icon']['valid']);
        $this->assertSame(
            ['svg_event_handler_attribute'],
            array_column($result['icon']['warnings'], 'code')
        );
        $this->assertTrue($result['validation']['is_valid'], '아이콘 규격 위반은 설치를 막지 않아야 합니다.');
    }

    /**
     * 매니페스트 icon 경로가 패키지 밖을 가리키면 경고 후 관례로 폴백합니다.
     *
     * @param  string  $serviceClass  미리보기 서비스 클래스
     * @param  string  $manifestFile  매니페스트 파일명
     * @param  array<string, mixed>  $manifestExtra  유형별 매니페스트 필수 키
     * @return void 반환값 없음
     *
     * @scenario surface=manifest_preview, declaration=manifest_path, validity=path_escape
     *
     * @effects preview_reports_icon_warnings_without_blocking_install
     */
    #[Test]
    #[DataProvider('extensionTypeProvider')]
    public function manifest_icon_escaping_the_package_is_warned(string $serviceClass, string $manifestFile, array $manifestExtra): void
    {
        $zip = $this->makeZip($manifestFile, $manifestExtra + ['icon' => '../../etc/passwd.svg'], ['assets/icon.svg' => self::VALID_SVG]);

        $result = app($serviceClass)->previewManifest($zip);

        $this->assertSame('convention', $result['icon']['source']);
        $this->assertContains('manifest_icon_traversal', array_column($result['icon']['warnings'], 'code'));

        // 아이콘 경고 자체는 설치 판정을 바꾸지 않는다(D6) — `validation.errors` 에 섞지 않는다.
        $this->assertArrayNotHasKey('icon', $result['validation']['errors'] ?? []);

        // 다만 언어팩만 매니페스트 스키마 검증기를 가지며, 그 검증기는 패키지 밖을 가리키는
        // 선언을 설치 전에 막는다(계획 §8.1). 모듈·플러그인·템플릿은 스키마 검증기가 없어
        // 아이콘이 설치 판정에 닿지 않는다.
        if ($manifestFile === 'language-pack.json') {
            $this->assertFalse(
                $result['validation']['is_valid'],
                '언어팩 스키마 검증기는 패키지 밖 icon 경로를 거부해야 합니다.'
            );
        } else {
            $this->assertTrue(
                $result['validation']['is_valid'],
                '스키마 검증기가 없는 유형에서 아이콘이 설치를 막아서는 안 됩니다.'
            );
        }
    }

    /**
     * 아이콘이 없는 패키지는 present=false·경고 없음으로 보고됩니다.
     *
     * @param  string  $serviceClass  미리보기 서비스 클래스
     * @param  string  $manifestFile  매니페스트 파일명
     * @param  array<string, mixed>  $manifestExtra  유형별 매니페스트 필수 키
     * @return void 반환값 없음
     *
     * @scenario surface=manifest_preview, declaration=none
     *
     * @effects preview_reports_icon_warnings_without_blocking_install
     */
    #[Test]
    #[DataProvider('extensionTypeProvider')]
    public function package_without_icon_reports_absence_without_warnings(string $serviceClass, string $manifestFile, array $manifestExtra): void
    {
        $zip = $this->makeZip($manifestFile, $manifestExtra, []);

        $result = app($serviceClass)->previewManifest($zip);

        $this->assertSame(false, $result['icon']['present']);
        $this->assertSame('none', $result['icon']['source']);
        $this->assertSame([], $result['icon']['warnings']);
        $this->assertTrue($result['validation']['is_valid']);
    }

    /**
     * 미리보기 아이콘 축을 재는 모집단이 비어 있지 않습니다.
     *
     * 유형이 빠지면 그 유형의 변종 네 개가 케이스 없이 사라지는데, 데이터 프로바이더가
     * 조용히 줄어들면 「전부 통과」로 보인다. 유형 수를 직접 잠근다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=manifest_preview
     *
     * @effects preview_reports_icon_warnings_without_blocking_install
     */
    #[Test]
    public function every_extension_type_is_covered_by_the_preview_icon_axes(): void
    {
        $types = array_keys(self::extensionTypeProvider());

        $this->assertSame(
            ['module', 'plugin', 'template', 'language_pack'],
            $types,
            '미리보기 아이콘 변종은 4유형 전량에 걸려야 합니다.'
        );
    }

    /**
     * 매니페스트와 파일을 담은 임시 ZIP 업로드 파일을 만듭니다.
     *
     * @param  string  $manifestFile  매니페스트 파일명
     * @param  array<string, mixed>  $manifestExtra  매니페스트에 덧붙일 키
     * @param  array<string, string>  $files  ZIP 안 상대경로 → 내용
     * @return UploadedFile 업로드 파일
     */
    private function makeZip(string $manifestFile, array $manifestExtra, array $files): UploadedFile
    {
        $path = storage_path('app/temp/preview-icon-'.bin2hex(random_bytes(6)).'.zip');
        File::ensureDirectoryExists(dirname($path));
        $this->tempZips[] = $path;

        $manifest = array_merge([
            'identifier' => 'test-preview',
            'version' => '1.0.0',
            'vendor' => 'test',
            'name' => ['ko' => '미리보기 픽스처', 'en' => 'Preview fixture'],
            'description' => ['ko' => '아이콘 미리보기 테스트', 'en' => 'Icon preview test'],
        ], $manifestExtra);

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($manifestFile, json_encode($manifest, JSON_UNESCAPED_UNICODE));
        foreach ($files as $relative => $contents) {
            $zip->addFromString($relative, $contents);
        }
        $zip->close();

        return new UploadedFile($path, basename($path), 'application/zip', null, true);
    }
}
