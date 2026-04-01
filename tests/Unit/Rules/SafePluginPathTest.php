<?php

namespace Tests\Unit\Rules;

use App\Rules\SafePluginPath;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SafePluginPathTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = base_path('plugins/test-plugin');

        // 테스트용 디렉토리 및 파일 생성 (realpath 검증을 위해 필요)
        if (! file_exists($this->basePath.'/dist/js/components')) {
            mkdir($this->basePath.'/dist/js/components', 0755, true);
        }

        // 테스트용 파일 생성
        file_put_contents($this->basePath.'/dist/js/plugin.iife.js', 'test');
        file_put_contents($this->basePath.'/dist/js/components/PaymentForm.js', 'test');
    }

    protected function tearDown(): void
    {
        // 테스트용 파일 및 디렉토리 정리
        if (file_exists($this->basePath)) {
            $this->deleteDirectory($this->basePath);
        }
        parent::tearDown();
    }

    /**
     * 디렉토리 재귀 삭제 헬퍼
     */
    private function deleteDirectory(string $dir): bool
    {
        if (! file_exists($dir)) {
            return true;
        }

        if (! is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            if (! $this->deleteDirectory($dir.DIRECTORY_SEPARATOR.$item)) {
                return false;
            }
        }

        return rmdir($dir);
    }

    /**
     * 유효한 경로가 허용되는지 테스트
     */
    public function test_allows_valid_path(): void
    {
        $rule = new SafePluginPath($this->basePath);
        $validator = Validator::make(
            ['path' => 'dist/js/plugin.iife.js'],
            ['path' => $rule]
        );

        $this->assertTrue($validator->passes());
    }

    /**
     * 중첩 경로가 허용되는지 테스트
     */
    public function test_allows_nested_path(): void
    {
        $rule = new SafePluginPath($this->basePath);
        $validator = Validator::make(
            ['path' => 'dist/js/components/PaymentForm.js'],
            ['path' => $rule]
        );

        $this->assertTrue($validator->passes());
    }

    /**
     * 기본 Path Traversal 패턴 차단 테스트 (../)
     */
    public function test_blocks_path_traversal(): void
    {
        $rule = new SafePluginPath($this->basePath);
        $validator = Validator::make(
            ['path' => '../../../.env'],
            ['path' => $rule]
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('path', $validator->errors()->toArray());
    }

    /**
     * 백슬래시 Path Traversal 패턴 차단 테스트 (..\)
     */
    public function test_blocks_backslash_traversal(): void
    {
        $rule = new SafePluginPath($this->basePath);
        $validator = Validator::make(
            ['path' => '..\\..\\..\\config.php'],
            ['path' => $rule]
        );

        $this->assertTrue($validator->fails());
    }

    /**
     * URL 인코딩된 Path Traversal 패턴 차단 테스트
     */
    public function test_blocks_url_encoded_traversal(): void
    {
        $encodedPatterns = [
            '%2e%2e%2f',       // ../
            '%2e%2e/',         // ../
            '%2e%2e%5c',       // ..\
            '..%2f',           // ../
            '..%5c',           // ..\
            '.%2e/',           // ../
        ];

        foreach ($encodedPatterns as $pattern) {
            $rule = new SafePluginPath($this->basePath);
            $validator = Validator::make(
                ['path' => $pattern.'secret.txt'],
                ['path' => $rule]
            );

            $this->assertTrue($validator->fails(), "Pattern {$pattern} should be blocked");
        }
    }

    /**
     * 다중 URL 인코딩 공격 차단 테스트
     */
    public function test_blocks_double_url_encoded(): void
    {
        $rule = new SafePluginPath($this->basePath);
        $validator = Validator::make(
            ['path' => '%252e%252e%252f.env'],  // Double encoded ../
            ['path' => $rule]
        );

        $this->assertTrue($validator->fails());
    }

    /**
     * 절대 경로 차단 테스트 - Windows
     */
    public function test_blocks_windows_absolute_path(): void
    {
        $rule = new SafePluginPath($this->basePath);
        $validator = Validator::make(
            ['path' => 'C:\\Windows\\System32\\config.ini'],
            ['path' => $rule]
        );

        $this->assertTrue($validator->fails());
    }

    /**
     * 절대 경로 차단 테스트 - Linux
     */
    public function test_blocks_linux_absolute_path(): void
    {
        $rule = new SafePluginPath($this->basePath);
        $validator = Validator::make(
            ['path' => '/etc/passwd'],
            ['path' => $rule]
        );

        $this->assertTrue($validator->fails());
    }

    /**
     * NULL 바이트 공격 차단 테스트
     */
    public function test_blocks_null_byte(): void
    {
        $rule = new SafePluginPath($this->basePath);
        $validator = Validator::make(
            ['path' => "malicious.php\0.js"],
            ['path' => $rule]
        );

        $this->assertTrue($validator->fails());
    }

    /**
     * 문자열이 아닌 값 차단 테스트
     */
    public function test_blocks_non_string_value(): void
    {
        $rule = new SafePluginPath($this->basePath);
        $validator = Validator::make(
            ['path' => ['array', 'value']],
            ['path' => $rule]
        );

        $this->assertTrue($validator->fails());
    }

    /**
     * 이중 슬래시 차단 테스트
     */
    public function test_blocks_double_slash(): void
    {
        $rule = new SafePluginPath($this->basePath);
        $validator = Validator::make(
            ['path' => '//etc/passwd'],
            ['path' => $rule]
        );

        $this->assertTrue($validator->fails());
    }
}
