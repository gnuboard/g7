<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkTemplateService;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioKakaoApiClient;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * AlimtalkTemplateService — kapi 조회 위임 + 상태 배지 매핑 검증 (조회 전용).
 */
class AlimtalkTemplateServiceTest extends PluginTestCase
{
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    /**
     * @param  array<string, string>  $settings
     */
    private function service(array $settings = ['bizppurio_id' => 'biz01', 'api_key' => 'key01', 'sender_key' => 'SK_40']): AlimtalkTemplateService
    {
        $pluginSettings = Mockery::mock(PluginSettingsService::class);
        $pluginSettings->shouldReceive('get')->with(self::IDENTIFIER)->andReturn($settings);

        $kakao = new BizppurioKakaoApiClient($pluginSettings);

        return new AlimtalkTemplateService($kakao, $pluginSettings);
    }

    public function test_목록은_상태_배지를_부가한다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response([
                'code' => '200',
                'totalCount' => 1,
                'totalPage' => 1,
                'currentPage' => 1,
                'data' => ['list' => [
                    ['templateCode' => 'TW_1', 'templateName' => '주문완료', 'serviceStatus' => 'ACT'],
                ]],
            ], 200),
        ]);

        $result = $this->service()->list(['status' => 'ACT']);

        $this->assertCount(1, $result['templates']);
        $tpl = $result['templates'][0];
        $this->assertSame('ACT', $tpl['service_status']);
        $this->assertSame('green', $tpl['status_badge']['variant']);
        // 회귀 방지: 배지 label_key 는 프론트 lang 키 형식(templates.status.*)이어야 한다.
        // 백엔드 messages.php 네임스페이스(::messages.template.status.*)로 주면 프론트 $t() 가
        // 해석하지 못해 라벨 원문이 목록/상세에 그대로 노출된다(PO 브라우저 검수로 발견된 회귀).
        $this->assertSame(
            'sirsoft-message_bizppurio.templates.status.sendable',
            $tpl['status_badge']['label_key'],
        );
        // 조회 전용 — 상태별 가능 액션(available_actions)은 더 이상 부가하지 않는다.
        $this->assertArrayNotHasKey('available_actions', $tpl);
        $this->assertSame(1, $result['pagination']['total']);
    }

    public function test_목록은_status_keyword를_kapi에_전달한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200', 'data' => ['list' => []]], 200)]);

        $this->service()->list(['status' => 'REQ', 'keyword' => '주문', 'page' => 2, 'count' => 10]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/kakao/template/list')
                && $request['templateStatus'] === 'REQ'
                && $request['keyword'] === '주문'
                && $request['page'] === 2
                && $request['count'] === 10
                && $request['senderKey'] === 'SK_40';
        });
    }

    public function test_상세는_status_inspection으로_배지를_추론한다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response([
                'code' => '200',
                'data' => [
                    'templateCode' => 'TW_2',
                    'inspectionStatus' => 'APR',
                    'status' => 'A',
                    'block' => false,
                    'dormant' => false,
                ],
            ], 200),
        ]);

        $detail = $this->service()->detail('TW_2');

        // inspection=APR + status=A → ACT(정상)
        $this->assertSame('ACT', $detail['service_status']);
        $this->assertSame('green', $detail['status_badge']['variant']);
    }

    public function test_발신프로필은_data_success_배열을_반환한다(): void
    {
        // 규격(5.발신프로필관리): /v3/kakao/profile/use 응답 data 는 {success:[...], fail:[...]}
        // 2단 봉투다. 실제 발신프로필 목록은 data.success 안에 있으므로 그 배열을 반환해야 한다.
        Http::fake([
            'kapi.ppurio.com/*' => Http::response([
                'code' => '200',
                'data' => [
                    'success' => [
                        ['senderKey' => 'SK_40', 'name' => '테스트채널', 'status' => 'A'],
                    ],
                    'fail' => [],
                ],
            ], 200),
        ]);

        $profiles = $this->service()->senderProfiles();

        // success 배열이 그대로 반환되어야 한다(껍데기 {success,fail} 가 아님).
        $this->assertCount(1, $profiles);
        $this->assertSame('SK_40', $profiles[0]['senderKey']);
        $this->assertSame('테스트채널', $profiles[0]['name']);
        // 회귀 방지: data 통째 반환 시 노출되던 success/fail 키가 없어야 한다.
        $this->assertArrayNotHasKey('success', $profiles);
        $this->assertArrayNotHasKey('fail', $profiles);
    }

    public function test_발신프로필_키_미설정시_예외(): void
    {
        $this->expectException(BizppurioApiException::class);

        $this->service(['bizppurio_id' => 'biz01', 'api_key' => 'key01', 'sender_key' => ''])
            ->list();
    }

    public function test_kapi_실패코드시_예외에_결과코드가_담긴다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '7204', 'message' => '템플릿 불일치'], 200),
        ]);

        try {
            $this->service()->list();
            $this->fail('예외가 발생해야 한다.');
        } catch (BizppurioApiException $e) {
            $this->assertSame('7204', $e->getResultCode());
            $this->assertStringContainsString('템플릿 불일치', $e->getMessage());
        }
    }

    public function test_목록조회는_kapi_508을_빈_목록으로_처리한다(): void
    {
        // 카카오 결과코드 508 = "요청한 데이터가 없음"(13.응답코드정의.md). 목록 검색에서
        // 매칭 결과가 0건일 때 카카오가 이 코드로 응답하므로, 진짜 에러가 아니라 빈 목록으로
        // 취급해야 한다(PO 실측: "댓글"은 200 정상 필터링, "대글"처럼 매칭 없는 키워드만 508).
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '508', 'message' => '요청한 데이타가 없습니다.'], 200),
        ]);

        $result = $this->service()->list(['keyword' => '대글']);

        $this->assertSame([], $result['templates']);
        $this->assertSame(0, $result['pagination']['total']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
