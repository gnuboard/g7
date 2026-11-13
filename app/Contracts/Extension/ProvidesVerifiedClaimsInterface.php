<?php

namespace App\Contracts\Extension;

use App\Extension\IdentityVerification\DTO\VerifiedClaims;
use App\Models\IdentityVerificationLog;
use App\Models\User;

/**
 * 본인인증 수단이 확인한 인적 사항(이름, 휴대폰, 생년월일, 성별)을 코어에 내주는 선택 계약.
 *
 * 본인인증 프로바이더({@see IdentityVerificationInterface})가 이 계약을 함께 구현하면, 코어가 확인을 마친 요청 행에서
 * 인증 값을 받아 회원 항목(항목 정의의 「본인인증 연결」)을 채우고 잠근다. 구현하지 않은 수단(메일 인증번호 등)은
 * 인증 값이 없는 것으로 다뤄져 채움과 잠금이 일어나지 않는다.
 *
 * 코어는 받은 값을 요청 행이나 로그에 다시 저장하지 않는다 — 회원 항목 값으로만 쓴다.
 *
 * @since 7.2.0
 */
interface ProvidesVerifiedClaimsInterface
{
    /**
     * 이 수단이 줄 수 있는 인증 값의 키 목록을 반환합니다.
     *
     * | 키 | 뜻 |
     * | --- | --- |
     * | name | 이름 |
     * | mobile | 휴대폰 번호 |
     * | birth_date | 생년월일 |
     * | gender | 성별 |
     *
     * 여기 없는 키에 연결된 회원 항목은 이 수단으로 인증한 회원에게 잠기지 않는다.
     *
     * @return array<int, string> 인증 값 키 목록
     */
    public function supportedClaims(): array;

    /**
     * 확인을 마친 요청 행의 인증 값을 반환합니다.
     *
     * @param  IdentityVerificationLog  $log  확인을 마친(verified) 본인인증 요청 행
     * @param  User|null  $user  값을 받을 회원 (가입 전이면 null)
     * @return VerifiedClaims|null 인증 값 (이 요청 행의 값을 더는 알 수 없으면 null)
     */
    public function verifiedClaims(IdentityVerificationLog $log, ?User $user): ?VerifiedClaims;
}
