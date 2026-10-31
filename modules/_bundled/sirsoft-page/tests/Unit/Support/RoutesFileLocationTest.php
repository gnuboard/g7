<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Support;

use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 페이지 모듈 라우트 파일 위치 테스트 (7.1.1 출시 전 점검)
 *
 * 코어는 모듈 라우트를 `resources/routes/{admin|user}.json` 에서 먼저 찾고, 없으면 레거시 위치
 * `resources/routes.json` 을 관리자 라우트로 읽으면서 요청마다 「모듈 routes.json이 레거시 위치에 있습니다」
 * WARNING 을 남긴다(TemplateService::loadActiveModulesRoutesData). 페이지 모듈만 레거시 위치에 두어
 * 운영 로그에 경고가 쌓였다(업데이트 리허설 환경 약 1시간 55회). 게시판·이커머스처럼 표준 위치에 둔다.
 *
 * 업데이트로 옛 파일이 설치본에 남아도 표준 위치가 먼저 읽혀 경고는 나지 않는다.
 */
class RoutesFileLocationTest extends ModuleTestCase
{
    /**
     * 모듈 루트(_bundled 작업 원본) 경로.
     *
     * @return string 모듈 루트 절대 경로
     */
    private function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 관리자 라우트는 표준 위치에 있고 레거시 위치 파일은 없다.
     *
     * @scenario case=routes_in_standard_location
     *
     * @effects no_legacy_routes_warning
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_routes_live_in_standard_location(): void
    {
        $this->assertFileDoesNotExist($this->moduleRoot().'/resources/routes.json', '레거시 위치 routes.json 은 부팅마다 WARNING 을 남긴다');
        $this->assertFileExists($this->moduleRoot().'/resources/routes/admin.json');
    }

    /**
     * 옮긴 파일이 관리자 화면 4개 라우트를 그대로 담는다(경로·레이아웃 무변경).
     *
     * @scenario case=routes_content_kept
     *
     * @effects admin_routes_unchanged
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function moved_file_keeps_admin_routes(): void
    {
        $data = json_decode((string) file_get_contents($this->moduleRoot().'/resources/routes/admin.json'), true);

        $this->assertIsArray($data['routes'] ?? null);
        $this->assertSame(
            [
                '*/admin/pages' => 'admin_page_list',
                '*/admin/pages/create' => 'admin_page_form',
                '*/admin/pages/:id/edit' => 'admin_page_form',
                '*/admin/pages/:id' => 'admin_page_detail',
            ],
            array_column($data['routes'], 'layout', 'path')
        );
    }
}
