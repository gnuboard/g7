<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

/**
 * 관리자 취소·환불 직권 접수 요청 (§3-11 — 종전 [주문취소] 의 흡수)
 *
 * 형태는 종전 관리자 주문취소(`CancelOrderRequest`)와 같다 — 같은 모달이 같은 본문을 보낸다. 입구가
 * 둘이면 게이트가 둘이 되므로 규칙은 부모 한 곳에 둔다. 자격은 D5 물리 게이트(아직 보내지 않은 상품)를
 * 서비스가 판정한다 — `order_settings.cancellable_statuses` 는 고객 축으로만 남는다.
 */
class AdminStoreCancelRefundRequest extends CancelOrderRequest {}
