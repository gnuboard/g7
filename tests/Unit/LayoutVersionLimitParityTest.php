<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\LayoutVersionListRequest;
use Tests\TestCase;

/**
 * 버전 목록 조회 상한의 서버 ↔ 편집기 일치 검사
 *
 * 편집기의 '더 보기' 는 조회 상한을 한 묶음씩 넓히는 방식이라, 화면이 서버 상한을 모르면
 * 넓히기를 멈추지 못하고 상한을 넘는 순간 목록 전체가 422 가 된다. 이미 보고 있던 이력까지
 * 사라지므로 두 상수는 반드시 같아야 한다.
 *
 * 상수를 한쪽만 바꾸면 이 테스트가 깨진다.
 */
class LayoutVersionLimitParityTest extends TestCase
{
    /** 편집기 훅 경로 (상수 SSoT 의 소비 측) */
    private const HOOK_PATH = 'resources/js/core/template-engine/layout-editor/hooks/useLayoutVersions.ts';

    /**
     * 편집기의 VERSION_MAX_LIMIT 이 서버 MAX_LIMIT 과 같다.
     *
     * @return void
     */
    public function test_editor_max_limit_matches_server_max_limit(): void
    {
        $this->assertSame(
            LayoutVersionListRequest::MAX_LIMIT,
            $this->readNumericConstant('VERSION_MAX_LIMIT'),
            '편집기 VERSION_MAX_LIMIT 이 서버 LayoutVersionListRequest::MAX_LIMIT 과 다르다. '.
            '화면이 서버 상한을 넘는 limit 을 보내면 버전 목록이 422 로 비어 버린다.'
        );
    }

    /**
     * 편집기의 한 묶음 크기가 서버 기본 조회 건수와 같다.
     *
     * 첫 화면이 서버 기본값과 다른 수를 요청하면 '더 보기' 노출 판정("묶음이 가득 찼는가")이
     * 어긋나, 남은 이력이 있는데도 버튼이 사라지거나 그 반대가 된다.
     *
     * @return void
     */
    public function test_editor_page_size_matches_server_default_limit(): void
    {
        $this->assertSame(
            LayoutVersionListRequest::DEFAULT_LIMIT,
            $this->readNumericConstant('VERSION_PAGE_SIZE'),
            '편집기 VERSION_PAGE_SIZE 가 서버 DEFAULT_LIMIT 과 다르다.'
        );
    }

    /**
     * 상한이 한 묶음보다 크다 — 그렇지 않으면 '더 보기' 가 처음부터 무의미하다.
     *
     * @return void
     */
    public function test_max_limit_leaves_room_for_at_least_one_more_batch(): void
    {
        $this->assertGreaterThan(
            LayoutVersionListRequest::DEFAULT_LIMIT,
            LayoutVersionListRequest::MAX_LIMIT,
            '상한이 기본 조회 건수 이하면 더 보기로 넓힐 여지가 없다.'
        );
    }

    /**
     * 편집기 훅에서 숫자 상수 값을 읽습니다.
     *
     * @param  string  $name  상수 이름
     * @return int 상수 값
     */
    private function readNumericConstant(string $name): int
    {
        $path = base_path(self::HOOK_PATH);

        $this->assertFileExists($path, '편집기 버전 훅 경로가 바뀌었다면 본 테스트의 경로도 함께 고쳐야 한다.');

        $source = file_get_contents($path);

        $matched = preg_match(
            '/export\s+const\s+'.preg_quote($name, '/').'\s*=\s*(\d+)\s*;/',
            $source,
            $matches
        );

        $this->assertSame(
            1,
            $matched,
            "편집기 훅에서 {$name} 선언을 찾지 못했다 (이름 변경 또는 선언 형태 변경)."
        );

        return (int) $matches[1];
    }
}
