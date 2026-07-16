<?php

namespace Tests\Feature\Services;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

class TemplateServiceLocalizationTest extends TestCase
{
    /**
     * 한국어 로케일에서 템플릿 오류 메시지 테스트
     */
    public function test_korean_template_error_messages(): void
    {
        App::setLocale('ko');

        $this->assertEquals(
            '파일 복사에 실패했습니다.',
            __('templates.errors.file_copy_failed')
        );

        $this->assertEquals(
            '템플릿 빌드 결과물(dist)을 찾을 수 없습니다.',
            __('templates.errors.dist_directory_not_found')
        );
    }

    /**
     * 영어 로케일에서 템플릿 오류 메시지 테스트
     */
    public function test_english_template_error_messages(): void
    {
        App::setLocale('en');

        $this->assertEquals(
            'Failed to copy files.',
            __('templates.errors.file_copy_failed')
        );

        $this->assertEquals(
            'Template build output (dist) not found.',
            __('templates.errors.dist_directory_not_found')
        );
    }

    /**
     * 로케일 전환 시 메시지 변경 확인 테스트
     */
    public function test_locale_switching_changes_messages(): void
    {
        // 한국어로 설정
        App::setLocale('ko');
        $koreanMessage = __('templates.errors.file_copy_failed');
        $this->assertEquals('파일 복사에 실패했습니다.', $koreanMessage);

        // 영어로 전환
        App::setLocale('en');
        $englishMessage = __('templates.errors.file_copy_failed');
        $this->assertEquals('Failed to copy files.', $englishMessage);

        // 메시지가 다른지 확인
        $this->assertNotEquals($koreanMessage, $englishMessage);
    }

    /**
     * 기타 템플릿 메시지 다국어 지원 테스트
     */
    public function test_other_template_messages_localization(): void
    {
        // 한국어
        App::setLocale('ko');
        $this->assertEquals('템플릿 관리', __('templates.title'));
        $this->assertEquals('템플릿이 활성화되었습니다.', __('templates.messages.activated'));

        // 영어
        App::setLocale('en');
        $this->assertEquals('Template Management', __('templates.title'));
        $this->assertEquals('Template activated successfully.', __('templates.messages.activated'));
    }
}
