<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 프리셋 접근 권한 없음 예외
 *
 * 사용자가 본인 소유가 아닌 프리셋에 접근하려 할 때 발생합니다.
 */
class UnauthorizedPresetAccessException extends Exception
{
    /**
     * @param  int  $presetId  프리셋 ID
     */
    public function __construct(
        private int $presetId
    ) {
        parent::__construct(
            __('sirsoft-ecommerce::exceptions.unauthorized_preset_access', [
                'preset_id' => $presetId,
            ])
        );
    }

    /**
     * 프리셋 ID를 반환합니다.
     *
     * @return int
     */
    public function getPresetId(): int
    {
        return $this->presetId;
    }
}
