<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

/**
 * 내보내기 예상 행 수 요청 검증
 *
 * 요청과 같은 규칙(권한·범위·기간·필드)을 쓰되, 작업을 만들지 않으므로 사유·동시 요청 상한·중복 방지 키는
 * 보지 않는다 — 모달이 값을 바꿀 때마다 부르는 경로라 그 셋을 요구하면 입력 중에 막힌다.
 */
class EstimateExportRequest extends StoreExportRequest
{
    /**
     * {@inheritDoc}
     */
    protected function isEstimate(): bool
    {
        return true;
    }
}
