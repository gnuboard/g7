<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Board\Http\Requests\UploadAttachmentRequest;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 첨부 업로드 용량 상한의 기본값 폴백 회귀 테스트 (#703)
 *
 * 회귀 배경: 게시판의 최대 파일 크기가 비어 있으면(null — 기본값 따름) 게시글 저장 검증은 게시판
 * 환경설정 기본값을 쓰는데, 첨부 업로드 검증만 10MB 리터럴로 떨어졌다. 기본값을 10MB 보다 크게 둔
 * 사이트에서는 게시글 저장은 허용하는 크기의 파일이 업로드 단계에서 먼저 거절됐다.
 */
#[Group('board')]
#[Group('attachment')]
class AttachmentMaxFileSizeFallbackTest extends BoardTestCase
{
    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'attachment-size-fallback';
    }

    /**
     * 최대 파일 크기가 비어 있는 게시판 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '용량 폴백 테스트 게시판', 'en' => 'Size Fallback Test Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_size' => null,
        ];
    }

    /**
     * UploadAttachmentRequest 를 대상 게시판 슬러그로 생성합니다.
     *
     * @return UploadAttachmentRequest 라우트 슬러그가 주입된 요청 객체
     */
    private function makeUploadRequest(): UploadAttachmentRequest
    {
        $slug = $this->board->slug;
        $request = new UploadAttachmentRequest;
        $request->setRouteResolver(function () use ($slug) {
            $route = \Mockery::mock(Route::class);
            $route->shouldReceive('parameter')->with('slug', null)->andReturn($slug);

            return $route;
        });

        return $request;
    }

    /**
     * 게시판 값이 비어 있으면 업로드 용량 규칙이 게시판 기본값(MB)을 따른다.
     *
     * @scenario board_max_file_size=null, defaults_max_file_size=raised
     *
     * @effects upload_size_rule_falls_back_to_board_defaults
     *
     * @return void 없음
     */
    public function test_null_board_size_uses_board_defaults(): void
    {
        $this->updateBoardSettings(['max_file_size' => null]);
        Config::set('g7_settings.modules.sirsoft-board.basic_defaults.max_file_size', 30);

        $request = $this->makeUploadRequest();

        $this->assertContains('max:'.(30 * 1024), $request->rules()['file']);
        $this->assertStringContainsString('30', $request->messages()['file.max']);
    }

    /**
     * 게시판 값이 있으면 기본값보다 게시판 값이 우선한다.
     *
     * @scenario board_max_file_size=set, defaults_max_file_size=raised
     *
     * @effects board_value_precedes_defaults
     *
     * @return void 없음
     */
    public function test_board_size_precedes_defaults(): void
    {
        $this->updateBoardSettings(['max_file_size' => 5]);
        Config::set('g7_settings.modules.sirsoft-board.basic_defaults.max_file_size', 30);

        $this->assertContains('max:'.(5 * 1024), $this->makeUploadRequest()->rules()['file']);
    }
}
