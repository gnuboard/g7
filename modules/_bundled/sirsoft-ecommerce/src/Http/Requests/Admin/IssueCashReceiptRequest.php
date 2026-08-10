<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesCashReceiptIssue;

/**
 * 현금영수증 발급 요청 (관리자)
 *
 * 주문 당시 미신청 건에 대한 사후 발급과, 재발급 실패 복구 후의 수동 발급에 사용된다.
 * 권한은 라우트 미들웨어(permission:admin,sirsoft-ecommerce.orders.update)에서 처리한다.
 */
class IssueCashReceiptRequest extends FormRequest
{
    use ValidatesCashReceiptIssue;
}
