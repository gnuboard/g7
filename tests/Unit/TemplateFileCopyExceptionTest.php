<?php

namespace Tests\Unit;

use App\Exceptions\TemplateFileCopyException;
use Tests\TestCase;

class TemplateFileCopyExceptionTest extends TestCase
{
    /**
     * 다국어 메시지가 올바르게 생성되는지 테스트 (한국어)
     */
    public function test_exception_message_is_localized_in_korean(): void
    {
        app()->setLocale('ko');

        $exception = new TemplateFileCopyException(
            '/path/to/source.css',
            '/path/to/destination.css'
        );

        $this->assertStringContainsString('템플릿 파일 복사 실패', $exception->getMessage());
        $this->assertStringContainsString('/path/to/source.css', $exception->getMessage());
        $this->assertStringContainsString('/path/to/destination.css', $exception->getMessage());
    }

    /**
     * 다국어 메시지가 올바르게 생성되는지 테스트 (영어)
     */
    public function test_exception_message_is_localized_in_english(): void
    {
        app()->setLocale('en');

        $exception = new TemplateFileCopyException(
            '/path/to/source.css',
            '/path/to/destination.css'
        );

        $this->assertStringContainsString('Template file copy failed', $exception->getMessage());
        $this->assertStringContainsString('/path/to/source.css', $exception->getMessage());
        $this->assertStringContainsString('/path/to/destination.css', $exception->getMessage());
    }

    /**
     * Context 정보가 올바르게 전달되는지 테스트
     */
    public function test_context_is_properly_passed(): void
    {
        $exception = new TemplateFileCopyException(
            '/path/to/source.css',
            '/path/to/destination.css',
            'Permission denied'
        );

        $this->assertEquals('Permission denied', $exception->getContext());
        $this->assertStringContainsString('(Permission denied)', $exception->getMessage());
    }

    /**
     * Context가 null인 경우 메시지에 포함되지 않는지 테스트
     */
    public function test_null_context_is_not_included_in_message(): void
    {
        $exception = new TemplateFileCopyException(
            '/path/to/source.css',
            '/path/to/destination.css'
        );

        $this->assertNull($exception->getContext());
        $this->assertStringNotContainsString('()', $exception->getMessage());
    }

    /**
     * Getter 메서드가 올바르게 동작하는지 테스트
     */
    public function test_getters_return_correct_values(): void
    {
        $source = '/templates/sirsoft-admin_basic/dist/layout.css';
        $destination = '/public/build/templates/sirsoft-admin_basic/layout.css';
        $context = 'Disk space full';

        $exception = new TemplateFileCopyException($source, $destination, $context);

        $this->assertEquals($source, $exception->getSourcePath());
        $this->assertEquals($destination, $exception->getDestinationPath());
        $this->assertEquals($context, $exception->getContext());
    }

    /**
     * 다양한 실패 원인을 Context로 전달할 수 있는지 테스트
     */
    public function test_various_failure_contexts(): void
    {
        $contexts = [
            'Disk space full',
            'Permission denied',
            'Source file not found',
            'Destination directory not writable',
        ];

        foreach ($contexts as $context) {
            $exception = new TemplateFileCopyException(
                '/source/file.css',
                '/dest/file.css',
                $context
            );

            $this->assertEquals($context, $exception->getContext());
            $this->assertStringContainsString("({$context})", $exception->getMessage());
        }
    }
}
