<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioKakaoApiClient;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * BizppurioKakaoApiClient — kapi 단일 도메인, bizId+apiKey body 인증 검증.
 */
class BizppurioKakaoApiClientTest extends PluginTestCase
{
    private const IDENTIFIER = 'sirsoft-message_bizppurio';

    /**
     * @param  array<string, string>  $settings
     */
    private function makeSettings(array $settings): PluginSettingsService
    {
        $mock = Mockery::mock(PluginSettingsService::class);
        $mock->shouldReceive('get')->with(self::IDENTIFIER)->andReturn($settings);

        return $mock;
    }

    private function client(): BizppurioKakaoApiClient
    {
        return new BizppurioKakaoApiClient(
            $this->makeSettings(['bizppurio_id' => 'biz01', 'api_key' => 'key01']),
        );
    }

    public function test_발신프로필_조회는_bizid_apikey를_body에_싣는다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '200', 'data' => ['success' => []]], 200),
        ]);

        $result = $this->client()->getSenderProfiles();

        $this->assertTrue($this->client()->isSuccess($result));
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'kapi.ppurio.com/v3/kakao/profile/use')
                && $request['bizId'] === 'biz01'
                && $request['apiKey'] === 'key01';
        });
    }

    public function test_템플릿_목록은_senderkey를_포함한다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '200', 'data' => []], 200),
        ]);

        $this->client()->getTemplateList('SENDER_KEY', ['count' => 20]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/kakao/template/list')
                && $request['senderKey'] === 'SENDER_KEY'
                && $request['count'] === 20
                && $request['bizId'] === 'biz01';
        });
    }

    public function test_템플릿_상세_조회(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '200', 'data' => ['templateCode' => 'TW_1']], 200),
        ]);

        $result = $this->client()->getTemplateDetail('SK', 'TW_1');

        $this->assertSame('TW_1', $result['data']['templateCode']);
    }

    public function test_임의_엔드포인트_request_위임(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(['code' => '200'], 200),
        ]);

        $this->client()->request('/v3/kakao/template/add', ['templateName' => 'T']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/kakao/template/add')
            && $request['templateName'] === 'T');
    }

    public function test_자격증명_미설정시_예외(): void
    {
        $client = new BizppurioKakaoApiClient(
            $this->makeSettings(['bizppurio_id' => 'biz01', 'api_key' => '']),
        );

        $this->expectException(BizppurioApiException::class);
        $client->getSenderProfiles();
    }

    public function test_http_실패시_예외(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response([], 500)]);

        $this->expectException(BizppurioApiException::class);
        $this->client()->getSenderProfiles();
    }

    public function test_http_실패시_카카오_message와_code를_예외에_싣는다(): void
    {
        Http::fake([
            'kapi.ppurio.com/*' => Http::response(
                ['code' => '403', 'message' => '접근할 수 없는 IP 입니다. (114.207.113.206)'],
                403,
            ),
        ]);

        try {
            $this->client()->getSenderProfiles();
            $this->fail('BizppurioApiException 이 발생해야 한다.');
        } catch (BizppurioApiException $e) {
            $this->assertSame('접근할 수 없는 IP 입니다. (114.207.113.206)', $e->getMessage());
            $this->assertSame('403', $e->getResultCode());
            $this->assertSame(403, $e->getHttpStatus());
        }
    }

    public function test_http_실패_body에_message가_없으면_일반문구로_폴백한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response([], 500)]);

        try {
            $this->client()->getSenderProfiles();
            $this->fail('BizppurioApiException 이 발생해야 한다.');
        } catch (BizppurioApiException $e) {
            $this->assertSame(
                __('sirsoft-message_bizppurio::messages.error.kakao_request_failed'),
                $e->getMessage(),
            );
            $this->assertNull($e->getResultCode());
            $this->assertSame(500, $e->getHttpStatus());
        }
    }

    public function test_템플릿_등록은_add_경로로_pos_t한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200'], 200)]);

        $this->client()->addTemplate([
            'senderKey' => 'SK',
            'templateCode' => 'TW_1',
            'templateName' => '주문완료',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/kakao/template/add')
                && $request['senderKey'] === 'SK'
                && $request['templateCode'] === 'TW_1'
                && $request['templateName'] === '주문완료'
                && $request['bizId'] === 'biz01'
                && $request['apiKey'] === 'key01';
        });
    }

    public function test_템플릿_수정은_update_경로로_pos_t한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200'], 200)]);

        $this->client()->updateTemplate([
            'senderKey' => 'SK',
            'templateCode' => 'TW_1',
            'newTemplateCode' => 'TW_2',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/kakao/template/update')
                && $request['newTemplateCode'] === 'TW_2'
                && $request['bizId'] === 'biz01'
                && $request['apiKey'] === 'key01';
        });
    }

    public function test_템플릿_삭제는_delete_경로로_pos_t한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200'], 200)]);

        $this->client()->deleteTemplate('SK', 'TW_1');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/kakao/template/delete')
                && $request['senderKey'] === 'SK'
                && $request['templateCode'] === 'TW_1'
                && $request['bizId'] === 'biz01'
                && $request['apiKey'] === 'key01';
        });
    }

    public function test_템플릿_코드_중복검증은_code_check_경로로_pos_t한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200'], 200)]);

        $this->client()->checkTemplateCode('SK', 'TW_1');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/kakao/template/codeCheck')
                && $request['senderKey'] === 'SK'
                && $request['templateCode'] === 'TW_1'
                && $request['bizId'] === 'biz01'
                && $request['apiKey'] === 'key01';
        });
    }

    public function test_검수요청은_comment를_포함해_request_경로로_pos_t한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200'], 200)]);

        $this->client()->requestInspection('SK', 'TW_1', '변수는 주문번호입니다.');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/kakao/template/request')
                && $request['senderKey'] === 'SK'
                && $request['templateCode'] === 'TW_1'
                && $request['comment'] === '변수는 주문번호입니다.'
                && $request['bizId'] === 'biz01'
                && $request['apiKey'] === 'key01';
        });
    }

    public function test_검수요청_comment_미전달시_comment_키를_싣지_않는다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200'], 200)]);

        $this->client()->requestInspection('SK', 'TW_1');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/kakao/template/request')
                && $request['senderKey'] === 'SK'
                && ! array_key_exists('comment', $request->data())
                && $request['bizId'] === 'biz01';
        });
    }

    public function test_검수취소는_cancel_request_경로로_pos_t한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200'], 200)]);

        $this->client()->cancelInspection('SK', 'TW_1');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/kakao/template/cancel_request')
                && $request['senderKey'] === 'SK'
                && $request['templateCode'] === 'TW_1'
                && $request['bizId'] === 'biz01'
                && $request['apiKey'] === 'key01';
        });
    }

    public function test_승인취소는_cancel_approval_경로로_pos_t한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200'], 200)]);

        $this->client()->cancelApproval('SK', 'TW_1');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/kakao/template/cancel_approval')
                && $request['senderKey'] === 'SK'
                && $request['templateCode'] === 'TW_1'
                && $request['bizId'] === 'biz01'
                && $request['apiKey'] === 'key01';
        });
    }

    public function test_휴면해제는_release_경로로_pos_t한다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => Http::response(['code' => '200'], 200)]);

        $this->client()->releaseDormant('SK', 'TW_1');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v3/kakao/template/release')
                && $request['senderKey'] === 'SK'
                && $request['templateCode'] === 'TW_1'
                && $request['bizId'] === 'biz01'
                && $request['apiKey'] === 'key01';
        });
    }

    public function test_이미지_업로드는_multipart로_전송하고_최상위_image_필드를_반환한다(): void
    {
        // kapi 유일의 비-JSON(multipart) 엔드포인트 — 응답도 data 봉투가 아니라
        // 최상위 image 필드에 업로드 URL 이 실린다(부록 A-7).
        Http::fake([
            'kapi.ppurio.com/*' => Http::response([
                'code' => '200',
                'message' => 'success',
                'image' => 'https://mud-kage.kakao.com/dn/example.png',
            ], 200),
        ]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'biz_img_');
        file_put_contents($tmpPath, 'fake-png-bytes');

        try {
            $result = $this->client()->uploadTemplateImage($tmpPath, 'template.png');
        } finally {
            @unlink($tmpPath);
        }

        $this->assertSame('https://mud-kage.kakao.com/dn/example.png', $result['image']);
        $this->assertArrayNotHasKey('data', $result);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v3/kakao/image/alimtalk/template')) {
                return false;
            }

            if (! $request->isMultipart()) {
                return false;
            }

            // multipart 파트에 image 파일 + bizId/apiKey 필드가 실려야 한다.
            // 파일 contents 는 스트림 리소스로 실리므로(전송 후 close) 값 비교가 불가 —
            // 파트명 + 원본 파일명으로 단언한다.
            $parts = collect($request->data());

            return $request->hasFile('image', null, 'template.png')
                && $parts->contains(fn ($part) => ($part['name'] ?? null) === 'bizId' && ($part['contents'] ?? null) === 'biz01')
                && $parts->contains(fn ($part) => ($part['name'] ?? null) === 'apiKey' && ($part['contents'] ?? null) === 'key01');
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
