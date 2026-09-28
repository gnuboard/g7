<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Privacy\OrderRetentionHandler;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPrivacyRetentionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;

/**
 * 종결 주문 개인정보 보유기간 처리 서비스 (이슈 #636)
 *
 * 보유기간이 지난 종결 주문 **한 건**을 익명화하거나 삭제합니다. 대상 선정·순회·건수 보고는
 * {@see OrderRetentionHandler} 가 하고, 어떤 칸·테이블을 지우는지는
 * {@see OrderPrivacyRetentionRepositoryInterface} 가 압니다. 이 서비스는 순서·트랜잭션·부수 처리
 * (리뷰 파일·통계 캐시)를 맡습니다.
 *
 * ## 익명화 — NULL 이 아니라 치환값인 이유
 *
 * 주문자·수령인의 이름·연락처·우편번호·주소는 `ecommerce_order_addresses` 에서 **NOT NULL** 이라
 * NULL 을 넣으면 배치가 중간에 죽는다. 스키마는 건드리지 않고 고정 치환값({@see ANONYMIZED_VALUE})을
 * 넣으며, nullable 인 칸만 NULL 로 지운다(2026-09-18 확정). 주문번호·금액·상품·상태·시각은
 * 법정 보존 대상(전자상거래법 §6 거래 기록)이라 그대로 둔다 — 결제 플러그인이 주문번호로 역조회하는
 * 영수증·정산 대사 화면도 그래서 끊기지 않는다.
 *
 * 처리 후 그 주문은 **비회원 주문조회(주문자 연락처로 인증)와 관리자 주문 검색(주문자·수령인 이름·
 * 연락처)으로 찾을 수 없다.** 주문번호로 여는 관리자 상세는 그대로 열린다.
 *
 * 교환·반품·환불 신청서(#599)는 주문 상태를 바꾸지 않고 주문 곁에 남으므로 같은 기준으로 함께 가린다 —
 * 환불 계좌 명의·번호와 상세 사유·관리자 메모는 NULL, 교환 재배송지는 주문 배송지처럼 치환값. 신청서
 * 번호·사유 코드·금액·상태·첨부 사진은 청약철회 기록이라 남는다.
 *
 * ## 삭제 — 명시 삭제 전수
 *
 * DB CASCADE 에 기대지 않고 참조 테이블을 전부 명시 삭제한다(부수의무 #33). 주문 옵션에 NOT NULL
 * 로 묶인 **상품 리뷰는 이미지 파일째 함께 사라지고**, 교환·반품·환불 신청서와 **클레임 첨부 사진도
 * 파일째** 사라진다(#599 — 첨부 행은 주문 FK 가 없어 CASCADE 로도 남는다). 쿠폰 발급·마일리지 거래는
 * 행을 남기되 주문 연결만 끊긴다(nullOnDelete 와 같은 결과를 명시적으로). 결제 플러그인의 주문번호 문자열 역조회는
 * FK 가 없어 이 목록에 잡히지 않으므로 삭제 후 그 주문의 영수증·거래 조회가 불가능해진다(#46) —
 * 설정 화면의 삭제 경고가 이를 적고, 기본 방식을 익명화로 두는 근거다.
 */
class OrderPrivacyRetentionService
{
    /** NOT NULL 칸에 넣는 치환값 */
    public const ANONYMIZED_VALUE = '***';

    /** 익명화 완료 표지 — `order_meta` 의 키. 멱등 판정에 쓴다 */
    public const ANONYMIZED_MARKER = 'privacy_anonymized_at';

    /**
     * 주문 메타의 배송비 계산 입력(`calculation_input.shipping_address`)에서 NULL 로 지우는 주소 칸.
     *
     * @var array<int, string>
     */
    private const CALCULATION_ADDRESS_KEYS = ['zipcode', 'region', 'city', 'address'];

    /**
     * @param  ProductReviewService  $reviewService  리뷰 서비스 (삭제 모드의 리뷰·이미지 파기)
     * @param  OrderRepositoryInterface  $orderRepository  주문 저장소 (통계 캐시 무효화)
     * @param  OrderPrivacyRetentionRepositoryInterface  $retentionRepository  주문 개인정보 익명화·삭제 저장소
     * @param  ClaimAttachmentService  $claimAttachmentService  클레임 첨부 서비스 (삭제 모드의 첨부 파일 파기)
     */
    public function __construct(
        private ProductReviewService $reviewService,
        private OrderRepositoryInterface $orderRepository,
        private OrderPrivacyRetentionRepositoryInterface $retentionRepository,
        private ClaimAttachmentService $claimAttachmentService,
    ) {}

    /**
     * 주문 한 건의 개인정보를 익명화합니다.
     *
     * 수정 시각을 바꾸지 않는다 — 익명화는 주문 변경이 아니고, `updated_at` 은 레거시 주문의
     * 종결 시각 대용으로도 쓰인다.
     *
     * @param  Order  $order  대상 주문 (휴지통 포함)
     * @return void 반환값 없음
     */
    public function anonymize(Order $order): void
    {
        $meta = $this->scrubOrderMeta(is_array($order->order_meta) ? $order->order_meta : []);
        $meta[self::ANONYMIZED_MARKER] = now()->toIso8601String();

        DB::transaction(function () use ($order, $meta) {
            $this->retentionRepository->anonymizeOrder($order->id, $meta, self::ANONYMIZED_VALUE);

            // 주문 한 건 익명화 훅 — 확장이 자기 소유 칸(결제 플러그인의 payment_meta 등)에 남긴 개인정보
            // 사본을 같은 트랜잭션에서 지운다. 리스너가 던지면 익명화 표지까지 롤백되어 다음 실행이 다시 잡는다.
            // 훅 이름을 리터럴로 적는다 — 조립하면 문서 생성기와 이름 검색이 찾지 못한다.
            HookManager::doAction('sirsoft-ecommerce.order.after_anonymize', $order->id);
        });

        $this->orderRepository->forgetStatisticsCache();
    }

    /**
     * 주문 메타에 남은 수령인 정보 사본을 지웁니다.
     *
     * 주소 테이블만 가리면 사본이 남는다 — PG 결제에서 「배송지 저장」을 고르고 결제가 끝나지 않은
     * 주문(자동 취소되어 종결 대상이 된다)은 저장 대기 중인 배송지 전체를, 임시 주문을 거친 주문은
     * 배송비 계산에 쓴 주소를 메타에 갖고 있다. 배송비 계산 입력의 국가 코드는 개인을 가리키지 않아 둔다.
     *
     * @param  array<string, mixed>  $meta  주문 메타
     * @return array<string, mixed> 사본을 지운 주문 메타
     */
    private function scrubOrderMeta(array $meta): array
    {
        unset($meta['save_shipping_address'], $meta['shipping_info_for_save']);

        if (is_array($meta['calculation_input']['shipping_address'] ?? null)) {
            foreach (self::CALCULATION_ADDRESS_KEYS as $key) {
                $meta['calculation_input']['shipping_address'][$key] = null;
            }
        }

        return $meta;
    }

    /**
     * 주문 한 건과 그 참조 행을 영구 삭제합니다.
     *
     * 주문 옵션에 NOT NULL 로 묶인 리뷰를 이미지 파일째 먼저 지우고, 나머지 참조 행과 주문을
     * 저장소가 참조하는 쪽 → 참조되는 쪽 순서로 지운다. 한 트랜잭션이라 중간에 실패하면 그 주문은
     * 통째로 남고 다음 실행이 다시 대상으로 잡는다(리뷰 이미지 파일은 되돌려지지 않는다).
     *
     * 클레임 첨부는 관리자 주문 삭제(`OrderService::delete`)와 같은 순서다 — 행이 사라지기 전에 목록을
     * 잡아 두고, 행은 트랜잭션 안에서 지우고, 파일은 커밋 뒤에 지운다. 정리가 롤백되면 파일은 그대로
     * 남아 살아 있는 신청서의 첨부가 비지 않는다.
     *
     * 지운 뒤에는 관리자 삭제와 같은 `sirsoft-ecommerce.order.after_delete` 를 발행한다 — 주문 활동
     * 로그가 「주문 삭제」를 남기고, 확장이 기존 삭제 훅으로 자기 사본을 정리할 수 있다. 커밋 뒤에
     * 발행하므로 구독자는 실제로 지워진 주문만 받는다. `before_delete` 는 발행하지 않는다 — 게시판
     * 보유기간 삭제와 같이, 관리자 요청 전제의 사전 검사를 무인 배치에 걸지 않는다.
     *
     * @param  Order  $order  대상 주문 (휴지통 포함)
     * @return void 반환값 없음
     */
    public function purge(Order $order): void
    {
        $claimImages = $this->claimAttachmentService->imagesForOrder($order);

        DB::transaction(function () use ($order) {
            $this->reviewService->purgeForOrderOptions($this->retentionRepository->optionIds($order->id));
            $this->retentionRepository->purgeOrder($order->id);
        });

        $this->claimAttachmentService->deleteStoredFiles($claimImages);

        $this->orderRepository->forgetStatisticsCache();

        HookManager::doAction('sirsoft-ecommerce.order.after_delete', $order);
    }
}
