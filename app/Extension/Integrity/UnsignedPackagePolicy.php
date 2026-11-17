<?php

namespace App\Extension\Integrity;

use App\Exceptions\PackageIntegrityException;
use Illuminate\Support\Facades\Log;

/**
 * 검증 결과로 설치·업데이트를 계속할지 정합니다.
 *
 * - `invalid`·`untrusted`: 늘 422 — 운영자 확인·`--force`·스위치·옛 화면 호환 어느 것으로도 통과하지 않는다.
 * - `unsigned`·`unverifiable`: 환경설정 「미서명 패키지 차단」(`security.extension_block_unsigned`)이 켜져 있으면 409 고정
 *   (계속 불가). 꺼져 있으면 운영자가 `allowUnsigned` 로 확인했을 때만 통과, 아니면 409(계속 가능).
 *   단, 무결성 표식(`integrity_aware`)이 없는 옛 관리자 화면(`legacyClient`)은 「미서명으로 계속」을 그릴 수 없으므로 7.1 동작대로
 *   통과시키고 경고 로그를 남긴다. 설치 행에는 `unsigned`·`unverifiable` 이 그대로 기록된다.
 * - `verified`·`bundled`: 통과.
 *
 * 옛 화면 호환 판정은 이 메서드 한 곳에만 둔다 — 요청 쪽은 표식 유무를 `PackageIntegrityOptions::legacyClient` 로 넘기기만 한다.
 *
 * @since 7.2.0
 */
class UnsignedPackagePolicy
{
    /** 「미서명 패키지 차단」 설정 키 */
    public const BLOCK_SETTING = 'security.extension_block_unsigned';

    /**
     * 검증 결과를 판정하고, 계속할 수 없으면 예외를 던집니다.
     *
     * @param  PackageVerification  $verification  검증 결과
     * @param  PackageIntegrityOptions|bool  $choice  운영자 선택 (bool 이면 미서명 계속 확인 여부만 — 옛 화면 아님)
     * @return PackageVerification 통과한 결과 (그대로)
     *
     * @throws PackageIntegrityException 차단·확인 필요 시
     */
    public function decide(PackageVerification $verification, PackageIntegrityOptions|bool $choice): PackageVerification
    {
        $options = $choice instanceof PackageIntegrityOptions ? $choice : new PackageIntegrityOptions(allowUnsigned: $choice);
        $status = $verification->status;

        if ($status === PackageVerificationStatus::Invalid) {
            throw new PackageIntegrityException($verification, PackageIntegrityException::CODE_INVALID);
        }

        if ($status === PackageVerificationStatus::Untrusted) {
            throw new PackageIntegrityException($verification, PackageIntegrityException::CODE_UNTRUSTED);
        }

        if ($status->followsUnsignedPolicy()) {
            if ($this->blocksUnsigned()) {
                throw new PackageIntegrityException($verification, PackageIntegrityException::CODE_UNSIGNED, canContinue: false, blockedByPolicy: true);
            }

            if ($options->allowUnsigned) {
                return $verification;
            }

            if ($options->legacyClient) {
                Log::warning('옛 화면 호환으로 미서명 패키지를 허용했습니다 — 무결성 표식(integrity_aware)이 없는 관리자 화면의 요청입니다. 7.2.0 관리자 템플릿으로 바꾸면 「미서명으로 계속」 확인을 거칩니다.', [
                    'status' => $status->value,
                    'reason' => $verification->reason,
                    'package' => $verification->package,
                ]);

                return $verification;
            }

            throw new PackageIntegrityException($verification, PackageIntegrityException::CODE_UNSIGNED, canContinue: true);
        }

        return $verification;
    }

    /**
     * 「미서명 패키지 차단」 스위치가 켜져 있는지 확인합니다.
     *
     * @return bool 켜져 있으면 true
     */
    public function blocksUnsigned(): bool
    {
        return (bool) g7_core_settings(self::BLOCK_SETTING, false);
    }
}
