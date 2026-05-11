<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Services\LanguagePack\LanguagePackManifestValidator;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 공식 일본어 번들 언어팩 12종의 manifest 무결성 검증.
 *
 * lang-packs/_bundled/g7-*-ja/language-pack.json 이 production validator 를 통과하고,
 * 패키지 디렉토리에 선언된 파일들이 실제 존재함을 검증한다.
 *
 * 번들 미배포 상태에서는 자동 스킵 (CI 안전).
 */
class BundledJapanesePacksTest extends TestCase
{
    /** @var array<int, string> 공식 ja 패키지 식별자 12종 */
    private const PACKS = [
        'g7-core-ja',
        'g7-template-sirsoft-admin_basic-ja',
        'g7-template-sirsoft-basic-ja',
        'g7-template-gnuboard7-hello_admin_template-ja',
        'g7-template-gnuboard7-hello_user_template-ja',
        'g7-module-sirsoft-ecommerce-ja',
        'g7-module-sirsoft-board-ja',
        'g7-module-sirsoft-page-ja',
        'g7-module-gnuboard7-hello_module-ja',
        'g7-plugin-sirsoft-ckeditor5-ja',
        'g7-plugin-sirsoft-marketing-ja',
        'g7-plugin-sirsoft-tosspayments-ja',
    ];

    /**
     * 12개 ja 팩의 manifest 가 모두 LanguagePackManifestValidator 를 통과하는지 검증.
     *
     * @return void
     */
    public function test_all_japanese_bundled_manifests_pass_validator(): void
    {
        $validator = $this->app->make(LanguagePackManifestValidator::class);
        $checked = 0;

        foreach (self::PACKS as $identifier) {
            $packageRoot = base_path('lang-packs/_bundled/'.$identifier);
            $manifestPath = $packageRoot.'/language-pack.json';
            if (! File::isFile($manifestPath)) {
                continue;
            }

            $manifest = json_decode(File::get($manifestPath), true);
            $this->assertIsArray($manifest, "[$identifier] manifest 가 올바른 JSON 이어야 함");

            $validator->validate($manifest, $packageRoot);

            $this->assertSame($identifier, $manifest['identifier']);
            $this->assertSame('ja', $manifest['locale']);
            $this->assertSame('日本語', $manifest['locale_native_name']);
            $this->assertSame('g7', $manifest['namespace'], "[$identifier] namespace=g7 (모든 G7 공식 번들의 공통 prefix)");
            $this->assertNotEmpty($manifest['vendor'], "[$identifier] vendor 는 제작자 식별자 (모듈/플러그인 매니페스트와 일관)");
            $this->assertIsArray($manifest['name'], "[$identifier] name 은 다국어 객체 (모듈/플러그인과 일관)");

            $checked++;
        }

        if ($checked === 0) {
            $this->markTestSkipped('일본어 ja 팩이 아직 빌드되지 않음 — build-language-pack-ja.cjs 실행 후 재시도');
        }
    }

    /**
     * 12개 ja 팩 디렉토리에 backend/frontend/seed 중 최소 1개 카테고리의 파일이 존재하는지 검증.
     *
     * 매니페스트의 `contents` 필드는 모듈/플러그인/템플릿 매니페스트와의 일관성을 위해 제거됨 —
     * 파일 인벤토리는 디스크 스캔이 SSoT.
     *
     * @return void
     */
    public function test_all_japanese_bundled_contents_files_exist(): void
    {
        $checked = 0;

        foreach (self::PACKS as $identifier) {
            $packageRoot = base_path('lang-packs/_bundled/'.$identifier);
            if (! File::isDirectory($packageRoot)) {
                continue;
            }

            $hasFiles = false;
            foreach (['backend', 'frontend', 'seed'] as $bucket) {
                $bucketDir = $packageRoot.DIRECTORY_SEPARATOR.$bucket;
                if (File::isDirectory($bucketDir) && count(File::allFiles($bucketDir)) > 0) {
                    $hasFiles = true;
                    break;
                }
            }

            $this->assertTrue($hasFiles, "[$identifier] backend/frontend/seed 디렉토리에 파일이 1개 이상 존재해야 함");

            $checked++;
        }

        if ($checked === 0) {
            $this->markTestSkipped('일본어 ja 팩이 아직 빌드되지 않음 — build-language-pack-ja.cjs 실행 후 재시도');
        }
    }

    /**
     * 12개 ja 팩의 backend PHP 파일과 frontend JSON 파일이 한국어 원본과 동일한 키 집합을 가지는지 검증.
     *
     * 키 누락은 i18n fallback 누수로 이어지므로 bottom-line 무결성 검사.
     * 본 테스트는 g7-core-ja 만 샘플 검증 (전체 검증은 비용 대비 효과 낮음).
     *
     * @return void
     */
    public function test_g7_core_ja_backend_keys_match_korean_origin(): void
    {
        $packageRoot = base_path('lang-packs/_bundled/g7-core-ja');
        $jaDir = $packageRoot.'/backend/ja';
        if (! File::isDirectory($jaDir)) {
            $this->markTestSkipped('g7-core-ja 가 아직 빌드되지 않음');
        }

        $koDir = base_path('lang/ko');
        $missing = [];
        foreach (File::files($jaDir) as $jaFile) {
            $name = $jaFile->getFilename();
            $koFile = $koDir.DIRECTORY_SEPARATOR.$name;
            $this->assertFileExists($koFile, "[$name] ko 원본 부재");

            $jaArr = require $jaFile->getRealPath();
            $koArr = require $koFile;
            $jaKeys = $this->flattenKeys($jaArr);
            $koKeys = $this->flattenKeys($koArr);
            $diff = array_diff($koKeys, $jaKeys);
            if (! empty($diff)) {
                $missing[$name] = array_values($diff);
            }
        }

        $this->assertEmpty($missing, '키 누락: '.json_encode($missing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 중첩 배열을 점 표기 키 목록으로 평탄화.
     *
     * @param  array<string, mixed>  $arr
     * @param  string  $prefix
     * @return array<int, string>
     */
    private function flattenKeys(array $arr, string $prefix = ''): array
    {
        $keys = [];
        foreach ($arr as $k => $v) {
            $cur = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            if (is_array($v)) {
                $keys = array_merge($keys, $this->flattenKeys($v, $cur));
            } else {
                $keys[] = $cur;
            }
        }

        return $keys;
    }
}
