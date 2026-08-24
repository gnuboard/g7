<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Enums;

use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * BizppurioTemplateStatus — kapi serviceStatus 환원 매핑과 상태 판정 헬퍼 검증 (#597 §3.4).
 */
class BizppurioTemplateStatusTest extends PluginTestCase
{
    /**
     * kapi serviceStatus 코드 → 우리 상태 매핑 전수 검증.
     */
    /**
     * @effects status_enum_maps_kapi_service_status_vocabulary
     */
    public function test_service_status_매핑_전수(): void
    {
        $this->assertSame(BizppurioTemplateStatus::Draft, BizppurioTemplateStatus::tryFromServiceStatus('REG'));
        $this->assertSame(BizppurioTemplateStatus::Requested, BizppurioTemplateStatus::tryFromServiceStatus('REQ'));
        $this->assertSame(BizppurioTemplateStatus::Rejected, BizppurioTemplateStatus::tryFromServiceStatus('REJ'));
        $this->assertSame(BizppurioTemplateStatus::Approved, BizppurioTemplateStatus::tryFromServiceStatus('RDY'));
        $this->assertSame(BizppurioTemplateStatus::Approved, BizppurioTemplateStatus::tryFromServiceStatus('ACT'));
        $this->assertSame(BizppurioTemplateStatus::Stopped, BizppurioTemplateStatus::tryFromServiceStatus('STP'));
        $this->assertSame(BizppurioTemplateStatus::Blocked, BizppurioTemplateStatus::tryFromServiceStatus('BLK'));
        $this->assertSame(BizppurioTemplateStatus::Dormant, BizppurioTemplateStatus::tryFromServiceStatus('DMT'));
    }

    /**
     * 알 수 없는 코드는 null — 동기화 호출측이 상태를 덮어쓰지 않고 건너뛴다.
     */
    /**
     * @effects unknown_service_status_does_not_overwrite_state
     */
    public function test_미지의_service_status는_null(): void
    {
        $this->assertNull(BizppurioTemplateStatus::tryFromServiceStatus('XX'));
    }

    /**
     * serviceStatus 필드가 있으면 유도 없이 그대로 사용한다.
     */
    public function test_상세_유도는_service_status_필드를_우선한다(): void
    {
        $this->assertSame('ACT', BizppurioTemplateStatus::serviceStatusFromDetail([
            'serviceStatus' => 'ACT',
            // 아래 필드는 무시되어야 한다 (serviceStatus 우선).
            'inspectionStatus' => 'REJ',
            'status' => 'S',
        ]));
    }

    /**
     * block 플래그는 최우선으로 BLK 를 유도한다.
     */
    public function test_상세_유도_block은_blk(): void
    {
        $this->assertSame('BLK', BizppurioTemplateStatus::serviceStatusFromDetail([
            'inspectionStatus' => 'APR',
            'status' => 'A',
            'block' => true,
        ]));
    }

    /**
     * dormant 플래그는 DMT 를 유도한다.
     */
    public function test_상세_유도_dormant는_dmt(): void
    {
        $this->assertSame('DMT', BizppurioTemplateStatus::serviceStatusFromDetail([
            'inspectionStatus' => 'APR',
            'status' => 'A',
            'dormant' => true,
        ]));
    }

    /**
     * inspectionStatus REQ(검수중)는 REQ 를 유도한다.
     */
    public function test_상세_유도_inspection_re_q는_req(): void
    {
        $this->assertSame('REQ', BizppurioTemplateStatus::serviceStatusFromDetail([
            'inspectionStatus' => 'REQ',
            'status' => 'R',
        ]));
    }

    /**
     * inspectionStatus REJ(반려)는 REJ 를 유도한다.
     */
    public function test_상세_유도_inspection_re_j는_rej(): void
    {
        $this->assertSame('REJ', BizppurioTemplateStatus::serviceStatusFromDetail([
            'inspectionStatus' => 'REJ',
            'status' => 'R',
        ]));
    }

    /**
     * 승인(APR) + status S(중지)는 STP 를 유도한다.
     */
    public function test_상세_유도_ap_r_상태_s는_stp(): void
    {
        $this->assertSame('STP', BizppurioTemplateStatus::serviceStatusFromDetail([
            'inspectionStatus' => 'APR',
            'status' => 'S',
        ]));
    }

    /**
     * 승인(APR) + status A(정상)는 ACT 를 유도한다.
     */
    public function test_상세_유도_ap_r_상태_a는_act(): void
    {
        $this->assertSame('ACT', BizppurioTemplateStatus::serviceStatusFromDetail([
            'inspectionStatus' => 'APR',
            'status' => 'A',
        ]));
    }

    /**
     * 승인(APR) + status R(발송전)은 RDY 를 유도한다.
     */
    public function test_상세_유도_ap_r_상태_r은_rdy(): void
    {
        $this->assertSame('RDY', BizppurioTemplateStatus::serviceStatusFromDetail([
            'inspectionStatus' => 'APR',
            'status' => 'R',
        ]));
    }

    /**
     * 판정 조건이 하나도 성립하지 않으면 기본값 REG(등록)를 유도한다.
     */
    public function test_상세_유도_기본값은_reg(): void
    {
        $this->assertSame('REG', BizppurioTemplateStatus::serviceStatusFromDetail([
            'inspectionStatus' => 'REG',
            'status' => 'R',
        ]));
        $this->assertSame('REG', BizppurioTemplateStatus::serviceStatusFromDetail([]));
    }

    /**
     * isApproved 는 Approved 상태만 true 다 (발송 게이트 근거).
     */
    public function test_is_approved는_승인만_true(): void
    {
        $this->assertTrue(BizppurioTemplateStatus::Approved->isApproved());

        $this->assertFalse(BizppurioTemplateStatus::Draft->isApproved());
        $this->assertFalse(BizppurioTemplateStatus::Requested->isApproved());
        $this->assertFalse(BizppurioTemplateStatus::Rejected->isApproved());
        $this->assertFalse(BizppurioTemplateStatus::Stopped->isApproved());
        $this->assertFalse(BizppurioTemplateStatus::Blocked->isApproved());
        $this->assertFalse(BizppurioTemplateStatus::Dormant->isApproved());
    }

    /**
     * allowsContentEdit 는 Draft·Rejected 만 true 다 (kapi update/delete 허용 상태, 부록 A-4).
     */
    public function test_allows_content_edit는_draft와_rejected만_true(): void
    {
        $this->assertTrue(BizppurioTemplateStatus::Draft->allowsContentEdit());
        $this->assertTrue(BizppurioTemplateStatus::Rejected->allowsContentEdit());

        $this->assertFalse(BizppurioTemplateStatus::Requested->allowsContentEdit());
        $this->assertFalse(BizppurioTemplateStatus::Approved->allowsContentEdit());
        $this->assertFalse(BizppurioTemplateStatus::Stopped->allowsContentEdit());
        $this->assertFalse(BizppurioTemplateStatus::Blocked->allowsContentEdit());
        $this->assertFalse(BizppurioTemplateStatus::Dormant->allowsContentEdit());
    }
}
