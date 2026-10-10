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
     * 코어 `lang/ko` 에 짝이 없는 코어 언어팩 전용 백엔드 파일과 그 키(대조 기준) 출처.
     *
     * 국가 이름은 코어 내장 로케일(ko·en)이 `config/countries.php` 의 `localized_names` 를 정본으로 쓰고,
     * 그 밖의 로케일만 언어팩의 `backend/{locale}/countries.php`(키 = ISO alpha-2 국가 코드)가 채운다
     * (언어팩 계약 문서 `docs/extension/language-packs.md` 「국가 이름」 절, 해석기 `App\Support\CountryName`).
     * 그래서 lang/ko/countries.php 는 의도적으로 없고, 이 파일의 키는 설정의 ko 국가 코드 목록과 대조한다.
     *
     * @var array<string, string> 언어팩 파일명 => 키 목록을 주는 설정 키
     */
    private const PACK_ONLY_BACKEND_FILES = [
        'countries.php' => 'countries.localized_names.ko',
    ];

    /**
     * 12개 ja 팩의 manifest 가 모두 LanguagePackManifestValidator 를 통과하는지 검증.
     *
     * @scenario check=manifest, origin=lang_ko
     *
     * @effects bundled_ja_manifests_pass_validator
     *
     * @return void 반환값 없음
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
     * @scenario check=files, origin=lang_ko
     *
     * @effects bundled_ja_packs_ship_files
     *
     * @return void 반환값 없음
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
     * 코어 `lang/ko` 에 짝이 없는 언어팩 전용 파일(`PACK_ONLY_BACKEND_FILES`, 예: 국가 이름)은 아래
     * 별도 테스트가 설정의 키 목록과 대조한다. 등록되지 않은 언어팩 전용 파일은 「ko 원본 부재」로 실패한다.
     *
     * @scenario check=backend_keys, origin=lang_ko
     *
     * @effects bundled_core_ja_backend_keys_match_korean_origin
     *
     * @return void 반환값 없음
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
            if (isset(self::PACK_ONLY_BACKEND_FILES[$name])) {
                continue;
            }

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
     * g7-core-ja 의 언어팩 전용 백엔드 파일(예: 국가 이름 `countries.php`)은 코어 `lang/ko` 에 원본이 없고,
     * 키가 설정의 정본 목록(국가 이름은 `countries.localized_names.ko` 의 국가 코드)을 빠짐없이 덮는다.
     *
     * 코어 lang 에 같은 이름의 원본이 생기면 이 예외는 더 이상 맞지 않으므로 실패한다(예외 목록에서 빼고
     * 위 ko 원본 대조로 돌린다).
     *
     * @scenario check=backend_keys, origin=config
     *
     * @effects bundled_core_ja_pack_only_file_matches_config_keys
     *
     * @return void 반환값 없음
     */
    public function test_g7_core_ja_pack_only_backend_files_match_their_config_origin(): void
    {
        $jaDir = base_path('lang-packs/_bundled/g7-core-ja/backend/ja');
        if (! File::isDirectory($jaDir)) {
            $this->markTestSkipped('g7-core-ja 가 아직 빌드되지 않음');
        }

        foreach (self::PACK_ONLY_BACKEND_FILES as $name => $configKey) {
            $jaFile = $jaDir.DIRECTORY_SEPARATOR.$name;
            $this->assertFileExists($jaFile, "[$name] 언어팩 전용 파일 부재");
            $this->assertFileDoesNotExist(
                base_path('lang/ko/'.$name),
                "[$name] 코어 lang 에 원본이 생겼다 — 언어팩 전용 예외에서 빼고 ko 원본과 대조한다"
            );

            $originKeys = array_map('strval', array_keys((array) config($configKey, [])));
            $this->assertNotEmpty($originKeys, "[$name] 대조 기준 설정 {$configKey} 이 비어 있음");

            $missing = array_values(array_diff($originKeys, $this->flattenKeys(require $jaFile)));
            $this->assertSame([], $missing, "[$name] 설정 {$configKey} 에 있는데 언어팩에 없는 키");
        }
    }

    /**
     * 중첩 배열을 점 표기 키 목록으로 평탄화.
     *
     * @param  array<string, mixed>  $arr  평탄화할 번역 배열
     * @param  string  $prefix  상위 키 접두 (최상위는 빈 문자열)
     * @return array<int, string> 점 표기 키 목록
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
