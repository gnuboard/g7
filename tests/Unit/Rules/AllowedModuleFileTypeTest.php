<?php

namespace Tests\Unit\Rules;

use App\Rules\AllowedModuleFileType;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AllowedModuleFileTypeTest extends TestCase
{
    /**
     * JavaScript 파일이 허용되는지 테스트
     */
    public function test_allows_javascript_files(): void
    {
        $rule = new AllowedModuleFileType();

        $jsFiles = ['dist/js/module.iife.js', 'app.js', 'script.mjs'];

        foreach ($jsFiles as $file) {
            $validator = Validator::make(
                ['path' => $file],
                ['path' => $rule]
            );

            $this->assertTrue($validator->passes(), "File {$file} should be allowed");
        }
    }

    /**
     * CSS 파일이 허용되는지 테스트
     */
    public function test_allows_css_files(): void
    {
        $rule = new AllowedModuleFileType();
        $validator = Validator::make(
            ['path' => 'dist/css/module.css'],
            ['path' => $rule]
        );

        $this->assertTrue($validator->passes());
    }

    /**
     * JSON 파일이 허용되는지 테스트
     */
    public function test_allows_json_files(): void
    {
        $rule = new AllowedModuleFileType();
        $validator = Validator::make(
            ['path' => 'data/config.json'],
            ['path' => $rule]
        );

        $this->assertTrue($validator->passes());
    }

    /**
     * Source Map 파일이 허용되는지 테스트
     */
    public function test_allows_sourcemap_files(): void
    {
        $rule = new AllowedModuleFileType();
        $validator = Validator::make(
            ['path' => 'dist/js/module.iife.js.map'],
            ['path' => $rule]
        );

        $this->assertTrue($validator->passes());
    }

    /**
     * 이미지 파일이 허용되는지 테스트
     */
    public function test_allows_image_files(): void
    {
        $rule = new AllowedModuleFileType();
        $imageFiles = [
            'images/logo.png',
            'images/photo.jpg',
            'images/banner.jpeg',
            'icons/arrow.svg',
            'images/hero.webp',
            'images/animation.gif',
            'favicon.ico',
        ];

        foreach ($imageFiles as $file) {
            $validator = Validator::make(
                ['path' => $file],
                ['path' => $rule]
            );

            $this->assertTrue($validator->passes(), "Image file {$file} should be allowed");
        }
    }

    /**
     * 폰트 파일이 허용되는지 테스트
     */
    public function test_allows_font_files(): void
    {
        $rule = new AllowedModuleFileType();
        $fontFiles = [
            'fonts/roboto.woff',
            'fonts/roboto.woff2',
            'fonts/arial.ttf',
            'fonts/custom.otf',
            'fonts/legacy.eot',
        ];

        foreach ($fontFiles as $file) {
            $validator = Validator::make(
                ['path' => $file],
                ['path' => $rule]
            );

            $this->assertTrue($validator->passes(), "Font file {$file} should be allowed");
        }
    }

    /**
     * PHP 파일이 차단되는지 테스트
     */
    public function test_blocks_php_files(): void
    {
        $rule = new AllowedModuleFileType();
        $phpFiles = ['malicious.php', 'index.phtml', 'shell.php3', 'backdoor.php4', 'exploit.php5', 'hack.phps'];

        foreach ($phpFiles as $file) {
            $validator = Validator::make(
                ['path' => $file],
                ['path' => $rule]
            );

            $this->assertTrue($validator->fails(), "PHP file {$file} should be blocked");
        }
    }

    /**
     * 실행 파일이 차단되는지 테스트
     */
    public function test_blocks_executable_files(): void
    {
        $rule = new AllowedModuleFileType();
        $executableFiles = ['malware.exe', 'script.sh', 'run.bat', 'library.dll', 'module.so'];

        foreach ($executableFiles as $file) {
            $validator = Validator::make(
                ['path' => $file],
                ['path' => $rule]
            );

            $this->assertTrue($validator->fails(), "Executable file {$file} should be blocked");
        }
    }

    /**
     * 스크립트 파일이 차단되는지 테스트
     */
    public function test_blocks_script_files(): void
    {
        $rule = new AllowedModuleFileType();
        $scriptFiles = ['exploit.py', 'attack.rb', 'hack.pl', 'malicious.cgi'];

        foreach ($scriptFiles as $file) {
            $validator = Validator::make(
                ['path' => $file],
                ['path' => $rule]
            );

            $this->assertTrue($validator->fails(), "Script file {$file} should be blocked");
        }
    }

    /**
     * 서버 스크립트 파일이 차단되는지 테스트
     */
    public function test_blocks_server_script_files(): void
    {
        $rule = new AllowedModuleFileType();
        $serverScriptFiles = ['page.asp', 'app.aspx', 'index.jsp', 'api.jspx'];

        foreach ($serverScriptFiles as $file) {
            $validator = Validator::make(
                ['path' => $file],
                ['path' => $rule]
            );

            $this->assertTrue($validator->fails(), "Server script file {$file} should be blocked");
        }
    }

    /**
     * 설정 파일이 차단되는지 테스트
     */
    public function test_blocks_config_files(): void
    {
        $rule = new AllowedModuleFileType();
        $configFiles = ['.htaccess', '.htpasswd', '.env'];

        foreach ($configFiles as $file) {
            $validator = Validator::make(
                ['path' => $file],
                ['path' => $rule]
            );

            $this->assertTrue($validator->fails(), "Config file {$file} should be blocked");
        }
    }

    /**
     * 대소문자 구분 없이 차단되는지 테스트
     */
    public function test_case_insensitive_blocking(): void
    {
        $rule = new AllowedModuleFileType();
        $caseVariations = ['MALICIOUS.PHP', 'Exploit.Php', 'SCRIPT.EXE', 'attack.PY'];

        foreach ($caseVariations as $file) {
            $validator = Validator::make(
                ['path' => $file],
                ['path' => $rule]
            );

            $this->assertTrue($validator->fails(), "Case variation {$file} should be blocked");
        }
    }

    /**
     * getAllowedExtensions() 메서드가 올바른 목록을 반환하는지 테스트
     */
    public function test_get_allowed_extensions_returns_correct_list(): void
    {
        $extensions = AllowedModuleFileType::getAllowedExtensions();

        $this->assertIsArray($extensions);
        $this->assertContains('js', $extensions);
        $this->assertContains('css', $extensions);
        $this->assertContains('json', $extensions);
        $this->assertContains('map', $extensions);
        $this->assertContains('png', $extensions);
        $this->assertContains('woff2', $extensions);
    }

    /**
     * 문자열이 아닌 값 차단 테스트
     */
    public function test_blocks_non_string_value(): void
    {
        $rule = new AllowedModuleFileType();
        $validator = Validator::make(
            ['path' => ['array', 'value']],
            ['path' => $rule]
        );

        $this->assertTrue($validator->fails());
    }
}
