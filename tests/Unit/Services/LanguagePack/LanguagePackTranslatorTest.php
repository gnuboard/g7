<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Services\LanguagePack\LanguagePackTranslator;
use Illuminate\Translation\FileLoader;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

/**
 * LanguagePackTranslator 단위 테스트.
 *
 * Translator decorator 의 코어 폴백 경로 동작을 검증합니다.
 */
class LanguagePackTranslatorTest extends TestCase
{
    private string $tempDir;

    private LanguagePackTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('lp_translator_', true);
        mkdir($this->tempDir, 0777, true);

        $loader = new FileLoader(new Filesystem(), $this->tempDir);
        $this->translator = new LanguagePackTranslator($loader, 'ko');
        $this->translator->setFallback('ko');
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->tempDir);
        parent::tearDown();
    }

    private function deleteRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->deleteRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_add_core_fallback_path_records_path(): void
    {
        $this->translator->addCoreFallbackPath('ja', '/some/path');

        $this->assertSame(['ja' => ['/some/path']], $this->translator->getCoreFallbackPaths());
    }

    public function test_load_merges_fallback_when_base_missing_key(): void
    {
        // 기본 lang/ko/messages.php 생성 — only 'foo' 정의
        mkdir($this->tempDir.'/ko', 0777, true);
        file_put_contents($this->tempDir.'/ko/messages.php', "<?php\nreturn ['foo' => 'baseFoo'];\n");

        // 폴백 경로 — 'foo' 와 'bar' 정의
        $fallbackDir = $this->tempDir.'/lang-pack-ja';
        mkdir($fallbackDir, 0777, true);
        file_put_contents($fallbackDir.'/messages.php', "<?php\nreturn ['foo' => 'fallbackFoo', 'bar' => 'fallbackBar'];\n");

        $this->translator->addCoreFallbackPath('ko', $fallbackDir);

        // base 우선 — foo 는 base 값 유지
        $this->assertSame('baseFoo', $this->translator->get('messages.foo', [], 'ko'));
        // base 부재 키 — fallback 으로 보완
        $this->assertSame('fallbackBar', $this->translator->get('messages.bar', [], 'ko'));
    }

    public function test_namespaced_load_does_not_apply_fallback(): void
    {
        // 폴백 경로 등록 (코어 폴백)
        $fallbackDir = $this->tempDir.'/lang-pack-ja';
        mkdir($fallbackDir, 0777, true);
        file_put_contents($fallbackDir.'/messages.php', "<?php\nreturn ['foo' => 'fallbackFoo'];\n");
        $this->translator->addCoreFallbackPath('ko', $fallbackDir);

        // 모듈 namespace 키 조회 — 등록된 path 가 없으므로 키 자체 그대로 반환 (fallback 미적용)
        $result = $this->translator->get('somemodule::messages.foo', [], 'ko');
        $this->assertSame('somemodule::messages.foo', $result);
    }
}
