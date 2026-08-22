<?php

namespace Tests\Feature\Search;

use App\Helpers\ResponseHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\TestCase;

/**
 * 색인 누락 안내가 **응답을 타고 화면까지 도달하는지** 고정하는 테스트
 *
 * 색인이 비면 검색은 오류 없이 0건을 돌려줍니다. 운영자가 알 방법은 업데이트 응답에
 * 실려 오는 `search_index` 페이로드뿐이므로, 그 페이로드가 중간에서 사라지면
 * 점검 기능 자체가 있으나 마나 한 상태가 됩니다.
 *
 * `SearchIndexRebuildOptInTest` 는 "재생성을 하느냐" 를 고정하고, 이 테스트는
 * "그 결과를 알려 주느냐" 를 고정합니다.
 */
class SearchIndexNoticeReachesClientTest extends TestCase
{
    /**
     * 리소스에 실은 부가 데이터가 응답 본문에 살아남는지 검증합니다.
     *
     * `JsonResource::resolve()` 는 `additional()` 을 버립니다. 응답 헬퍼가 그것만
     * 호출하면 `search_index` 가 조용히 사라집니다.
     *
     * @return void
     */
    public function test_리소스_부가데이터가_응답에_실린다(): void
    {
        $resource = (new PayloadCarrierResource(['identifier' => 'sample-module']))
            ->additional(['search_index' => ['rebuilt' => false, 'stale_count' => 1]]);

        $body = json_decode(
            ResponseHelper::successWithResource('messages.success', $resource)->getContent(),
            true
        );

        $this->assertArrayHasKey('search_index', $body, '색인 안내가 응답에서 사라졌습니다');
        $this->assertFalse($body['search_index']['rebuilt']);
        $this->assertSame(1, $body['search_index']['stale_count']);
        $this->assertSame('sample-module', $body['data']['identifier'], '리소스 본문은 그대로 유지되어야 합니다');
    }

    /**
     * 부가 데이터가 없을 때 응답 형태가 달라지지 않는지 검증합니다.
     *
     * @return void
     */
    public function test_부가데이터가_없으면_응답_형태가_그대로다(): void
    {
        $body = json_decode(
            ResponseHelper::successWithResource(
                'messages.success',
                new PayloadCarrierResource(['identifier' => 'sample-module'])
            )->getContent(),
            true
        );

        $this->assertSame(['success', 'message', 'data'], array_keys($body));
    }

    /**
     * 업데이트 성공 메시지의 치환자가 실제 값으로 바뀌는지 검증합니다.
     *
     * 치환자가 남으면 API 소비자(외부 클라이언트 포함)가 `:module` 같은 원문을 그대로 봅니다.
     *
     * @return void
     */
    public function test_업데이트_성공_메시지의_치환자가_치환된다(): void
    {
        $body = json_decode(
            ResponseHelper::successWithResource(
                'modules.update_success',
                new PayloadCarrierResource(['identifier' => 'sirsoft-page']),
                200,
                ['module' => 'sirsoft-page', 'version' => '1.0.2']
            )->getContent(),
            true
        );

        $this->assertStringNotContainsString(':module', $body['message']);
        $this->assertStringNotContainsString(':version', $body['message']);
        $this->assertStringContainsString('sirsoft-page', $body['message']);
        $this->assertStringContainsString('1.0.2', $body['message']);
    }

    /**
     * 모듈 업데이트 컨트롤러가 메시지 파라미터를 실제로 넘기는지 정적으로 고정합니다.
     *
     * @return void
     */
    public function test_모듈_플러그인_컨트롤러가_메시지_파라미터를_넘긴다(): void
    {
        foreach ([
            app_path('Http/Controllers/Api/Admin/ModuleController.php'),
            app_path('Http/Controllers/Api/Admin/PluginController.php'),
        ] as $path) {
            $source = file_get_contents($path);

            $this->assertMatchesRegularExpression(
                "/update_success',\s*\n?\s*\(new \w+Resource/",
                $source,
                basename($path).': 업데이트 성공 응답 형태가 바뀌었습니다 — 파라미터 전달 여부를 재확인하세요'
            );
            $this->assertStringContainsString(
                "'version' =>",
                $source,
                basename($path).': 성공 메시지에 version 파라미터가 전달되지 않습니다'
            );
        }
    }
}

/**
 * 부가 데이터 유실을 검증하기 위한 최소 리소스.
 */
class PayloadCarrierResource extends JsonResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return ['identifier' => $this->resource['identifier']];
    }
}
