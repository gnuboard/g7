<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

class TemplateLocalizationTest extends TestCase
{
    /**
     * 한국어 다국어 키 존재 여부 테스트
     *
     * @scenario locale=ko, check=keys
     *
     * @effects template_lang_keys_exist_in_base_locales
     *
     * @return void 반환값 없음
     */
    public function test_korean_translation_keys_exist(): void
    {
        App::setLocale('ko');

        // 에러 메시지
        $this->assertNotEmpty(__('templates.errors.not_found'));
        $this->assertNotEmpty(__('templates.errors.not_installed'));
        $this->assertNotEmpty(__('templates.errors.already_active'));
        $this->assertNotEmpty(__('templates.errors.dependency_not_met'));
        $this->assertNotEmpty(__('templates.errors.version_mismatch'));
        $this->assertNotEmpty(__('templates.errors.file_copy_failed'));

        // 성공 메시지
        $this->assertNotEmpty(__('templates.messages.activated'));
        $this->assertNotEmpty(__('templates.messages.deactivated'));
        $this->assertNotEmpty(__('templates.messages.files_copied'));

        // 정보 메시지
        $this->assertNotEmpty(__('templates.info.scanning'));
        $this->assertNotEmpty(__('templates.info.loading'));
        $this->assertNotEmpty(__('templates.info.public_directory_cleaned'));
    }

    /**
     * 영어 다국어 키 존재 여부 테스트
     *
     * @scenario locale=en, check=keys
     *
     * @effects template_lang_keys_exist_in_base_locales
     *
     * @return void 반환값 없음
     */
    public function test_english_translation_keys_exist(): void
    {
        App::setLocale('en');

        // 에러 메시지
        $this->assertNotEmpty(__('templates.errors.not_found'));
        $this->assertNotEmpty(__('templates.errors.not_installed'));
        $this->assertNotEmpty(__('templates.errors.already_active'));
        $this->assertNotEmpty(__('templates.errors.dependency_not_met'));
        $this->assertNotEmpty(__('templates.errors.version_mismatch'));
        $this->assertNotEmpty(__('templates.errors.file_copy_failed'));

        // 성공 메시지
        $this->assertNotEmpty(__('templates.messages.activated'));
        $this->assertNotEmpty(__('templates.messages.deactivated'));
        $this->assertNotEmpty(__('templates.messages.files_copied'));

        // 정보 메시지
        $this->assertNotEmpty(__('templates.info.scanning'));
        $this->assertNotEmpty(__('templates.info.loading'));
        $this->assertNotEmpty(__('templates.info.public_directory_cleaned'));
    }

    /**
     * 한국어 메시지 내용 검증
     *
     * @scenario locale=ko, check=content
     *
     * @effects template_lang_representative_content
     *
     * @return void 반환값 없음
     */
    public function test_korean_translation_content(): void
    {
        App::setLocale('ko');

        $this->assertStringContainsString('템플릿을 찾을 수 없습니다', __('templates.errors.not_found'));
        $this->assertStringContainsString('설치되지 않았습니다', __('templates.errors.not_installed'));
        $this->assertStringContainsString('활성화된 템플릿', __('templates.errors.already_active'));
        $this->assertStringContainsString('활성화되었습니다', __('templates.messages.activated'));
        $this->assertStringContainsString('스캔하는 중', __('templates.info.scanning'));
    }

    /**
     * 영어 메시지 내용 검증
     *
     * @scenario locale=en, check=content
     *
     * @effects template_lang_representative_content
     *
     * @return void 반환값 없음
     */
    public function test_english_translation_content(): void
    {
        App::setLocale('en');

        $this->assertStringContainsString('not found', __('templates.errors.not_found'));
        $this->assertStringContainsString('not installed', __('templates.errors.not_installed'));
        $this->assertStringContainsString('already active', __('templates.errors.already_active'));
        $this->assertStringContainsString('activated successfully', __('templates.messages.activated'));
        $this->assertStringContainsString('Scanning', __('templates.info.scanning'));
    }

    /**
     * 매개변수 치환 테스트 — 템플릿 의존 미충족 문구의 종류는 현지화한 이름(「모듈」)으로 치환된다.
     *
     * @scenario locale=ko, check=params
     *
     * @effects template_lang_params_replaced_with_localized_type
     *
     * @return void 반환값 없음
     */
    public function test_translation_parameter_replacement(): void
    {
        App::setLocale('ko');

        $message = __('templates.errors.not_found', ['template' => 'sirsoft-admin']);
        $this->assertStringContainsString('sirsoft-admin', $message);

        // 종류는 현지화한 이름으로 넘긴다 — TemplateManager::checkDependencies() 와 같다(#153, 한국어 문장에 `module` 원문이 섞이던 결함)
        $dependencyMessage = __('templates.errors.dependency_not_met', [
            'dependency' => 'sirsoft-ecommerce',
            'type' => __('extensions.dependency_types.module'),
        ]);
        $this->assertStringContainsString('sirsoft-ecommerce', $dependencyMessage);
        $this->assertStringContainsString(__('extensions.dependency_types.module'), $dependencyMessage);
        $this->assertSame('모듈', __('extensions.dependency_types.module', [], 'ko'));
        $this->assertSame('플러그인', __('extensions.dependency_types.plugin', [], 'ko'));

        $versionMessage = __('templates.errors.version_mismatch', [
            'dependency' => 'sirsoft-blog',
            'required' => '>=1.0.0',
            'installed' => '0.9.0',
        ]);
        $this->assertStringContainsString('sirsoft-blog', $versionMessage);
        $this->assertStringContainsString('>=1.0.0', $versionMessage);
        $this->assertStringContainsString('0.9.0', $versionMessage);
    }

    /**
     * 모든 TemplateManager에서 사용되는 키가 다국어 파일에 존재하는지 검증
     *
     * @scenario locale=en, check=keys
     *
     * @effects template_manager_used_keys_exist
     *
     * @return void 반환값 없음
     */
    public function test_all_template_manager_keys_exist(): void
    {
        App::setLocale('ko');

        // TemplateManager에서 사용되는 모든 키
        $usedKeys = [
            'templates.errors.not_found',
            'templates.errors.not_installed',
            'templates.errors.already_active',
            'templates.errors.file_copy_failed',
            'templates.errors.dependency_not_met',
            'templates.errors.version_mismatch',
            'templates.messages.files_copied',
            'templates.info.public_directory_cleaned',
        ];

        foreach ($usedKeys as $key) {
            $translation = __($key);
            $this->assertNotEquals($key, $translation, "Translation key '{$key}' is missing");
            $this->assertNotEmpty($translation, "Translation for '{$key}' is empty");
        }
    }

    /**
     * 상태 및 타입 레이블 테스트
     *
     * @scenario locale=ko, check=labels
     *
     * @effects template_status_and_type_labels
     *
     * @return void 반환값 없음
     */
    public function test_status_and_type_labels(): void
    {
        App::setLocale('ko');

        // 상태 레이블
        $this->assertEquals('활성', __('templates.status.active'));
        $this->assertEquals('비활성', __('templates.status.inactive'));
        $this->assertEquals('설치 중', __('templates.status.installing'));
        $this->assertEquals('제거 중', __('templates.status.uninstalling'));

        // 타입 레이블
        $this->assertEquals('관리자용', __('templates.types.admin'));
        $this->assertEquals('사용자용', __('templates.types.user'));
    }

    /**
     * 상태 및 타입 레이블 테스트 (영어)
     *
     * @scenario locale=en, check=labels
     *
     * @effects template_status_and_type_labels
     *
     * @return void 반환값 없음
     */
    public function test_status_and_type_labels_in_english(): void
    {
        App::setLocale('en');

        // 상태 레이블 (영어)
        $this->assertEquals('Active', __('templates.status.active'));
        $this->assertEquals('Inactive', __('templates.status.inactive'));
        $this->assertEquals('Installing', __('templates.status.installing'));
        $this->assertEquals('Uninstalling', __('templates.status.uninstalling'));

        // 타입 레이블 (영어)
        $this->assertEquals('Admin', __('templates.types.admin'));
        $this->assertEquals('User', __('templates.types.user'));
    }
}
