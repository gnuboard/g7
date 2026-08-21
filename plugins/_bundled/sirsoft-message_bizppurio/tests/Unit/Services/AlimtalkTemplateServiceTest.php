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
 * AlimtalkTemplateService — 작성 모달 참조 조회(카테고리·발신프로필) 위임 검증 (#597).
 *
 * 템플릿 목록/상세의 실시간 조회(구 Phase 5)는 DB 기반 라이프사이클
 * (BizppurioTemplateService)로 대체되어 제거됐다 — 이 서비스는 카테고리와
 * 발신프로필 조회만 남는다.
 */
class AlimtalkTemplateServiceTest extends PluginTestCase
{
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    /**
     * kapi 자격증명이 준비된 서비스 인스턴스를 생성한다.
     */
    private function service(): AlimtalkTemplateService
    {
        $pluginSettings = Mockery::mock(PluginSettingsService::class);
        $pluginSettings->shouldReceive('get')->with(self::IDENTIFIER)
            ->andReturn(['bizppurio_id' => 'biz01', 'api_key' => 'key01']);

        return new AlimtalkTemplateService(new BizppurioKakaoApiClient($pluginSettings));
    }

    /**
     * 카테고리 전체 조회는 data 배열을 그대로 반환한다.
     */
    public function test_카테고리는_data_배열을_반환한다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response([
                'code' => '200',
                'data' => [
                    ['code' => '001001', 'name' => '회원가입', 'groupName' => '회원'],
                    ['code' => '002001', 'name' => '구매완료', 'groupName' => '구매'],
                ],
            ], 200),
        ]);

        $categories = $this->service()->categories();

        $this->assertCount(2, $categories);
        $this->assertSame('001001', $categories[0]['code']);
        $this->assertSame('구매', $categories[1]['groupName']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/kakao/template/category/all')
            && $request['bizId'] === 'biz01'
            && $request['apiKey'] === 'key01');
    }

    /**
     * 카테고리 조회가 실패 코드로 응답하면 message 원문·resultCode 를 보존한 예외를 던진다.
     */
    public function test_카테고리_실패코드시_message와_결과코드를_예외에_보존한다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '405', 'message' => '지원하지 않는 기능입니다.'], 200),
        ]);

        try {
            $this->service()->categories();
            $this->fail('BizppurioApiException 이 발생해야 한다.');
        } catch (BizppurioApiException $e) {
            $this->assertSame('지원하지 않는 기능입니다.', $e->getMessage());
            $this->assertSame('405', $e->getResultCode());
        }
    }

    /**
     * 발신프로필은 data.success 배열만 반환한다 (success/fail 껍데기 미노출).
     */
    public function test_발신프로필은_data_success_배열만_반환한다(): void
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
        $this->assertArrayNotHasKey('success', $profiles);
        $this->assertArrayNotHasKey('fail', $profiles);
    }

    /**
     * 발신프로필 조회가 실패 코드로 응답하면 예외를 던진다.
     */
    public function test_발신프로필_실패코드시_예외(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '7204', 'message' => '발신프로필을 찾을 수 없습니다.'], 200),
        ]);

        try {
            $this->service()->senderProfiles();
            $this->fail('BizppurioApiException 이 발생해야 한다.');
        } catch (BizppurioApiException $e) {
            $this->assertSame('발신프로필을 찾을 수 없습니다.', $e->getMessage());
            $this->assertSame('7204', $e->getResultCode());
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
