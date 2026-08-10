<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Services\LanguagePack\LanguagePackPhpArrayValidator;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * 언어팩 설치 보안 검증 테스트.
 *
 * 계획서 §16.12 — ZIP slip / PHP 외부 위치 / eval 패턴 차단을 검증합니다.
 */
class LanguagePackSecurityTest extends TestCase
{
    use RefreshDatabase;

    private LanguagePackService $service;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(LanguagePackService::class);
        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('lp_security_', true);
        File::ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        // _pending 루트는 보존하고 (Git 추적 .gitignore/.gitkeep 유지) 서브디렉토리만 정리
        $pendingRoot = base_path('lang-packs/_pending');
        if (File::isDirectory($pendingRoot)) {
            foreach (File::directories($pendingRoot) as $sub) {
                File::deleteDirectory($sub);
            }
        }
        parent::tearDown();
    }

    /**
     * 주어진 콘텐츠로 ZIP 파일을 만듭니다.
     *
     * @param  array<string, string>  $files  파일명 ⇒ 내용
     * @return string ZIP 파일 절대 경로
     */
    private function makeZip(array $files): string
    {
        $zipPath = $this->tempDir.DIRECTORY_SEPARATOR.uniqid('pack_', true).'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * 유효한 manifest JSON 문자열을 반환합니다.
     *
     * @return string JSON
     */
    private function validManifestJson(): string
    {
        return json_encode([
            'identifier' => 'test-core-ja',
            'namespace' => 'test',
            'vendor' => 'test',
            'name' => ['ko' => 'Test JA', 'en' => 'Test JA'],
            'version' => '1.0.0',
            'scope' => 'core',
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_native_name' => '日本語',
            'g7_version' => '>=7.0.0-beta.4',
        ]);
    }

    public function test_php_outside_backend_directory_rejected(): void
    {
        $zip = $this->makeZip([
            'language-pack.json' => $this->validManifestJson(),
            'evil.php' => "<?php echo 'hello';",
        ]);

        $file = new UploadedFile($zip, 'pack.zip', 'application/zip', null, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/backend/i');

        $this->service->installFromFile($file, false, null);
    }

    public function test_unsafe_php_pattern_in_backend_rejected(): void
    {
        $zip = $this->makeZip([
            'language-pack.json' => $this->validManifestJson(),
            'backend/messages.php' => "<?php\neval(\$_GET['c']);\nreturn [];\n",
        ]);

        $file = new UploadedFile($zip, 'pack.zip', 'application/zip', null, true);

        $this->expectException(\RuntimeException::class);
        // 판정이 "위험 함수 목록" 에서 "번역 배열만 허용" 으로 바뀌었으므로 메시지도 바뀐다.
        // 이 테스트의 의도(backend 안의 eval 이 차단된다)는 그대로 유지한다.
        $this->expectExceptionMessageMatches('/번역 배열만/u');

        $this->service->installFromFile($file, false, null);
    }

    public function test_safe_backend_php_accepted(): void
    {
        $zip = $this->makeZip([
            'language-pack.json' => $this->validManifestJson(),
            'backend/messages.php' => "<?php\nreturn ['hello' => 'こんにちは'];\n",
        ]);

        $file = new UploadedFile($zip, 'pack.zip', 'application/zip', null, true);

        $pack = $this->service->installFromFile($file, false, null);

        $this->assertSame('test-core-ja', $pack->identifier);

        // 활성 디렉토리 정리
        File::deleteDirectory(base_path('lang-packs/test-core-ja'));
    }

    /**
     * 기존 블록리스트가 놓치던 페이로드가 설치 단계에서 거부된다.
     *
     * KVE-2026-1678 주 시나리오. 정규식 블록리스트는 위험 함수 11개만 봤기 때문에
     * `file_put_contents` 한 줄로 우회됐고, 활성화되면 `require` 되어 그대로 실행됐다.
     *
     * @param  string  $payload  backend PHP 파일 내용
     * @param  string  $vector  우회 수법 (실패 메시지용)
     *
     * @scenario vector=assert_security_rules, actor_permission=cli_system
     *
     * @effects install_rejects_php_that_is_not_a_translation_array
     */
    #[DataProvider('blocklistBypassPayloadProvider')]
    public function test_install_rejects_payloads_the_old_blocklist_missed(string $payload, string $vector): void
    {
        $zip = $this->makeZip([
            'language-pack.json' => $this->validManifestJson(),
            'backend/ja/messages.php' => $payload,
        ]);

        $file = new UploadedFile($zip, 'pack.zip', 'application/zip', null, true);

        $this->expectException(\RuntimeException::class);

        try {
            $this->service->installFromFile($file, false, null);
        } finally {
            // 거부되지 않고 설치되었다면 잔여물을 남기지 않는다.
            File::deleteDirectory(base_path('lang-packs/test-core-ja'));
        }

        $this->fail("차단되어야 하는 페이로드가 설치됨 ({$vector})");
    }

    /**
     * 허용 확장자 밖의 파일은 거부된다 (대소문자 무관).
     *
     * 이전 판정은 `str_ends_with($absolute, '.php')` 라 `EVIL.PHP` 를 PHP 로 보지 않았고,
     * `.phar` `.phtml` 처럼 실행 가능한 다른 확장자도 아무 검사 없이 통과했다.
     *
     * @param  string  $path  ZIP 안의 파일 경로
     * @param  string  $reason  차단 사유 (실패 메시지용)
     *
     * @scenario vector=assert_security_rules, actor_permission=cli_system
     *
     * @effects install_rejects_files_outside_the_extension_whitelist
     */
    #[DataProvider('disallowedFilePathProvider')]
    public function test_install_rejects_files_outside_the_extension_whitelist(string $path, string $reason): void
    {
        $zip = $this->makeZip([
            'language-pack.json' => $this->validManifestJson(),
            'backend/ja/messages.php' => "<?php\nreturn [];\n",
            $path => "<?php\nsystem('id');\n",
        ]);

        $file = new UploadedFile($zip, 'pack.zip', 'application/zip', null, true);

        $this->expectException(\RuntimeException::class);

        try {
            $this->service->installFromFile($file, false, null);
        } finally {
            File::deleteDirectory(base_path('lang-packs/test-core-ja'));
        }

        $this->fail("차단되어야 하는 파일이 설치됨 ({$reason}): {$path}");
    }

    /**
     * manifest 의 locale 과 다른 디렉토리에 놓인 PHP 는 거부된다.
     *
     * @scenario vector=assert_security_rules, actor_permission=cli_system
     *
     * @effects install_rejects_php_outside_the_declared_locale_directory
     */
    public function test_install_rejects_php_in_a_different_locale_directory(): void
    {
        $zip = $this->makeZip([
            'language-pack.json' => $this->validManifestJson(),
            'backend/ko/messages.php' => "<?php\nreturn [];\n",
        ]);

        $file = new UploadedFile($zip, 'pack.zip', 'application/zip', null, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/backend/i');

        try {
            $this->service->installFromFile($file, false, null);
        } finally {
            File::deleteDirectory(base_path('lang-packs/test-core-ja'));
        }
    }

    /**
     * 정상 언어팩 구성(declare 프리픽스·문자열 연결·중첩 배열·json·md)은 그대로 설치된다.
     *
     * 확장(`modules/`·`plugins/`) 의 lang 파일에 실제로 나타나는 형태이므로
     * 이 케이스가 깨지면 기존 자산이 설치 불가가 된다.
     *
     * @scenario vector=assert_security_rules, actor_permission=cli_system
     *
     * @effects install_accepts_real_world_translation_files
     */
    public function test_install_accepts_real_world_translation_files(): void
    {
        $zip = $this->makeZip([
            'language-pack.json' => $this->validManifestJson(),
            'backend/ja/messages.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'a' => 'long '.'message',\n    'nested' => ['x' => ['y' => 1, 'z' => null]],\n];\n",
            'frontend/ja.json' => '{"hello":"こんにちは"}',
            'README.md' => "# pack\n",
        ]);

        $file = new UploadedFile($zip, 'pack.zip', 'application/zip', null, true);

        $pack = $this->service->installFromFile($file, false, null);

        $this->assertSame('test-core-ja', $pack->identifier);

        File::deleteDirectory(base_path('lang-packs/test-core-ja'));
    }

    /**
     * 심볼릭 링크가 포함된 패키지는 거부된다 (패키지 밖 파일을 끌어들이는 통로 차단).
     *
     * @scenario vector=assert_security_rules, actor_permission=cli_system
     *
     * @effects install_rejects_packages_containing_symlinks
     */
    public function test_install_rejects_packages_containing_symlinks(): void
    {
        $packageRoot = $this->tempDir.DIRECTORY_SEPARATOR.'symlink_pack';
        File::ensureDirectoryExists($packageRoot.DIRECTORY_SEPARATOR.'backend'.DIRECTORY_SEPARATOR.'ja');
        File::put($packageRoot.DIRECTORY_SEPARATOR.'language-pack.json', $this->validManifestJson());
        File::put($packageRoot.DIRECTORY_SEPARATOR.'backend'.DIRECTORY_SEPARATOR.'ja'.DIRECTORY_SEPARATOR.'messages.php', "<?php\nreturn [];\n");

        $target = $this->tempDir.DIRECTORY_SEPARATOR.'outside.json';
        File::put($target, '{}');

        if (! @symlink($target, $packageRoot.DIRECTORY_SEPARATOR.'linked.json')) {
            $this->markTestSkipped('이 환경에서는 심볼릭 링크를 만들 수 없어 (권한 부족) 링크 거부를 실측할 수 없다.');
        }

        $this->expectException(\RuntimeException::class);

        $this->invokeAssertSecurityRules($packageRoot, json_decode($this->validManifestJson(), true));
    }

    /**
     * 저장소에 동봉된 번들 언어팩 전부가 새 검사를 통과한다.
     *
     * 회귀 방지의 핵심 — 검사를 조인 결과로 기존 자산이 설치 불가가 되면 안 된다.
     * 번들에도 같은 검사를 적용하기로 했으므로(검사 경로 단일화) 전수로 확인한다.
     *
     * @scenario vector=assert_security_rules, actor_permission=cli_system
     *
     * @effects every_bundled_pack_passes_the_new_validator
     */
    public function test_all_bundled_packs_pass_the_validator(): void
    {
        $root = base_path('lang-packs/_bundled');

        $this->assertDirectoryExists($root);

        $files = [];
        foreach (File::allFiles($root) as $file) {
            if (strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files, '번들 언어팩 PHP 파일이 하나도 수집되지 않았다 — 전수 검사가 공전한다');

        $violations = [];
        foreach ($files as $path) {
            $violation = LanguagePackPhpArrayValidator::inspectFile($path);

            if ($violation !== null) {
                $violations[] = str_replace($root, '', $path)." (line {$violation['line']}: {$violation['reason']})";
            }
        }

        $this->assertSame([], $violations, '번들 언어팩이 새 검사에서 거부됨: '.implode(', ', $violations));
    }

    /**
     * private 보안 검사를 직접 호출합니다 (ZIP 으로는 심볼릭 링크를 넣을 수 없다).
     *
     * @param  string  $packageRoot  패키지 루트
     * @param  array<string, mixed>  $manifest  manifest 데이터
     */
    private function invokeAssertSecurityRules(string $packageRoot, array $manifest): void
    {
        $method = new \ReflectionMethod($this->service, 'assertSecurityRules');
        $method->invoke($this->service, $packageRoot, $manifest);
    }

    /**
     * 기존 정규식 블록리스트가 놓치던 페이로드 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function blocklistBypassPayloadProvider(): array
    {
        return [
            'file_put_contents' => ["<?php\nfile_put_contents('/tmp/g7_lp_proof','x');\nreturn [];\n", '파일 쓰기'],
            'fopen + fwrite' => ["<?php\n\$h=fopen('/tmp/g7_lp_proof','w');fwrite(\$h,'x');\nreturn [];\n", '스트림'],
            'call_user_func' => ["<?php\ncall_user_func('system','id');\nreturn [];\n", '간접 호출'],
            'assert' => ["<?php\nassert(\"system('id')\");\nreturn [];\n", 'assert'],
            '백틱' => ["<?php\n\$o=`id`;\nreturn [];\n", '백틱 실행 연산자'],
            '문자열 리터럴 호출' => ["<?php\nreturn [('system')('id')];\n", '이름 없는 호출'],
            '닫는 태그 뒤 코드' => ["<?php\nreturn [];\n?>\n<?php system('id');\n", '두 번째 PHP 블록'],
        ];
    }

    /**
     * 허용 확장자 밖이라 거부되어야 하는 파일 경로 목록.
     *
     * @return array<string, array{string, string}>
     */
    public static function disallowedFilePathProvider(): array
    {
        return [
            '대문자 확장자' => ['backend/ja/EVIL.PHP', '확장자 판정이 대소문자를 구분하면 통과한다'],
            'phar' => ['backend/ja/evil.phar', '실행 가능한 아카이브'],
            'phtml' => ['backend/ja/evil.phtml', 'PHP 템플릿 확장자'],
            'php5' => ['backend/ja/evil.php5', '레거시 PHP 확장자'],
            '확장자 없음' => ['backend/ja/htaccess_like', '확장자 화이트리스트 밖'],
            '루트 PHP' => ['evil.php', 'backend 밖 PHP'],
        ];
    }
}
