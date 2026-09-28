<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 자동 승인의 **주체는 사람이 아니다** (SA-D11)
 *
 * `claim.auto_approve_refund_request` 가 켜져 있으면 고객이 낸 환불신청서가 접수 그 자리에서
 * 승인으로 넘어간다. 그 전이를 누른 사람은 없다 — 설정이 한 일이다.
 *
 * 그런데 접수 페이로드의 `processed_by`(= 신청한 고객)를 그대로 승인 전이에 넘기고 있어서,
 * 활동 로그 「환불 신청서 상태 변경(환불신청 → 환불승인)」의 작업자가 **고객 이름**으로
 * 남았다(#599 세션A 실측 2026-09-21 · RF20260921-1616082573 · 작업자 `MEAS599R-A-M`).
 * 나중에 「누가 승인했나」를 물으면 고객이 자기 신청을 승인한 것처럼 읽힌다.
 *
 * 같은 저장소가 이미 쓰는 관례가 있다 — 1-T 입금기한 만료 자동취소는 작업자를 **NULL** 로
 * 남기고 화면이 그것을 시스템으로 읽는다. 새 라벨을 만들지 않고 그 관례에 합류시킨다.
 *
 * 접수 줄(`claim_refund.request`)의 주체는 고객이 맞으므로 건드리지 않는다 — 고객이 낸
 * 신청이다. 갈리는 것은 **그다음 승인 전이**뿐이다.
 */
class AutoApproveActorIsSystemTest extends ModuleTestCase
{
    /**
     * 서비스 소스를 읽어 돌려줍니다.
     *
     * @return string 파일 내용
     */
    private function source(): string
    {
        return file_get_contents(
            base_path('modules/sirsoft-ecommerce/src/Services/OrderRefundRequestService.php')
        );
    }

    /**
     * D8 자동승인 전이는 접수자를 주체로 넘기지 않는다.
     *
     * @scenario actor=auto_approve
     *
     * @effects auto_approve_transition_has_no_human_actor
     *
     * @return void 반환값 없음
     */
    public function test_auto_approve_transition_does_not_carry_the_requester(): void
    {
        $source = $this->source();

        $start = strpos($source, 'protected function advanceAfterRequest');
        $this->assertNotFalse($start, 'advanceAfterRequest 를 찾지 못했습니다.');

        $end = strpos($source, 'protected function allItemsInAutoApproveStatuses', $start);
        $body = substr($source, $start, $end - $start);

        // 자동승인 전이 줄 — APPROVED 로 넘기는 transition 호출.
        $this->assertMatchesRegularExpression(
            '/transition\(\s*\$refund,\s*RefundStatusEnum::APPROVED/',
            $body,
            '자동승인 전이 호출을 찾지 못했습니다.'
        );

        // 그 호출이 $processedBy 를 실어 나르면 활동로그 주체가 고객이 된다.
        $this->assertDoesNotMatchRegularExpression(
            '/transition\(\s*\$refund,\s*RefundStatusEnum::APPROVED,\s*\[\s*\'processed_by\'\s*=>\s*\$processedBy/',
            $body,
            '자동승인 전이가 접수자(processed_by)를 주체로 넘기면 안 됩니다 — 사람이 누른 전이가 아닙니다.'
        );
    }

    /**
     * 관리자 [주문취소] 자동전이(`autoAdvance`)의 주체는 그대로 운영자로 남는다.
     *
     * 그 경로는 **사람이 버튼을 눌러** 시작한 것이라 주체가 있다. D8 토글 경로와 뭉뚱그려
     * 둘 다 NULL 로 만들면 운영자 조작의 책임 추적이 사라진다.
     *
     * @scenario actor=admin_auto_advance
     *
     * @effects admin_initiated_auto_advance_keeps_its_actor
     *
     * @return void 반환값 없음
     */
    public function test_admin_initiated_auto_advance_keeps_the_operator(): void
    {
        $source = $this->source();

        $start = strpos($source, 'protected function advanceAfterRequest');
        $end = strpos($source, 'protected function allItemsInAutoApproveStatuses', $start);
        $body = substr($source, $start, $end - $start);

        $this->assertMatchesRegularExpression(
            '/autoAdvance\(\s*\$refund,\s*\[\s*\'processed_by\'\s*=>\s*\$processedBy/',
            $body,
            '관리자 자동전이는 주체(processed_by)를 유지해야 합니다.'
        );
    }

    /**
     * 자동승인 경로에 「왜 주체가 없는가」가 주석으로 남아 있다.
     *
     * 이 자리는 나중에 「누락된 인자」로 보여 되돌리기 쉽다 — 비워 둔 것이 의도임을
     * 코드 옆에 적어 둔다.
     *
     * @scenario actor=auto_approve
     *
     * @effects auto_approve_transition_has_no_human_actor
     *
     * @return void 반환값 없음
     */
    public function test_the_missing_actor_is_explained_in_place(): void
    {
        $source = $this->source();

        $start = strpos($source, 'protected function advanceAfterRequest');
        $end = strpos($source, 'protected function allItemsInAutoApproveStatuses', $start);
        $body = substr($source, $start, $end - $start);

        $this->assertMatchesRegularExpression(
            '/시스템|자동|사람이 누른/u',
            $body,
            '주체를 비워 둔 이유가 그 자리에 적혀 있어야 합니다.'
        );
    }
}
