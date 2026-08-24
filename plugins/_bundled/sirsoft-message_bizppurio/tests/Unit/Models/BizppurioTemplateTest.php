<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Models;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * BizppurioTemplate 모델 — 캐스트·알림톡 발송 게이트·unique 제약 검증 (#597).
 */
class BizppurioTemplateTest extends PluginTestCase
{
    /**
     * 기본 필드가 채워진 템플릿 행을 생성한다.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeTemplate(array $overrides = []): BizppurioTemplate
    {
        return BizppurioTemplate::create(array_merge([
            'notification_type' => 'order.completed.'.uniqid(),
            'alimtalk_enabled' => true,
            'template_code' => 'TW_'.uniqid(),
            'sender_key' => 'SK_TEST',
            'content' => ['templateName' => '주문완료', 'templateContent' => '#{name}님 주문이 완료되었습니다.'],
            'approved_content' => ['templateName' => '주문완료', 'templateContent' => '#{name}님 주문이 완료되었습니다.'],
            'status' => BizppurioTemplateStatus::Approved->value,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * JSON 컬럼 3종(content/approved_content/inspection_detail)이 배열로 저장·조회된다.
     */
    /**
     * @effects model_casts_content_snapshot_inspection_json
     */
    public function test_json_컬럼은_배열로_저장되고_조회된다(): void
    {
        $template = $this->makeTemplate([
            'content' => ['templateName' => 'T', 'buttons' => [['name' => '바로가기']]],
            'approved_content' => ['templateName' => 'T승인'],
            'inspection_detail' => [['content' => '반려 사유 원문']],
        ]);

        $fresh = BizppurioTemplate::query()->find($template->id);

        $this->assertSame('T', $fresh->content['templateName']);
        $this->assertSame('바로가기', $fresh->content['buttons'][0]['name']);
        $this->assertSame(['templateName' => 'T승인'], $fresh->approved_content);
        $this->assertSame([['content' => '반려 사유 원문']], $fresh->inspection_detail);
    }

    /**
     * status 컬럼은 BizppurioTemplateStatus enum 으로 캐스트된다.
     */
    public function test_status는_enum으로_캐스트된다(): void
    {
        $template = $this->makeTemplate(['status' => 'requested']);

        $fresh = BizppurioTemplate::query()->find($template->id);

        $this->assertSame(BizppurioTemplateStatus::Requested, $fresh->status);
    }

    /**
     * boolean 4종(alimtalk_enabled/fallback_sms_enabled/sms_only/is_active)이 캐스트된다.
     */
    public function test_boolean_4종_캐스트(): void
    {
        $template = $this->makeTemplate([
            'alimtalk_enabled' => 1,
            'fallback_sms_enabled' => 1,
            'sms_only' => 0,
            'is_active' => 0,
        ]);

        $fresh = BizppurioTemplate::query()->find($template->id);

        $this->assertTrue($fresh->alimtalk_enabled);
        $this->assertTrue($fresh->fallback_sms_enabled);
        $this->assertFalse($fresh->sms_only);
        $this->assertFalse($fresh->is_active);
    }

    /**
     * datetime 3종(requested_at/approved_at/last_synced_at)이 Carbon 으로 캐스트된다.
     */
    public function test_datetime_3종_캐스트(): void
    {
        $template = $this->makeTemplate([
            'requested_at' => '2026-08-01 09:00:00',
            'approved_at' => '2026-08-02 10:30:00',
            'last_synced_at' => '2026-08-03 11:45:00',
        ]);

        $fresh = BizppurioTemplate::query()->find($template->id);

        $this->assertInstanceOf(Carbon::class, $fresh->requested_at);
        $this->assertInstanceOf(Carbon::class, $fresh->approved_at);
        $this->assertInstanceOf(Carbon::class, $fresh->last_synced_at);
        $this->assertSame('2026-08-01 09:00:00', $fresh->requested_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-02 10:30:00', $fresh->approved_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-03 11:45:00', $fresh->last_synced_at->format('Y-m-d H:i:s'));
    }

    /**
     * 발송 게이트: 승인 + 활성 + 알림톡 사용 + 승인 스냅샷 존재 → 발송 가능.
     */
    public function test_알림톡_발송_게이트_모든_조건_충족시_true(): void
    {
        $this->assertTrue($this->makeTemplate()->isAlimtalkSendable());
    }

    /**
     * 발송 게이트: status 가 draft(승인 취소 복귀 포함)면 승인 스냅샷이 남아 있어도 차단.
     */
    public function test_알림톡_발송_게이트_draft_상태면_false(): void
    {
        $template = $this->makeTemplate(['status' => BizppurioTemplateStatus::Draft->value]);

        $this->assertFalse($template->isAlimtalkSendable());
    }

    /**
     * 발송 게이트: alimtalk_enabled 가 꺼져 있으면 차단.
     */
    public function test_알림톡_발송_게이트_alimtalk_enabled_false면_false(): void
    {
        $template = $this->makeTemplate(['alimtalk_enabled' => false]);

        $this->assertFalse($template->isAlimtalkSendable());
    }

    /**
     * 발송 게이트: is_active 가 꺼져 있으면 차단.
     */
    public function test_알림톡_발송_게이트_is_active_false면_false(): void
    {
        $template = $this->makeTemplate(['is_active' => false]);

        $this->assertFalse($template->isAlimtalkSendable());
    }

    /**
     * 발송 게이트: 승인 스냅샷(approved_content)이 null 이면 차단.
     */
    public function test_알림톡_발송_게이트_approved_content_null이면_false(): void
    {
        $template = $this->makeTemplate(['approved_content' => null]);

        $this->assertFalse($template->isAlimtalkSendable());
    }

    /**
     * 발송 게이트: 승인 스냅샷이 빈 배열이어도 차단 (내용 없는 스냅샷으로는 발송 불가).
     */
    public function test_알림톡_발송_게이트_approved_content_빈배열이면_false(): void
    {
        $template = $this->makeTemplate(['approved_content' => []]);

        $this->assertFalse($template->isAlimtalkSendable());
    }

    /**
     * notification_type 은 unique — 알림 1건당 1행 원칙을 DB 가 강제한다.
     */
    public function test_notification_type_중복_생성시_query_exception(): void
    {
        $this->makeTemplate(['notification_type' => 'order.completed.dup']);

        $this->expectException(QueryException::class);

        $this->makeTemplate(['notification_type' => 'order.completed.dup']);
    }

    public function test_sms_단독이면_승인된_알림톡도_발송하지_않는다(): void
    {
        // "SMS 단독" 은 운영자가 이 알림에서 알림톡을 쓰지 않겠다고 명시한 것이다(#597 §3.5).
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'alimtalk_enabled' => true,
            'is_active' => true,
            'sms_only' => true,
            'status' => BizppurioTemplateStatus::Approved->value,
            'approved_content' => ['templateContent' => '승인 본문'],
        ]);

        $this->assertFalse($template->isAlimtalkSendable());
    }

    public function test_sms_단독이_아니면_승인된_알림톡은_발송한다(): void
    {
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'alimtalk_enabled' => true,
            'is_active' => true,
            'sms_only' => false,
            'status' => BizppurioTemplateStatus::Approved->value,
            'approved_content' => ['templateContent' => '승인 본문'],
        ]);

        $this->assertTrue($template->isAlimtalkSendable());
    }
}
