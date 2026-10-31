<?php

namespace Tests\Feature\Services;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * 템플릿 메시지 다국어 전환 테스트.
 *
 * `file_copy_failed` 는 `:error` 파라미터를 받는다 — #302 "에러 응답에 :error 파라미터 표준화" 가
 * 메시지 형태를 바꿨고, 그때 이 테스트가 함께 갱신되지 않아 파라미터 없이 원문을 단언하고 있었다.
 * 파라미터를 채워 실제 렌더 결과를 단언한다(치환되지 않은 `:error` 를 정답으로 굳히지 않는다).
 */
class TemplateServiceLocalizationTest extends TestCase
{
    /** 오류 메시지 파라미터 표본. */
    private const COPY_ERROR = 'Permission denied';

    /**
     * 한국어 로케일에서 템플릿 오류 메시지 테스트
     */
    public function test_korean_template_error_messages(): void
    {
        App::setLocale('ko');

        // 메시지는 원인(:error)을 함께 노출한다 — 치환까지 확인해야 자리표시자 누락을 잡는다.
        $this->assertEquals(
            '파일 복사에 실패했습니다: '.self::COPY_ERROR,
            __('templates.errors.file_copy_failed', ['error' => self::COPY_ERROR])
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
            'Failed to copy files: '.self::COPY_ERROR,
            __('templates.errors.file_copy_failed', ['error' => self::COPY_ERROR])
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
        $koreanMessage = __('templates.errors.file_copy_failed', ['error' => self::COPY_ERROR]);
        $this->assertEquals('파일 복사에 실패했습니다: '.self::COPY_ERROR, $koreanMessage);

        // 영어로 전환
        App::setLocale('en');
        $englishMessage = __('templates.errors.file_copy_failed', ['error' => self::COPY_ERROR]);
        $this->assertEquals('Failed to copy files: '.self::COPY_ERROR, $englishMessage);

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
