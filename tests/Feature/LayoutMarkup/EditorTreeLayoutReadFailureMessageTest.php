<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\Layout\FragmentReadCache;
use App\Services\TemplateService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 편집기 트리가 본 레이아웃을 읽지 못하면 「레이아웃 소스 처리 실패」 로 남긴다 (#725 W3).
 *
 * 편집기 트리는 본 레이아웃·모달 부분 화면을 조각 읽기 함수(규약 SSoT)로 읽는다. 그 함수의 실패 문구가
 * 「레이아웃 확장 파일 처리 실패」 로 고정돼 있어, 본 레이아웃 파일이 깨진 것을 확장 조각이 깨진 것으로 읽게 했다
 * (10-07 6줄). 읽기 함수는 그대로 공유하고 문구의 대상 이름만 호출처가 고른다. 트리 표시(그 화면만 빠짐)는 그대로다.
 */
class EditorTreeLayoutReadFailureMessageTest extends TestCase
{
    private string $tmp;

    /**
     * 깨진 본 레이아웃 하나를 담은 임시 레이아웃 디렉토리를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = base_path('storage/framework/testing/editor_tree_read_'.substr(md5(uniqid('', true)), 0, 8));
        File::ensureDirectoryExists($this->tmp);
        File::put($this->tmp.'/broken_page.g7.html', "<layout name=\"broken_page\" version=\"1.0.0\">\n  <div id=\"root\">\n");
    }

    /**
     * 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        // 실패 기억은 프로세스 단위다 — 같은 프로세스의 다음 테스트로 새지 않게 비운다.
        FragmentReadCache::flush();

        parent::tearDown();
    }

    /**
     * 깨진 본 레이아웃은 레이아웃 소스 실패로 기록되고 확장 파일 문구는 쓰지 않는다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects editor_tree_layout_failure_uses_layout_message
     *
     * @return void 반환값 없음
     */
    public function test_broken_base_layout_is_logged_as_layout_source_failure(): void
    {
        Log::spy();

        $method = new \ReflectionMethod(TemplateService::class, 'collectBaseAndModalsFromLayoutDir');
        $collected = $method->invoke(app(TemplateService::class), $this->tmp, '');

        $this->assertSame([], $collected['base_layouts'], '읽지 못한 화면은 트리에서 빠진다(종전과 같음)');

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_starts_with($message, '레이아웃 소스 처리 실패')
                && str_contains($message, 'broken_page.g7.html'))
            ->once();
        Log::shouldNotHaveReceived('error', [\Mockery::on(fn ($message): bool => is_string($message) && str_contains($message, '레이아웃 확장 파일')), \Mockery::any()]);
    }
}
