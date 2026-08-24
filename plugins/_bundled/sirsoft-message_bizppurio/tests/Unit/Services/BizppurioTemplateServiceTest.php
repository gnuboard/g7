<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioTemplateStateException;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioTemplateRepository;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioKakaoApiClient;
use Plugins\Sirsoft\MessageBizppurio\Services\BizppurioTemplateService;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * BizppurioTemplateService — 라이프사이클 오케스트레이션 검증 (#597 §3.2·§3.4).
 *
 * 채번(codeCheck 재시도)·검수 신청(add/update 분기)·전이 가드·동기화(승인 스냅샷 동결·
 * 반려 사유 저장)·삭제(카카오측 조건부 동반 삭제)를 Http::fake 요청 캡처로 검증한다.
 * kapi 는 오류도 HTTP 200 + body code 로 반환한다(부록 A-9) — fake 응답도 그 규약을 따른다.
 */
class BizppurioTemplateServiceTest extends PluginTestCase
{
    /** kapi 도메인 매처 */
    private const KAPI = 'kapi.ppurio.com/*';

    /**
     * Http::fake 응답 봉투를 만듭니다 (kapi 는 실패도 HTTP 200 + body code).
     *
     * @param  string  $code  kapi 결과코드
     * @param  array<string, mixed>  $extra  data 등 추가 필드
     */
    private function kapiResponse(string $code = '200', array $extra = []): array
    {
        return array_merge(['code' => $code, 'message' => $code === '200' ? 'success' : 'kapi failure '.$code], $extra);
    }

    /**
     * 서비스를 조립합니다 (실 저장소 + 실 클라이언트 + mock 설정).
     */
    private function makeService(string $senderKey = 'SK_TEST'): BizppurioTemplateService
    {
        $settings = Mockery::mock(PluginSettingsService::class);
        $settings->shouldReceive('get')
            ->with('sirsoft-message_bizppurio')
            ->andReturn(['bizppurio_id' => 'biz1', 'api_key' => 'key1']);
        $settings->shouldReceive('get')
            ->with('sirsoft-message_bizppurio', 'sender_key', '')
            ->andReturn($senderKey);

        return new BizppurioTemplateService(
            new BizppurioTemplateRepository,
            new BizppurioKakaoApiClient($settings),
            $settings,
        );
    }

    /**
     * 템플릿 행을 DB 에 만듭니다.
     *
     * @param  array<string, mixed>  $attrs  오버라이드
     */
    private function row(array $attrs = []): BizppurioTemplate
    {
        return BizppurioTemplate::create(array_merge([
            'notification_type' => 'welcome',
            'status' => BizppurioTemplateStatus::Draft->value,
            'content' => ['templateName' => '환영', 'templateMessageType' => 'BA', 'templateEmphasizeType' => 'NONE', 'templateContent' => '#{name}님 환영합니다', 'categoryCode' => '001001'],
        ], $attrs));
    }

    public function test_create는_draft_상태로_생성한다(): void
    {
        $template = $this->makeService()->create([
            'notification_type' => 'welcome',
            'content' => ['templateName' => '환영', 'templateContent' => '본문'],
        ]);

        $this->assertSame(BizppurioTemplateStatus::Draft, $template->status);
        $this->assertDatabaseHas('bizppurio_templates', ['notification_type' => 'welcome']);
    }

    public function test_draft에서_content를_수정할_수_있다(): void
    {
        $template = $this->row();

        $updated = $this->makeService()->update($template, [
            'content' => ['templateName' => '환영2', 'templateContent' => '새 본문'],
        ]);

        $this->assertSame('환영2', $updated->content['templateName']);
    }

    /**
     * @effects content_edit_locked_outside_draft_rejected
     */
    public function test_requested_상태에서_content_변경은_거부된다(): void
    {
        $template = $this->row(['status' => BizppurioTemplateStatus::Requested->value]);

        $this->expectException(BizppurioTemplateStateException::class);

        $this->makeService()->update($template, [
            'content' => ['templateName' => '변경', 'templateContent' => '변경 본문'],
        ]);
    }

    /**
     * @effects sms_delivery_fields_editable_in_any_status
     */
    public function test_requested_상태라도_sms_설정은_수정할_수_있다(): void
    {
        $template = $this->row(['status' => BizppurioTemplateStatus::Requested->value]);

        $updated = $this->makeService()->update($template, [
            'fallback_sms_enabled' => true,
            'sms_body' => ['ko' => '[샵] #{name} 님 환영'],
        ]);

        $this->assertTrue($updated->fallback_sms_enabled);
        $this->assertSame(['ko' => '[샵] #{name} 님 환영'], $updated->sms_body);
    }

    /**
     * @effects content_edit_locked_outside_draft_rejected
     */
    public function test_requested_상태에서는_동일_content_전달도_거부된다(): void
    {
        $template = $this->row(['status' => BizppurioTemplateStatus::Requested->value]);

        // content 키가 실리면 값의 동일 여부와 무관하게 잠긴다 (#597 §3.2).
        // "값이 달라질 때만 막는다" 완화는 라운드 3 에서 철회했다 — DB 가 JSON 키 순서를
        // 정규화해 저장하므로 동일성 비교가 성립하지 않았고(항상 '변경됨'), 화면도 검수중·
        // 승인 상태에서는 수정 진입 자체를 막고 있어 완화가 보호하는 경로가 없었다.
        $this->expectException(BizppurioTemplateStateException::class);

        $this->makeService()->update($template, [
            'content' => $template->content,
            'sms_only' => true,
        ]);
    }

    /**
     * @effects sms_delivery_fields_editable_in_any_status
     */
    public function test_approved_상태에서도_content_키가_없으면_수정된다(): void
    {
        $template = $this->row(['status' => BizppurioTemplateStatus::Approved->value]);

        // 발송 설정만 보내는 경로(행 토글·SMS 모달)는 content 키를 싣지 않으므로 잠금 대상이 아니다.
        $updated = $this->makeService()->update($template, ['sms_only' => true]);

        $this->assertTrue($updated->sms_only);
    }

    /**
     * @effects content_edit_locked_outside_draft_rejected
     */
    public function test_approved_상태에서_content_변경은_거부된다(): void
    {
        $template = $this->row(['status' => BizppurioTemplateStatus::Approved->value]);

        $this->expectException(BizppurioTemplateStateException::class);

        $this->makeService()->update($template, [
            'content' => ['templateName' => '변경', 'templateContent' => '변경 본문'],
        ]);
    }

    public function test_upsert_delivery는_행이_없으면_draft로_생성한다(): void
    {
        $template = $this->makeService()->upsertDelivery('welcome', ['sms_only' => true, 'sms_body' => ['ko' => '본문']]);

        $this->assertSame(BizppurioTemplateStatus::Draft, $template->status);
        $this->assertTrue($template->sms_only);
        $this->assertNull($template->content);
    }

    public function test_upsert_delivery는_기존_행의_발송_설정만_갱신한다(): void
    {
        $existing = $this->row(['status' => BizppurioTemplateStatus::Approved->value]);

        $template = $this->makeService()->upsertDelivery('welcome', ['fallback_sms_enabled' => true]);

        $this->assertSame($existing->id, $template->id);
        $this->assertTrue($template->fallback_sms_enabled);
        $this->assertSame(BizppurioTemplateStatus::Approved, $template->status, '발송 설정 upsert 는 검수 상태를 건드리지 않는다.');
    }

    /**
     * @scenario content_variant=ba_none, transition=draft_to_requested
     *
     * @effects request_generates_code_with_codecheck_retry_up_to_three_generations, first_request_calls_add_then_request_with_full_content_payload, request_snapshots_sender_key
     */
    public function test_최초_검수_신청은_채번_add_request_순으로_호출하고_requested가_된다(): void
    {
        Http::fake([self::KAPI => Http::response($this->kapiResponse())]);
        $template = $this->row();

        $updated = $this->makeService()->requestInspection($template);

        $this->assertSame(BizppurioTemplateStatus::Requested, $updated->status);
        $this->assertSame('SK_TEST', $updated->sender_key, '신청 당시 발신프로필을 스냅샷한다.');
        $this->assertNotNull($updated->requested_at);
        $this->assertMatchesRegularExpression('/^g7_[0-9a-f]{8}_1$/', (string) $updated->template_code, '자체 채번 형식(g7_{md5 8자}_{세대}).');

        // 호출 순서: codeCheck → add → request (총 3회)
        $paths = [];
        Http::assertSentCount(3);
        Http::assertSent(function ($request) use (&$paths) {
            $paths[] = parse_url($request->url(), PHP_URL_PATH);

            return true;
        });
        $this->assertSame([
            '/v3/kakao/template/codeCheck',
            '/v3/kakao/template/add',
            '/v3/kakao/template/request',
        ], $paths);
    }

    public function test_검수_신청의_add_요청_본문은_content와_채번_코드를_싣는다(): void
    {
        Http::fake([self::KAPI => Http::response($this->kapiResponse())]);
        $template = $this->row();

        $updated = $this->makeService()->requestInspection($template);

        Http::assertSent(function ($request) use ($updated) {
            if (parse_url($request->url(), PHP_URL_PATH) !== '/v3/kakao/template/add') {
                return false;
            }

            $body = $request->data();
            $this->assertSame('biz1', $body['bizId'] ?? null, '클라이언트가 bizId 를 주입해야 한다.');
            $this->assertSame('key1', $body['apiKey'] ?? null);
            $this->assertSame('SK_TEST', $body['senderKey'] ?? null);
            $this->assertSame($updated->template_code, $body['templateCode'] ?? null);
            $this->assertSame('환영', $body['templateName'] ?? null, 'content 필드가 등록 페이로드로 그대로 실려야 한다.');
            $this->assertSame('#{name}님 환영합니다', $body['templateContent'] ?? null);

            return true;
        });
    }

    public function test_code_check_충돌시_세대를_올려_재시도한다(): void
    {
        Http::fake([
            '*codeCheck*' => Http::sequence()
                ->push($this->kapiResponse('504'))
                ->push($this->kapiResponse('200')),
            self::KAPI => Http::response($this->kapiResponse()),
        ]);
        $template = $this->row();

        $updated = $this->makeService()->requestInspection($template);

        $this->assertStringEndsWith('_2', (string) $updated->template_code, '충돌 시 세대 2 로 재시도해야 한다.');
    }

    public function test_code_check가_전부_충돌하면_예외를_던지고_코드를_확정하지_않는다(): void
    {
        Http::fake([self::KAPI => Http::response($this->kapiResponse('504'))]);
        $template = $this->row();

        try {
            $this->makeService()->requestInspection($template);
            $this->fail('BizppurioApiException 이 발생해야 한다.');
        } catch (BizppurioApiException) {
            $this->assertNull($template->fresh()->template_code, '재시도 소진 시 코드가 확정되면 안 된다.');
            $this->assertSame(BizppurioTemplateStatus::Draft, $template->fresh()->status);
        }
    }

    /**
     * @effects rerequest_calls_update_not_add
     */
    public function test_재신청은_add가_아니라_update를_호출한다(): void
    {
        Http::fake([self::KAPI => Http::response($this->kapiResponse())]);
        $template = $this->row([
            'status' => BizppurioTemplateStatus::Rejected->value,
            'template_code' => 'g7_deadbeef_1',
            'sender_key' => 'SK_SNAP',
        ]);

        $this->makeService()->requestInspection($template);

        // 재신청: update → request (codeCheck·add 없음)
        $paths = [];
        Http::assertSent(function ($request) use (&$paths) {
            $paths[] = parse_url($request->url(), PHP_URL_PATH);

            return true;
        });
        $this->assertSame(['/v3/kakao/template/update', '/v3/kakao/template/request'], $paths);
    }

    public function test_content_없이_검수_신청하면_거부된다(): void
    {
        Http::fake();
        $template = $this->row(['content' => null]);

        $this->expectException(BizppurioTemplateStateException::class);

        try {
            $this->makeService()->requestInspection($template);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_approved_상태에서_검수_신청은_거부된다(): void
    {
        Http::fake();
        $template = $this->row(['status' => BizppurioTemplateStatus::Approved->value]);

        $this->expectException(BizppurioTemplateStateException::class);

        try {
            $this->makeService()->requestInspection($template);
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * @effects concurrent_duplicate_request_claims_single_submission
     */
    public function test_동시_중복_신청은_한_건만_kapi에_제출된다(): void
    {
        Http::fake([self::KAPI => Http::response($this->kapiResponse())]);
        $template = $this->row();
        // 경합 재현: 같은 draft 상태를 보는 두 번째 모델 핸들 — 더블 클릭·이중 탭에서
        // 각 요청의 route model binding 이 서로의 커밋을 모른 채 같은 행을 든 상황.
        $stale = BizppurioTemplate::query()->findOrFail($template->id);

        $this->makeService()->requestInspection($template);

        try {
            $this->makeService()->requestInspection($stale);
            $this->fail('BizppurioTemplateStateException 이 발생해야 한다.');
        } catch (BizppurioTemplateStateException) {
            // 첫 신청의 codeCheck·add·request 3회뿐 — 두 번째 신청은 kapi 에 도달하지 않는다.
            Http::assertSentCount(3);
            $this->assertSame(BizppurioTemplateStatus::Requested, $template->fresh()->status);
        }
    }

    public function test_kapi_add_실패시_사유_원문을_보존하고_상태를_바꾸지_않는다(): void
    {
        Http::fake([
            '*codeCheck*' => Http::response($this->kapiResponse()),
            '*template/add*' => Http::response(['code' => '507', 'message' => '유효하지 않은 발신프로필']),
        ]);
        $template = $this->row();

        try {
            $this->makeService()->requestInspection($template);
            $this->fail('BizppurioApiException 이 발생해야 한다.');
        } catch (BizppurioApiException $e) {
            $this->assertSame('유효하지 않은 발신프로필', $e->getMessage(), 'kapi 사유 원문이 보존돼야 한다.');
            $this->assertSame('507', $e->getResultCode());
            $this->assertSame(BizppurioTemplateStatus::Draft, $template->fresh()->status);
        }
    }

    /**
     * @scenario content_variant=ba_none, transition=requested_cancel
     *
     * @effects cancel_request_returns_to_draft
     */
    public function test_검수_신청_취소는_draft로_복귀한다(): void
    {
        Http::fake([self::KAPI => Http::response($this->kapiResponse())]);
        $template = $this->row([
            'status' => BizppurioTemplateStatus::Requested->value,
            'template_code' => 'g7_deadbeef_1',
            'sender_key' => 'SK_SNAP',
        ]);

        $updated = $this->makeService()->cancelRequest($template);

        $this->assertSame(BizppurioTemplateStatus::Draft, $updated->status);
        Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/v3/kakao/template/cancel_request'
            && ($request->data()['senderKey'] ?? null) === 'SK_SNAP');
    }

    public function test_검수중이_아니면_신청_취소가_거부된다(): void
    {
        Http::fake();
        $template = $this->row();

        $this->expectException(BizppurioTemplateStateException::class);

        $this->makeService()->cancelRequest($template);
    }

    /**
     * @scenario content_variant=ba_none, transition=approved_cancel
     *
     * @effects cancel_approval_returns_to_draft_keeping_snapshot, cancel_approval_immediately_blocks_dispatch_gate
     */
    public function test_승인_취소는_draft로_복귀하되_승인_스냅샷을_유지한다(): void
    {
        Http::fake([self::KAPI => Http::response($this->kapiResponse())]);
        $approved = ['templateContent' => '승인된 본문'];
        $template = $this->row([
            'status' => BizppurioTemplateStatus::Approved->value,
            'template_code' => 'g7_deadbeef_1',
            'approved_content' => $approved,
            'approved_at' => now(),
        ]);

        $updated = $this->makeService()->cancelApproval($template);

        $this->assertSame(BizppurioTemplateStatus::Draft, $updated->status);
        $this->assertSame($approved, $updated->approved_content, '승인 스냅샷은 유지된다 — 발송 차단은 status 게이트가 담당.');
        $this->assertFalse($updated->isAlimtalkSendable(), '스냅샷이 남아도 status 를 잃는 순간 발송 게이트가 차단돼야 한다.');
    }

    public function test_승인_상태가_아니면_승인_취소가_거부된다(): void
    {
        Http::fake();
        $template = $this->row();

        $this->expectException(BizppurioTemplateStateException::class);

        $this->makeService()->cancelApproval($template);
    }

    /**
     * @scenario content_variant=ba_none, transition=requested_to_approved
     *
     * @effects approval_transition_freezes_content_into_approved_content, manual_sync_updates_single_row
     */
    public function test_sync는_승인_전이시_content를_승인_스냅샷으로_동결한다(): void
    {
        Http::fake([
            '*template/detail*' => Http::response($this->kapiResponse('200', [
                'data' => ['templateCode' => 'g7_deadbeef_1', 'serviceStatus' => 'RDY'],
            ])),
        ]);
        $template = $this->row([
            'status' => BizppurioTemplateStatus::Requested->value,
            'template_code' => 'g7_deadbeef_1',
            'sender_key' => 'SK_SNAP',
        ]);

        $updated = $this->makeService()->sync($template);

        $this->assertSame(BizppurioTemplateStatus::Approved, $updated->status);
        $this->assertSame($template->content, $updated->approved_content, '승인 전이 시 content 가 발송 SSoT 로 동결돼야 한다.');
        $this->assertNotNull($updated->approved_at);
        $this->assertNotNull($updated->last_synced_at);
    }

    public function test_sync는_이미_승인이면_스냅샷을_다시_덮지_않는다(): void
    {
        Http::fake([
            '*template/detail*' => Http::response($this->kapiResponse('200', [
                'data' => ['templateCode' => 'g7_deadbeef_1', 'serviceStatus' => 'ACT'],
            ])),
        ]);
        $frozen = ['templateContent' => '승인 당시 본문'];
        $template = $this->row([
            'status' => BizppurioTemplateStatus::Approved->value,
            'template_code' => 'g7_deadbeef_1',
            'approved_content' => $frozen,
            'content' => ['templateContent' => '이후 수정한 본문(미승인)'],
        ]);

        $updated = $this->makeService()->sync($template);

        $this->assertSame($frozen, $updated->approved_content, '승인 유지 상태에서 스냅샷이 현재 content 로 덮이면 안 된다.');
    }

    /**
     * @scenario content_variant=ba_none, transition=requested_to_rejected
     *
     * @effects rejection_transition_stores_comments_detail
     */
    public function test_sync는_반려_전이시_comments를_저장한다(): void
    {
        $comments = [['status' => 'REJ', 'content' => '심사 기준 미달', 'createdAt' => '2026-08-19 10:00:00']];
        Http::fake([
            '*template/detail*' => Http::response($this->kapiResponse('200', [
                'data' => ['templateCode' => 'g7_deadbeef_1', 'serviceStatus' => 'REJ', 'comments' => $comments],
            ])),
        ]);
        $template = $this->row([
            'status' => BizppurioTemplateStatus::Requested->value,
            'template_code' => 'g7_deadbeef_1',
        ]);

        $updated = $this->makeService()->sync($template);

        $this->assertSame(BizppurioTemplateStatus::Rejected, $updated->status);
        $this->assertSame($comments, $updated->inspection_detail, '반려 사유 원문(comments)이 저장돼야 한다.');
    }

    public function test_sync는_코드가_없는_행을_kapi_호출_없이_그대로_반환한다(): void
    {
        Http::fake();
        $template = $this->row();

        $result = $this->makeService()->sync($template);

        $this->assertSame($template->id, $result->id);
        Http::assertNothingSent();
    }

    public function test_sync_requested는_대상이_없으면_kapi를_호출하지_않는다(): void
    {
        Http::fake();
        $this->row(); // draft 행만 존재

        $result = $this->makeService()->syncRequested();

        $this->assertSame(['checked' => 0, 'transitioned' => 0], $result);
        Http::assertNothingSent();
    }

    public function test_sync_requested는_list_일괄_대조로_전이한다(): void
    {
        Http::fake([
            '*template/list*' => Http::response($this->kapiResponse('200', [
                'data' => [
                    'totalPage' => 1,
                    'list' => [
                        ['templateCode' => 'g7_aaaa1111_1', 'serviceStatus' => 'ACT'],
                        ['templateCode' => 'g7_bbbb2222_1', 'serviceStatus' => 'REQ'],
                    ],
                ],
            ])),
        ]);
        $a = $this->row([
            'notification_type' => 'welcome',
            'status' => BizppurioTemplateStatus::Requested->value,
            'template_code' => 'g7_aaaa1111_1',
            'sender_key' => 'SK_SNAP',
        ]);
        $b = $this->row([
            'notification_type' => 'order_confirmed',
            'status' => BizppurioTemplateStatus::Requested->value,
            'template_code' => 'g7_bbbb2222_1',
            'sender_key' => 'SK_SNAP',
        ]);

        $result = $this->makeService()->syncRequested();

        $this->assertSame(2, $result['checked']);
        $this->assertSame(1, $result['transitioned']);
        $this->assertSame(BizppurioTemplateStatus::Approved, $a->fresh()->status);
        $this->assertSame(BizppurioTemplateStatus::Requested, $b->fresh()->status, '여전히 검수중이면 상태 유지.');
        // 행별 detail 호출 금지 — list 1회만 (레이트리밋 보호)
        Http::assertSentCount(1);
    }

    public function test_sync_requested는_반려_전이_행만_detail을_추가_호출해_사유를_확보한다(): void
    {
        $comments = [['status' => 'REJ', 'content' => '변수 규격 위반']];
        Http::fake([
            '*template/list*' => Http::response($this->kapiResponse('200', [
                'data' => ['totalPage' => 1, 'list' => [['templateCode' => 'g7_aaaa1111_1', 'serviceStatus' => 'REJ']]],
            ])),
            '*template/detail*' => Http::response($this->kapiResponse('200', [
                'data' => ['templateCode' => 'g7_aaaa1111_1', 'serviceStatus' => 'REJ', 'comments' => $comments],
            ])),
        ]);
        $row = $this->row([
            'status' => BizppurioTemplateStatus::Requested->value,
            'template_code' => 'g7_aaaa1111_1',
            'sender_key' => 'SK_SNAP',
        ]);

        $this->makeService()->syncRequested();

        $fresh = $row->fresh();
        $this->assertSame(BizppurioTemplateStatus::Rejected, $fresh->status);
        $this->assertSame($comments, $fresh->inspection_detail);
        Http::assertSentCount(2); // list 1 + detail 1
    }

    /**
     * @effects delete_removes_kakao_side_only_in_deletable_state
     */
    public function test_delete는_삭제_가능_상태면_카카오측도_함께_지운다(): void
    {
        Http::fake([self::KAPI => Http::response($this->kapiResponse())]);
        $template = $this->row([
            'template_code' => 'g7_deadbeef_1',
            'sender_key' => 'SK_SNAP',
        ]);

        $result = $this->makeService()->delete($template);

        $this->assertTrue($result['kakao_deleted']);
        $this->assertNull($result['kakao_skip_reason']);
        $this->assertDatabaseCount('bizppurio_templates', 0);
        Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/v3/kakao/template/delete');
    }

    public function test_delete는_삭제_불가_상태면_db행만_지우고_사유를_명시한다(): void
    {
        Http::fake();
        $template = $this->row([
            'status' => BizppurioTemplateStatus::Approved->value,
            'template_code' => 'g7_deadbeef_1',
        ]);

        $result = $this->makeService()->delete($template);

        $this->assertFalse($result['kakao_deleted']);
        $this->assertSame('state_not_deletable', $result['kakao_skip_reason']);
        $this->assertDatabaseCount('bizppurio_templates', 0);
        Http::assertNothingSent();
    }

    public function test_delete는_카카오측_실패에도_db행을_지운다(): void
    {
        Http::fake([self::KAPI => Http::response(['code' => '509', 'message' => '처리 불가 상태'])]);
        $template = $this->row(['template_code' => 'g7_deadbeef_1', 'sender_key' => 'SK_SNAP']);

        $result = $this->makeService()->delete($template);

        $this->assertFalse($result['kakao_deleted']);
        $this->assertSame('처리 불가 상태', $result['kakao_skip_reason']);
        $this->assertDatabaseCount('bizppurio_templates', 0);
    }

    public function test_카카오_미등록_행_delete는_kapi를_호출하지_않는다(): void
    {
        Http::fake();
        $template = $this->row();

        $result = $this->makeService()->delete($template);

        $this->assertSame('not_registered', $result['kakao_skip_reason']);
        Http::assertNothingSent();
    }

    public function test_휴면_해제는_release_후_상태를_재동기화한다(): void
    {
        Http::fake([
            '*template/release*' => Http::response($this->kapiResponse()),
            '*template/detail*' => Http::response($this->kapiResponse('200', [
                'data' => ['templateCode' => 'g7_deadbeef_1', 'serviceStatus' => 'ACT'],
            ])),
        ]);
        $template = $this->row([
            'status' => BizppurioTemplateStatus::Dormant->value,
            'template_code' => 'g7_deadbeef_1',
            'sender_key' => 'SK_SNAP',
            'approved_content' => ['templateContent' => '본문'],
        ]);

        $updated = $this->makeService()->releaseDormant($template);

        $this->assertSame(BizppurioTemplateStatus::Approved, $updated->status, '해제 후 카카오 실제 상태(ACT)로 재동기화돼야 한다.');
    }

    public function test_휴면_상태가_아니면_해제가_거부된다(): void
    {
        Http::fake();
        $template = $this->row();

        $this->expectException(BizppurioTemplateStateException::class);

        $this->makeService()->releaseDormant($template);
    }

    /**
     * 요약 맵은 SMS 본문의 유무·미리보기를 **모델 판정 결과로** 내보낸다 (#597 라운드 5 R4).
     *
     * 화면이 로케일 맵을 직접 훑으면 발송 게이트와 규칙이 갈린다 — 실제로 행 하단은
     * truthy 판정(공백만 있어도 "본문 있음")에 폴백 로케일을 'ko' 로 박아 두었고,
     * 발송은 trim 비교에 config('app.fallback_locale') 를 쓰고 있었다. 같은 판정을
     * 두 벌 두면 한쪽만 고쳐지고, 증상은 "화면엔 본문이 있는데 발송은 스킵" 뿐이다.
     *
     * @effects sms_body_presence_and_preview_resolved_by_server
     */
    public function test_요약맵이_sms_본문_유무와_미리보기를_서버_판정으로_내보낸다(): void
    {
        BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'sms_body' => ['ko' => '  [샵] 가입을 환영합니다.  ', 'en' => 'Welcome!'],
        ]);
        // 전 로케일이 공백뿐이면 발송은 스킵된다 — 화면도 "본문 없음" 이어야 한다.
        BizppurioTemplate::create([
            'notification_type' => 'order_completed',
            'sms_body' => ['ko' => '   ', 'en' => ''],
        ]);
        BizppurioTemplate::create([
            'notification_type' => 'comment_added',
            'sms_body' => null,
        ]);

        $map = $this->makeService()->summaryMap();

        $this->assertTrue($map['welcome']['has_sms_body']);
        $this->assertSame('  [샵] 가입을 환영합니다.  ', $map['welcome']['sms_body_preview']);

        $this->assertFalse($map['order_completed']['has_sms_body'], '공백뿐인 본문은 발송되지 않는다 — 화면도 없음으로 본다');
        $this->assertFalse($map['comment_added']['has_sms_body']);

        // 모달 시딩용 원본 맵은 그대로 유지된다(요약이 원본을 대체하지 않는다).
        // MySQL json 컬럼은 키를 (길이, 사전순)으로 정규화 저장하므로 키 순서는 비교하지 않는다(§13.1).
        $this->assertEqualsCanonicalizing(['ko' => '  [샵] 가입을 환영합니다.  ', 'en' => 'Welcome!'], $map['welcome']['sms_body']);
    }

    /**
     * 미리보기 로케일 해석이 모델과 같은 체인을 탄다 (#597 라운드 5 R4).
     *
     * @effects sms_body_presence_and_preview_resolved_by_server
     */
    public function test_요약맵_미리보기가_현재_로케일과_폴백_체인을_따른다(): void
    {
        BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'sms_body' => ['ko' => '한국어 본문', 'en' => 'English body'],
        ]);

        app()->setLocale('en');
        $this->assertSame('English body', $this->makeService()->summaryMap()['welcome']['sms_body_preview']);

        // 해당 로케일 본문이 없으면 fallback_locale 로 내려간다 (하드코딩 'ko' 가 아니다)
        config(['app.fallback_locale' => 'en']);
        app()->setLocale('ja');
        $this->assertSame('English body', $this->makeService()->summaryMap()['welcome']['sms_body_preview']);

        app()->setLocale('ko');
    }

    /**
     * 검수자 전달 의견은 kapi request 의 comment 로만 실린다 (#597 §18.7 — 제품 결정 2026-08-23).
     *
     * 카카오 심사 가이드가 요구하는 변수 '예시 텍스트' 를 전할 유일한 통로가 request comment 다.
     * 등록(add) 페이로드에는 섞이지 않고, 행에도 남지 않는다.
     *
     * @effects inspection_request_carries_reviewer_comment
     */
    public function test_검수_신청의_comment는_kapi_request에만_실린다(): void
    {
        Http::fake([self::KAPI => Http::response($this->kapiResponse())]);
        $template = $this->row();

        $this->makeService()->requestInspection($template, '  변수 예시: #{name}=홍길동, #{order_number}=20260823-0001  ');

        Http::assertSent(function ($request) {
            if (parse_url($request->url(), PHP_URL_PATH) !== '/v3/kakao/template/request') {
                return false;
            }
            $this->assertSame('변수 예시: #{name}=홍길동, #{order_number}=20260823-0001', $request->data()['comment'] ?? null, '앞뒤 공백만 제거하고 원문 그대로 싣는다.');

            return true;
        });
        Http::assertSent(function ($request) {
            if (parse_url($request->url(), PHP_URL_PATH) !== '/v3/kakao/template/add') {
                return false;
            }
            $this->assertArrayNotHasKey('comment', $request->data(), 'comment 는 등록(add) 페이로드에 섞이지 않는다.');

            return true;
        });
    }

    /**
     * @effects inspection_request_carries_reviewer_comment
     */
    public function test_검수_신청의_comment가_공백이면_kapi_request에_comment_키를_싣지_않는다(): void
    {
        Http::fake([self::KAPI => Http::response($this->kapiResponse())]);

        $this->makeService()->requestInspection($this->row(), '   ');

        Http::assertSent(function ($request) {
            if (parse_url($request->url(), PHP_URL_PATH) !== '/v3/kakao/template/request') {
                return false;
            }
            $this->assertArrayNotHasKey('comment', $request->data(), '공백만 있는 의견은 미전달 — 클라이언트가 빈 comment 를 생략한다.');

            return true;
        });
    }
}
