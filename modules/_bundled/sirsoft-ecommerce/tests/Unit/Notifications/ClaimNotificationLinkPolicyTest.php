<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Notifications;

use App\Extension\ModuleManager;
use App\Notifications\GuestNotifiable;
use App\Services\NotificationRecipientCapability;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 알림 링크 정책 (2층)
 *
 * **이 파일에는 알림 유형 이름이 한 글자도 없다.** 정의 목록을 순회하며 수신자 세 종류로
 * 개인화를 돌려 링크가 그 사람이 쓸 수 있는 화면을 가리키는지만 본다.
 *
 * 유형 이름을 쓰면 결함과 같은 가정을 테스트가 공유하게 되고, 관리자 수신 알림이 늘어도
 * 그물에 걸리지 않는다(종전 테스트가 정확히 그렇게 낡았다).
 *
 * 정책:
 * - 클레임 화면을 열 수 있는 수신자(`claims.read`) → `/admin/ecommerce/claims…`
 * - 그 밖의 회원 · 비회원 → 관리자 주소가 **포함되지 않는다**
 */
class ClaimNotificationLinkPolicyTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private const CLAIM_READ_PERMISSION = 'sirsoft-ecommerce.claims.read';

    protected ClaimNotificationDataListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->listener = app(ClaimNotificationDataListener::class);
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * @effects claim_links_follow_the_recipient_not_the_notification_type
     */
    public function test_claim_links_follow_the_recipient_not_the_notification_type(): void
    {
        $claim = $this->requestedReturn();
        $staff = $this->createAdminUser([self::CLAIM_READ_PERMISSION]);
        $customer = $this->createUser();
        $guest = new GuestNotifiable('guest@example.test', '비회원');

        $types = $this->claimNotificationTypes();
        $this->assertNotEmpty($types, '클레임 알림 정의를 하나도 찾지 못하면 이 테스트는 아무것도 지키지 않습니다.');

        foreach ($types as $type) {
            $extracted = $this->listener->extractData($this->emptyResult(), $type, [$claim]);
            $data = $extracted['data'];
            $context = $extracted['context'];

            $staffData = $this->listener->personalizeClaimData($data, $staff, $type, $context);
            $this->assertStringContainsString(
                '/admin/ecommerce/claims',
                $staffData['claim_url'],
                "{$type}: 클레임 화면을 열 수 있는 운영자는 자기가 일할 수 있는 화면으로 가야 합니다."
            );

            foreach (['회원' => $customer, '비회원' => $guest] as $label => $recipient) {
                $recipientData = $this->listener->personalizeClaimData($data, $recipient, $type, $context);
                $this->assertStringNotContainsString(
                    '/admin/',
                    $recipientData['claim_url'],
                    "{$type}: {$label} 수신자에게 관리자 주소를 보내면 예외도 404 도 없이 빈 화면이 열립니다."
                );
            }
        }
    }

    /**
     * 신청번호가 비어도 운영자는 관리자 목록으로 간다.
     *
     * 종전에는 "번호가 없으면 고객 폴백" 이 관리자 판정보다 먼저 와서, 운영자가
     * 마이페이지로 밀려났다.
     *
     * @effects empty_claim_number_still_keeps_staff_on_the_admin_side
     */
    public function test_empty_claim_number_still_keeps_staff_on_the_admin_side(): void
    {
        $staff = $this->createAdminUser([self::CLAIM_READ_PERMISSION]);
        $type = $this->claimNotificationTypes()[0];

        $data = ['claim_url' => config('app.url').'/mypage/claims'];
        $context = ['claim_number' => ''];

        $result = $this->listener->personalizeClaimData($data, $staff, $type, $context);

        $this->assertStringEndsWith('/admin/ecommerce/claims', $result['claim_url']);
    }

    /**
     * 클레임 권한이 없는 운영자(예: 게시판 관리자)는 승격 대상이 아니다.
     *
     * @effects staff_without_claim_permission_is_not_promoted
     */
    public function test_staff_without_the_claim_permission_is_not_promoted(): void
    {
        $otherStaff = $this->createAdminUser(['sirsoft-board.posts.read']);
        $type = $this->claimNotificationTypes()[0];

        $data = ['claim_url' => config('app.url').'/mypage/claims/RT-1'];
        $result = $this->listener->personalizeClaimData($data, $otherStaff, $type, ['claim_number' => 'RT-1']);

        $this->assertStringNotContainsString('/admin/', $result['claim_url']);
    }

    /**
     * 판정이 수신자 수만큼 권한을 재조회하지 않아야 한다(수신자가 많은 알림의 N+1).
     *
     * @effects capability_memoizes_per_request
     */
    public function test_capability_memoizes_the_permission_lookup(): void
    {
        $staff = $this->createAdminUser([self::CLAIM_READ_PERMISSION]);
        $capability = app(NotificationRecipientCapability::class);

        $this->assertSame(
            $capability,
            app(NotificationRecipientCapability::class),
            '싱글턴이 아니면 요청 수명 메모가 성립하지 않습니다.'
        );

        $first = $capability->can($staff, self::CLAIM_READ_PERMISSION);
        $second = $capability->can($staff, self::CLAIM_READ_PERMISSION);

        $this->assertTrue($first);
        $this->assertSame($first, $second);
    }

    /**
     * 접수 상태의 반품 신청서를 하나 만듭니다 (링크 정책 검사용 재료).
     *
     * @return OrderReturn 반품 신청서
     */
    private function requestedReturn(): OrderReturn
    {
        $order = $this->deliveredClaimOrder();

        return app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'requested_by' => $order->user_id,
        ]);
    }

    /**
     * 클레임 알림 정의의 유형 목록을 **선언에서** 가져옵니다.
     *
     * @return array<int, string> 유형 목록
     */
    private function claimNotificationTypes(): array
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        $this->assertNotNull($module, '이커머스 모듈 로드 실패');

        $types = [];
        foreach ($module->getNotificationDefinitions() as $definition) {
            $type = $definition['type'] ?? '';
            // 이 리스너가 소관이라고 스스로 말하는 것만 고른다 — 목록을 손으로 적지 않는다.
            if ($this->listenerOwns($type)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * 이 리스너가 그 유형을 소관으로 삼는지 리스너 자신에게 묻습니다.
     *
     * @param  string  $type  알림 정의 유형
     * @return bool 소관이면 true
     */
    private function listenerOwns(string $type): bool
    {
        // 신청서 인자가 없으면 소관이어도 기본값이 돌아오므로 반환값으로는 두 경우를 가를 수
        // 없다. 그래서 리스너의 판정 자체를 본다 — 목록을 손으로 적으면 다시 낡는다.
        $method = new \ReflectionMethod(ClaimNotificationDataListener::class, 'isClaimNotificationType');

        return (bool) $method->invoke($this->listener, $type);
    }

    /**
     * extract_data 의 기본 입력값.
     *
     * @return array<string, mixed> 기본 구조
     */
    private function emptyResult(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
    }
}
