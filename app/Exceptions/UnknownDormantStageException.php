<?php

namespace App\Exceptions;

use LogicException;

/**
 * 휴면 생명주기에 없는 단계 식별자로 대상 쿼리를 요청하면 발생합니다.
 *
 * 단계는 `DormantAccountService::STAGES` 네 가지뿐이고, 목록 API 는 서비스를 부르기 전에
 * 같은 목록으로 걸러 404 로 답합니다. 이 예외가 난다면 호출하는 코드가 목록을 거치지 않은 것입니다.
 */
class UnknownDormantStageException extends LogicException
{
    /**
     * @param  string  $stage  요청된 단계 식별자
     */
    public function __construct(public readonly string $stage)
    {
        parent::__construct(__('privacy.targets.unknown_stage'));
    }
}
