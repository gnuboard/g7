<?php

namespace App\Extension\Helpers;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\AbstractUpgradeStep;

/**
 * 확장(모듈/플러그인) 업그레이드 스텝의 AbstractUpgradeStep 상속 의무 가드.
 *
 * 코어 7.0.0-beta.5 에서 도입된 "버전별 데이터 스냅샷 규약" (AbstractUpgradeStep 의무) 을
 * 확장 측에 동일 수준으로 적용한다.
 *
 * 의무 시작 버전 결정 규칙:
 *   - 확장의 manifest g7_version 제약이 7.0.0-beta.5 미만의 코어를 허용한다면 legacy
 *     (가드 미발동) — 그 확장은 본 인프라가 없던 코어를 인지하는 작성본이라 호환 보장.
 *   - 제약 최소 버전이 7.0.0-beta.5 이상이면 확장이 본 인프라를 인지함 → 그 확장의 현재
 *     working version 부터 step 작성 시 AbstractUpgradeStep 상속 의무.
 *
 * 즉 확장 작성자가 g7_version 을 7.0.0-beta.5 이상으로 상향하는 시점이 본 규약의 자동
 * 적용 첫 버전이 된다.
 *
 * 상세: docs/extension/upgrade-step-guide.md §13
 */
class ExtensionUpgradeGuardHelper
{
    /**
     * AbstractUpgradeStep 의무가 도입된 코어 버전.
     */
    public const ABSTRACT_INTRODUCED_AT = '7.0.0-beta.5';

    /**
     * 확장의 AbstractUpgradeStep 의무 시작 버전을 계산합니다.
     *
     * @param  string|null  $g7VersionConstraint  manifest 의 g7_version (예: ">=7.0.0-beta.5")
     * @param  string  $extensionWorkingVersion  manifest 의 version (예: "1.0.0-beta.5")
     * @return string|null  의무 시작 버전 또는 null (가드 미발동 — legacy)
     */
    public static function resolveSinceVersion(
        ?string $g7VersionConstraint,
        string $extensionWorkingVersion,
    ): ?string {
        if ($g7VersionConstraint === null || trim($g7VersionConstraint) === '') {
            return null;
        }

        $minVersion = self::extractMinimumVersion($g7VersionConstraint);
        if ($minVersion === null) {
            return null;
        }

        if (version_compare($minVersion, self::ABSTRACT_INTRODUCED_AT, '<')) {
            return null;
        }

        return $extensionWorkingVersion;
    }

    /**
     * 확장 step 이 본 가드를 통과하는지 검증합니다.
     *
     * stepVersion < sinceVersion 인 step (확장 버전 이전의 legacy step) 은 미발동.
     * stepVersion >= sinceVersion 인 step 이 AbstractUpgradeStep 미상속이면 fatal.
     *
     * @param  string  $stepVersion  실행 중인 step 의 버전
     * @param  string  $sinceVersion  의무 시작 버전 (resolveSinceVersion 결과; null 아님)
     * @param  UpgradeStepInterface  $step  step 인스턴스
     * @param  string  $extensionType  확장 타입 ("module" / "plugin")
     * @param  string  $extensionIdentifier  확장 식별자
     *
     * @throws \RuntimeException stepVersion >= sinceVersion 인데 AbstractUpgradeStep 미상속 시
     */
    public static function assertAbstractInheritance(
        string $stepVersion,
        string $sinceVersion,
        UpgradeStepInterface $step,
        string $extensionType,
        string $extensionIdentifier,
    ): void {
        if (version_compare($stepVersion, $sinceVersion, '<')) {
            return;
        }

        if ($step instanceof AbstractUpgradeStep) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Extension %s "%s" upgrade step %s must extend App\\Extension\\AbstractUpgradeStep '
            .'(required from %s; introduced in core %s).',
            $extensionType,
            $extensionIdentifier,
            $stepVersion,
            $sinceVersion,
            self::ABSTRACT_INTRODUCED_AT,
        ));
    }

    /**
     * Semantic Versioning 제약 문자열에서 최소 버전을 추출합니다.
     *
     * 지원 형식:
     *   - ">=X.Y.Z[-suffix]" / ">X.Y.Z" / "X.Y.Z"
     *   - "^X.Y.Z" / "~X.Y.Z"
     *   - "A|B" (OR) — 첫 후보 기준
     *
     * 파싱 실패 시 null 반환 (보수적으로 가드 미발동).
     */
    private static function extractMinimumVersion(string $constraint): ?string
    {
        $constraint = trim($constraint);

        $pipeIdx = strpos($constraint, '|');
        if ($pipeIdx !== false) {
            $constraint = trim(substr($constraint, 0, $pipeIdx));
        }

        if (preg_match(
            '/^(?:>=|>|\^|~|=)?\s*([0-9]+\.[0-9]+\.[0-9]+(?:[\-\.][a-zA-Z0-9\.\-]+)?)/',
            $constraint,
            $m,
        )) {
            return $m[1];
        }

        return null;
    }
}
