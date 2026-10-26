<?php

namespace App\Extension\Support;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\Exceptions\UpgradeStepClassMissingException;
use App\Extension\UpgradeContext;

/**
 * 파일은 있는데 클래스를 쓸 수 없는 확장 업그레이드 단계의 자리 표시 (7.1.3).
 *
 * 단계 수집은 버전 범위를 모르므로, 깨진 파일을 만나도 그 자리에서 던지지 않고 이 표시를 둔다. 업데이트가
 * 이번 범위(시작 판 초과 ~ 목표 판 이하)로 고른 단계에 이 표시가 있을 때만 업데이트를 실패로 끝낸다 —
 * 코어 업그레이드 단계와 같은 규칙. 이미 지난 판이나 아직 아닌 판의 깨진 파일 하나가 그 확장의 모든
 * 업데이트를 막지 않게 한다.
 */
final class UnusableUpgradeStep implements UpgradeStepInterface
{
    /**
     * @param  UpgradeStepClassMissingException  $reason  수집 때 확인한 사용 불가 사유
     */
    public function __construct(public readonly UpgradeStepClassMissingException $reason) {}

    /**
     * 범위 안에서 실행되면 수집 때의 사유로 실패합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     *
     * @throws UpgradeStepClassMissingException 늘
     */
    public function run(UpgradeContext $context): void
    {
        throw $this->reason;
    }

    /**
     * 고른 단계 중 쓸 수 없는 단계가 있으면 아무 단계도 돌리기 전에 그 사유로 실패합니다.
     *
     * @param  array<string, UpgradeStepInterface>  $steps  이번 업데이트가 고른 단계(버전 => 단계)
     * @return void 반환값 없음
     *
     * @throws UpgradeStepClassMissingException 쓸 수 없는 단계가 있을 때
     */
    public static function assertNoneIn(array $steps): void
    {
        foreach ($steps as $step) {
            if ($step instanceof self) {
                throw $step->reason;
            }
        }
    }
}
