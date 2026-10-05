<?php

namespace App\Privacy\Contracts;

use App\Privacy\ManualRun\PrivacyManualRunService;

/**
 * 실행 권한 없이도 마지막 직권 실행 기록을 볼 수 있는 대상의 표지
 *
 * 상태 조회는 실행할 수 없는 관리자에게 실행 기록을 가린다 — 기록에 접수자 이름이 있기 때문이다
 * ({@see PrivacyManualRunService::statuses()}). 이 표지를 단 대상은 여기서 돌려주는
 * 열람 권한을 모두(전체 범위로) 가진 관리자에게도 기록을 준다. 실행 가능 여부는 여전히 `can_run` 으로만 판정한다.
 *
 * 비밀번호 만료 카드는 설정을 보기만 하는 관리자에게도 「지금 실행」을 비활성으로 보이고 그 아래 마지막 실행 줄을
 * 그린다(#736, 2026-10-04 결정). 개인정보 탭 대상은 이 표지를 달지 않아 종전대로 가린다.
 */
interface PrivacyManualRunReadableInterface
{
    /**
     * 실행 기록을 볼 수 있는 관리자 권한 식별자 목록을 반환합니다.
     *
     * @return array<int, string> 모두 가져야 하는 권한(전체 범위)
     */
    public function readPermissions(): array;
}
