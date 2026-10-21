<?php

namespace Tests\Unit\Services;

use App\Services\LayoutService;
use Tests\TestCase;

/**
 * 공개 서빙의 개발자 주석 제거가 요청 본문 데이터의 `comment` 키를 지우지 않는지 확인합니다.
 *
 * `LayoutService::stripDeveloperComments` 는 레이아웃의 `comment`·`_comment` 설명을 서빙 응답에서 뺀다.
 * 종전에는 깊이·위치를 가리지 않고 지워, 액션 `params.body.comment` 처럼 서버로 보낼 **데이터** 키까지 사라졌다
 * (비즈뿌리오 「검수 의견」이 빈 본문 `[]` 로 전송). 요청 본문 자리(`body`·`query`·`headers` 아래,
 * 데이터소스 `params` 아래)는 데이터라 남기고, 정의 노드·액션 params·props·initLocal 의 설명은 계속 지운다.
 */
class LayoutServiceDeveloperCommentStripTest extends TestCase
{
    /**
     * 레이아웃·노드의 설명은 지운다.
     *
     * @scenario position=node
     *
     * @effects developer_comment_stripped
     *
     * @return void 반환값 없음
     */
    public function test_strips_layout_and_node_description(): void
    {
        $stripped = $this->stripFixture();

        $this->assertArrayNotHasKey('comment', $stripped);
        $this->assertArrayNotHasKey('comment', $stripped['components'][0]);
        $this->assertArrayNotHasKey('_comment', $stripped['data_sources'][0]);
    }

    /**
     * 액션 정의의 설명은 지운다.
     *
     * @scenario position=action
     *
     * @effects developer_comment_stripped
     *
     * @return void 반환값 없음
     */
    public function test_strips_action_description(): void
    {
        $this->assertArrayNotHasKey('comment', $this->stripFixture()['components'][0]['actions'][0]);
    }

    /**
     * 액션 params 바로 아래의 comment 는 설명 자리라 지운다 — 남기면 처리기 인자·상태 값이 된다.
     *
     * @scenario position=action_params
     *
     * @effects developer_comment_stripped
     *
     * @return void 반환값 없음
     */
    public function test_strips_action_params_description(): void
    {
        $params = $this->stripFixture()['components'][0]['actions'][0]['params'];

        $this->assertArrayNotHasKey('comment', $params);
        $this->assertSame('POST', $params['method']);
    }

    /**
     * props 아래의 comment 는 설명 자리라 지운다 — 남기면 컴포넌트 prop 이 된다.
     *
     * @scenario position=props
     *
     * @effects developer_comment_stripped
     *
     * @return void 반환값 없음
     */
    public function test_strips_props_description(): void
    {
        $props = $this->stripFixture()['components'][0]['props'];

        $this->assertArrayNotHasKey('comment', $props);
        $this->assertSame('저장', $props['label']);
    }

    /**
     * initLocal 아래의 comment 는 설명 자리라 지운다 — 남기면 `_local.comment` 가 된다.
     *
     * @scenario position=init_local
     *
     * @effects developer_comment_stripped
     *
     * @return void 반환값 없음
     */
    public function test_strips_init_local_description(): void
    {
        $initLocal = $this->stripFixture()['initLocal'];

        $this->assertArrayNotHasKey('comment', $initLocal);
        $this->assertArrayHasKey('memo', $initLocal);
    }

    /**
     * 액션 요청 본문의 comment 는 데이터라 남긴다.
     *
     * @scenario position=request_body
     *
     * @effects request_payload_comment_preserved
     *
     * @return void 반환값 없음
     */
    public function test_keeps_comment_in_request_body(): void
    {
        $params = $this->stripFixture()['components'][0]['actions'][0]['params'];

        $this->assertSame("{{_global.modal?.request_comment ?? ''}}", $params['body']['comment'] ?? null);
    }

    /**
     * 액션 요청 쿼리의 comment 는 데이터라 남긴다.
     *
     * @scenario position=request_query
     *
     * @effects request_payload_comment_preserved
     *
     * @return void 반환값 없음
     */
    public function test_keeps_comment_in_request_query(): void
    {
        $params = $this->stripFixture()['components'][0]['actions'][0]['params'];

        $this->assertSame('yes', $params['query']['comment'] ?? null);
    }

    /**
     * 요청 헤더의 comment 는 데이터라 남긴다.
     *
     * @scenario position=request_headers
     *
     * @effects request_payload_comment_preserved
     *
     * @return void 반환값 없음
     */
    public function test_keeps_comment_in_request_headers(): void
    {
        $params = $this->stripFixture()['components'][0]['actions'][0]['params'];

        $this->assertSame('h', $params['headers']['comment'] ?? null);
    }

    /**
     * 데이터소스의 요청 params·body 의 comment 는 데이터라 남긴다.
     *
     * @scenario position=data_source_params
     *
     * @effects request_payload_comment_preserved
     *
     * @return void 반환값 없음
     */
    public function test_keeps_comment_in_data_source_request(): void
    {
        $source = $this->stripFixture()['data_sources'][0];

        $this->assertSame('{{query.comment}}', $source['params']['comment'] ?? null);
        $this->assertSame('데이터소스 본문 값', $source['body']['comment'] ?? null);
    }

    /**
     * 번들 비즈뿌리오의 「검수 의견」 본문이 서빙 정리 뒤에도 남는다(실제 파일 4곳).
     *
     * @scenario position=request_body
     *
     * @effects bundled_review_comment_survives_serving
     *
     * @return void 반환값 없음
     */
    public function test_bundled_bizppurio_review_comment_survives_stripping(): void
    {
        $files = [
            'plugins/_bundled/sirsoft-message_bizppurio/resources/layouts/admin/plugin_settings.json',
            'plugins/_bundled/sirsoft-message_bizppurio/resources/extensions/notification_template_form_footer_actions.json',
        ];

        $service = app(LayoutService::class);
        $found = 0;

        foreach ($files as $file) {
            $layout = json_decode((string) file_get_contents(base_path($file)), true);
            $this->assertIsArray($layout, "{$file} 를 읽지 못했다");

            $paths = [];
            $this->collectCommentPaths($service->stripDeveloperComments($layout), [], $paths);

            foreach ($paths as $path) {
                $this->assertStringContainsString('.body.comment', $path, "{$file}: 요청 본문 밖 설명 comment 가 남았다 — {$path}");
            }
            $found += count($paths);
        }

        $this->assertSame(4, $found, '검수 신청 본문 comment 4곳이 서빙 정리 뒤에도 남아야 한다');
    }

    /**
     * 모든 자리에 comment 를 둔 표본 레이아웃을 정리해 돌려준다.
     *
     * @return array 정리된 레이아웃
     */
    private function stripFixture(): array
    {
        $layout = [
            'comment' => '레이아웃 설명',
            'initLocal' => ['comment' => '초기 상태 설명', 'memo' => ''],
            'data_sources' => [[
                'id' => 'reviews',
                '_comment' => '데이터소스 설명',
                'endpoint' => '/api/reviews',
                'params' => ['comment' => '{{query.comment}}'],
                'body' => ['comment' => '데이터소스 본문 값'],
            ]],
            'components' => [[
                'name' => 'Button',
                'comment' => '노드 설명',
                'props' => ['comment' => 'props 설명', 'label' => '저장'],
                'actions' => [[
                    'type' => 'click',
                    'handler' => 'apiCall',
                    'comment' => '액션 설명',
                    'target' => '/api/request',
                    'params' => [
                        'comment' => '액션 params 설명',
                        'method' => 'POST',
                        'body' => ['comment' => "{{_global.modal?.request_comment ?? ''}}"],
                        'query' => ['comment' => 'yes'],
                        'headers' => ['comment' => 'h'],
                    ],
                ]],
            ]],
        ];

        return app(LayoutService::class)->stripDeveloperComments($layout);
    }

    /**
     * 배열을 순회하며 `comment` 키의 경로를 모은다.
     *
     * @param  array  $node  순회 대상
     * @param  array  $segments  현재 경로
     * @param  array  $out  결과 경로 목록
     * @return void 반환값 없음
     */
    private function collectCommentPaths(array $node, array $segments, array &$out): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'comment' || $key === '_comment') {
                $out[] = implode('.', array_merge($segments, [$key]));
            }
            if (is_array($value)) {
                $this->collectCommentPaths($value, array_merge($segments, [$key]), $out);
            }
        }
    }
}
