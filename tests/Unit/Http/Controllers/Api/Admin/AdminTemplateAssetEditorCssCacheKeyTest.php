<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\AdminTemplateAssetController;
use PHPUnit\Framework\TestCase;

/**
 * 편집기 CSS 캐시 키는 mtime 을 읽지 못하면 만들어지지 않는다 (13차 G3 라운드).
 *
 * 종전에는 `(int) @filemtime()` 이라 조회 실패가 `m0` 으로 접혀, 실패한 요청들이 **하나의
 * 키**를 공유했다 — 어느 시점의 변환 결과든 그 키에 한 번 들어가면 빌드가 바뀌어도 1시간
 * 동안 그대로 나간다. mtime 을 키에 넣은 이유(빌드 변경 즉시 무효화)가 그 자리에서
 * 사라지는데 오류도 로그도 없다.
 *
 * 순수 단위 테스트다 — 키 조립은 앱·DB 를 필요로 하지 않는다.
 */
class AdminTemplateAssetEditorCssCacheKeyTest extends TestCase
{
    /**
     * 보호 메서드를 호출한다.
     *
     * @param  string  $cssPath  CSS 파일 경로
     * @return string|null 캐시 키
     */
    private function cacheKeyFor(string $cssPath): ?string
    {
        $controller = (new \ReflectionClass(AdminTemplateAssetController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'editorCssCacheKey');

        return $method->invoke($controller, 'sirsoft-admin_basic', 7, $cssPath);
    }

    /**
     * @scenario mtime_read=failed
     *
     * @effects editor_css_cache_key_is_skipped_when_mtime_is_unreadable
     */
    public function test_unreadable_mtime_yields_no_cache_key(): void
    {
        $missing = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-editor-css-'.uniqid().DIRECTORY_SEPARATOR.'components.css';

        $this->assertFileDoesNotExist($missing);
        $this->assertNull(
            $this->cacheKeyFor($missing),
            'mtime 조회 실패가 하나의 고정 키(m0)로 접혀 실패한 요청들이 같은 캐시 항목을 공유한다'
        );
    }

    /**
     * @scenario mtime_read=ok
     *
     * @effects editor_css_cache_key_embeds_mtime_when_readable
     */
    public function test_readable_mtime_is_embedded_in_the_key(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'g7css');
        file_put_contents($path, 'body{}');

        try {
            $key = $this->cacheKeyFor($path);

            $this->assertSame(
                'template.editor_css.sirsoft-admin_basic.v7.m'.filemtime($path),
                $key,
            );
            $this->assertStringNotContainsString('.m0', (string) $key);
        } finally {
            @unlink($path);
        }
    }
}
