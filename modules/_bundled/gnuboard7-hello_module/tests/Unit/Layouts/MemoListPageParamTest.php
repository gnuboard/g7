<?php

namespace Modules\Gnuboard7\HelloModule\Tests\Unit\Layouts;

use Modules\Gnuboard7\HelloModule\Tests\ModuleTestCase;

/**
 * 메모 목록 화면의 페이지 번호 파라미터 테스트.
 *
 * 7.1.0 전수 실측 M3: 공개 메모 목록(`/memos`)을 쿼리 없이 열면 데이터 소스가
 * `page={{query.page}}` 를 그대로 보내 `GET /api/modules/gnuboard7-hello_module/memos?page=undefined`
 * 가 되고, 목록 요청 검증(`page` 정수)이 422 를 돌려 메모가 한 건도 보이지 않았다(DB 메모 5건).
 * 관리자 목록도 같은 파라미터를 쓴다. 쿼리가 없을 때 1쪽을 보내도록 기본값을 둔다.
 *
 * @scenario-manifest tests/scenarios/memo-list-page-param.yaml
 */
class MemoListPageParamTest extends ModuleTestCase
{
    /**
     * 레이아웃 원본(.g7.html)과 짝 JSON 의 memos 데이터 소스 page 파라미터를 돌려줍니다.
     *
     * @param  string  $relative  resources/layouts 기준 경로(확장자 제외)
     * @return array{json: mixed, markup: string} JSON 값과 마크업 원문
     */
    private function pageParamOf(string $relative): array
    {
        $base = dirname(__DIR__, 3).'/resources/layouts/'.$relative;
        $layout = json_decode((string) file_get_contents($base.'.json'), true);
        $source = collect($layout['data_sources'] ?? [])->firstWhere('id', 'memos');

        return [
            'json' => $source['params']['page'] ?? null,
            'markup' => (string) file_get_contents($base.'.g7.html'),
        ];
    }

    /**
     * 쿼리가 없을 때 1쪽을 보낸다.
     *
     * @scenario screen=user_list
     *
     * @effects page_param_defaults_to_first_page
     *
     * @return void 반환값 없음
     */
    public function test_user_memo_list_sends_first_page_without_query(): void
    {
        $param = $this->pageParamOf('user/user_memo_list');

        $this->assertSame('{{query.page ?? 1}}', $param['json']);
        $this->assertStringContainsString('page="{{query.page ?? 1}}"', $param['markup']);
    }

    /**
     * 관리자 목록도 쿼리가 없을 때 1쪽을 보낸다.
     *
     * @scenario screen=admin_list
     *
     * @effects page_param_defaults_to_first_page
     *
     * @return void 반환값 없음
     */
    public function test_admin_memo_list_sends_first_page_without_query(): void
    {
        $param = $this->pageParamOf('admin/admin_memo_list');

        $this->assertSame('{{query.page ?? 1}}', $param['json']);
        $this->assertStringContainsString('page="{{query.page ?? 1}}"', $param['markup']);
    }
}
