<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Services\LanguagePack\LanguagePackManifestValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * LanguagePackManifestValidator 단위 테스트.
 *
 * 신규 매니페스트 스키마 (namespace + vendor 분리, top-level g7_version) 기준 케이스.
 */
class LanguagePackManifestValidatorTest extends TestCase
{
    private LanguagePackManifestValidator $validator;

    /**
     * 테스트 픽스처를 초기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new LanguagePackManifestValidator;
    }

    /**
     * 코어 manifest 의 기본 형태를 반환합니다 (신규 스키마).
     *
     * @return array<string, mixed> 매니페스트 배열
     */
    private function coreManifest(): array
    {
        return [
            'identifier' => 'g7-core-ja',
            'namespace' => 'g7',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '일본어 언어팩', 'en' => 'Japanese language pack', 'ja' => '日本語 言語パック'],
            'version' => '1.0.0',
            'scope' => 'core',
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_native_name' => '日本語',
            'g7_version' => '>=7.0.0-beta.4',
            'requires' => [
                'depends_on_core_locale' => false,
            ],
        ];
    }

    /**
     * 모듈 manifest 의 기본 형태를 반환합니다.
     *
     * @return array<string, mixed> 매니페스트 배열
     */
    private function moduleManifest(): array
    {
        return [
            'identifier' => 'g7-module-sirsoft-ecommerce-ja',
            'namespace' => 'g7',
            'vendor' => 'acme',
            'name' => ['ko' => '...', 'en' => '...', 'ja' => '...'],
            'version' => '1.0.0',
            'scope' => 'module',
            'target_identifier' => 'sirsoft-ecommerce',
            'locale' => 'ja',
            'locale_native_name' => '日本語',
            'g7_version' => '>=7.0.0-beta.4',
            'requires' => [
                'target_version' => '^2.3.0',
                'depends_on_core_locale' => true,
            ],
        ];
    }

    /**
     * 정상적인 코어 매니페스트는 통과합니다.
     *
     * @scenario extension_type=language_pack
     *
     * @effects manifest_schema_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_01_valid_core_manifest_passes(): void
    {
        $this->validator->validate($this->coreManifest());
        $this->assertTrue(true);
    }

    /**
     * 정상적인 모듈 매니페스트는 통과합니다.
     *
     * @scenario extension_type=language_pack
     *
     * @effects manifest_schema_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_02_valid_module_manifest_passes(): void
    {
        $this->validator->validate($this->moduleManifest());
        $this->assertTrue(true);
    }

    /**
     * identifier 의 첫 세그먼트가 namespace 와 다르면 거부합니다.
     *
     * @scenario extension_type=language_pack
     *
     * @effects manifest_schema_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_03_identifier_first_segment_must_match_namespace(): void
    {
        $manifest = $this->coreManifest();
        $manifest['namespace'] = 'g7';
        $manifest['identifier'] = 'acme-core-ja'; // namespace=g7 가 아님

        $this->expectException(ValidationException::class);
        $this->validator->validate($manifest);
    }

    /**
     * scope=core 에 target_identifier 가 있으면 거부합니다.
     *
     * @scenario extension_type=language_pack
     *
     * @effects manifest_schema_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_04_core_must_not_have_target_identifier(): void
    {
        $manifest = $this->coreManifest();
        $manifest['target_identifier'] = 'sirsoft-ecommerce';

        $this->expectException(ValidationException::class);
        $this->validator->validate($manifest);
    }

    /**
     * scope=module 에 target_identifier 가 없으면 거부합니다.
     *
     * @scenario extension_type=language_pack
     *
     * @effects manifest_schema_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_05_module_requires_target_identifier(): void
    {
        $manifest = $this->moduleManifest();
        $manifest['target_identifier'] = null;

        $this->expectException(ValidationException::class);
        $this->validator->validate($manifest);
    }

    /**
     * 예약어 namespace 는 거부합니다.
     *
     * @scenario extension_type=language_pack
     *
     * @effects manifest_schema_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_06_reserved_namespace_rejected(): void
    {
        $manifest = $this->coreManifest();
        $manifest['namespace'] = 'module';
        $manifest['identifier'] = 'module-core-ja';

        $this->expectException(ValidationException::class);
        $this->validator->validate($manifest);
    }

    /**
     * BCP-47 형식이 아닌 locale 은 거부합니다.
     *
     * @scenario extension_type=language_pack
     *
     * @effects manifest_schema_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_07_invalid_locale_rejected(): void
    {
        $manifest = $this->coreManifest();
        $manifest['locale'] = 'japanese';
        $manifest['identifier'] = 'g7-core-japanese';

        $this->expectException(ValidationException::class);
        $this->validator->validate($manifest);
    }

    /**
     * g7_version 이 semver 제약이 아니면 거부합니다.
     *
     * @scenario extension_type=language_pack
     *
     * @effects manifest_schema_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_08_invalid_g7_version_constraint_rejected(): void
    {
        $manifest = $this->coreManifest();
        $manifest['g7_version'] = '@@@invalid@@@';

        $this->expectException(ValidationException::class);
        $this->validator->validate($manifest);
    }

    /**
     * requires.target_version 이 semver 제약이 아니면 거부합니다.
     *
     * @scenario extension_type=language_pack
     *
     * @effects manifest_schema_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_09_invalid_target_version_constraint_rejected(): void
    {
        $manifest = $this->moduleManifest();
        $manifest['requires']['target_version'] = '@@@invalid@@@';

        $this->expectException(ValidationException::class);
        $this->validator->validate($manifest);
    }

    /**
     * identifier 가 스코프별 네이밍 공식과 어긋나면 거부합니다.
     *
     * @scenario extension_type=language_pack
     *
     * @effects manifest_schema_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_10_identifier_naming_format_mismatch_rejected(): void
    {
        $manifest = $this->moduleManifest();
        // segment 부족 (target 없이) — namespace-module-ja 만으로는 module 스코프 공식과 불일치
        $manifest['identifier'] = 'g7-module-ja';

        $this->expectException(ValidationException::class);
        $this->validator->validate($manifest);
    }

    /**
     * vendor 가 kebab-case 가 아니면 거부합니다.
     *
     * @scenario extension_type=language_pack
     *
     * @effects manifest_schema_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_11_vendor_required_and_kebab_case(): void
    {
        $manifest = $this->coreManifest();
        $manifest['vendor'] = '1Invalid'; // 숫자로 시작

        $this->expectException(ValidationException::class);
        $this->validator->validate($manifest);
    }

    /**
     * 패키지 밖을 가리키는 icon 선언은 설치 전에 막힙니다.
     *
     * 규격(정사각·크기)은 파일을 읽어야 알 수 있어 해석기가 맡지만, 경로가 루트를
     * 벗어나는 것은 문자열만으로 판정되므로 여기서 거른다.
     *
     * @scenario extension_type=language_pack, declaration=manifest_path, validity=path_escape
     *
     * @effects manifest_icon_path_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_12_icon_path_escaping_package_rejected(): void
    {
        $manifest = $this->coreManifest();
        $manifest['icon'] = '../../etc/passwd.svg';

        $this->expectException(ValidationException::class);
        $this->validator->validate($manifest);
    }

    /**
     * 관례를 따르는 icon 선언은 통과합니다.
     *
     * @scenario extension_type=language_pack, declaration=manifest_path, validity=valid
     *
     * @effects manifest_icon_path_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_13_icon_relative_path_accepted(): void
    {
        $manifest = $this->coreManifest();
        $manifest['icon'] = 'assets/icon.svg';

        $this->validator->validate($manifest);
        $this->addToAssertionCount(1);
    }

    /**
     * icon 은 선택 필드라 없어도 통과합니다.
     *
     * @scenario extension_type=language_pack, declaration=none
     *
     * @effects manifest_icon_path_is_validated
     *
     * @return void 반환값 없음
     */
    public function test_case_14_icon_absent_is_accepted(): void
    {
        $manifest = $this->coreManifest();
        unset($manifest['icon']);

        $this->validator->validate($manifest);
        $this->addToAssertionCount(1);
    }
}
