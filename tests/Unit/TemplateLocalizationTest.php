<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Facades\App;

class TemplateLocalizationTest extends TestCase
{
    /**
     * 한국어 다국어 키 존재 여부 테스트
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
     * 매개변수 치환 테스트
     */
    public function test_translation_parameter_replacement(): void
    {
        App::setLocale('ko');

        $message = __('templates.errors.not_found', ['template' => 'sirsoft-admin']);
        $this->assertStringContainsString('sirsoft-admin', $message);

        $dependencyMessage = __('templates.errors.dependency_not_met', [
            'dependency' => 'sirsoft-ecommerce',
            'type' => 'module',
        ]);
        $this->assertStringContainsString('sirsoft-ecommerce', $dependencyMessage);
        $this->assertStringContainsString('module', $dependencyMessage);

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
