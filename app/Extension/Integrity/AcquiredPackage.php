<?php

namespace App\Extension\Integrity;

/**
 * 내려받고 검증을 통과한 패키지 ZIP.
 *
 * 해제는 호출부가 기존 전략(`ExtensionManager::extractFromZip`·`ZipInstallHelper`)으로 한다 — 서명 자산 ZIP 은
 * 루트에 `{identifier}-{version}/` 디렉터리 하나라 zipball 과 같은 구조다.
 *
 * @since 7.2.0
 */
final class AcquiredPackage
{
    /**
     * 받은 패키지를 만듭니다.
     *
     * @param  string  $zipPath  내려받은 ZIP 경로
     * @param  PackageVerification  $verification  해제 전 검증 결과 (정책 통과)
     * @param  string|null  $releaseVersion  릴리스 태그 버전 (GitHub 이 아니면 null)
     * @param  bool  $fromSignedAsset  서명 자산(릴리스 첨부 ZIP)에서 받았는지 — false 면 태그 zipball
     */
    public function __construct(
        public readonly string $zipPath,
        public readonly PackageVerification $verification,
        public readonly ?string $releaseVersion = null,
        public readonly bool $fromSignedAsset = false,
    ) {}
}
