<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Enums;

/**
 * 비즈뿌리오 알림톡 템플릿 라이프사이클 상태 (#597 §3.4).
 *
 * G7 이 관리하는 알림톡 템플릿의 상태다. 카카오 관리 API(kapi)의 serviceStatus 어휘를
 * 우리 상태로 환원하는 매핑을 단일 출처로 보유한다(동기화 커맨드·수동 sync 가 공유).
 *
 * kapi serviceStatus → 우리 상태:
 *  - REG(등록: 검수 전·검수취소·승인취소 복귀 포함) → Draft
 *  - REQ(검수중)                                   → Requested
 *  - REJ(반려)                                     → Rejected
 *  - RDY(발송전)·ACT(정상)                          → Approved (발송 가능)
 *  - STP(중지)                                     → Stopped
 *  - BLK(차단)                                     → Blocked
 *  - DMT(휴면)                                     → Dormant
 */
enum BizppurioTemplateStatus: string
{
    case Draft = 'draft';
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Stopped = 'stopped';
    case Blocked = 'blocked';
    case Dormant = 'dormant';

    /**
     * kapi serviceStatus 코드를 우리 상태로 환원합니다.
     *
     * 알 수 없는 코드는 null 을 반환한다 — 동기화 호출측이 상태를 덮어쓰지 않고
     * 건너뛰게 하여, 카카오 어휘 확장 시 기존 상태가 오염되지 않게 한다.
     *
     * @param  string  $serviceStatus  kapi serviceStatus (REG/REQ/REJ/RDY/ACT/STP/BLK/DMT)
     * @return self|null 매핑된 상태, 알 수 없는 코드면 null
     */
    public static function tryFromServiceStatus(string $serviceStatus): ?self
    {
        return match ($serviceStatus) {
            'REG' => self::Draft,
            'REQ' => self::Requested,
            'REJ' => self::Rejected,
            'RDY', 'ACT' => self::Approved,
            'STP' => self::Stopped,
            'BLK' => self::Blocked,
            'DMT' => self::Dormant,
            default => null,
        };
    }

    /**
     * kapi 상세 응답(status/inspectionStatus/block/dormant)에서 serviceStatus 를 유도합니다.
     *
     * 상세 조회(template/detail)는 serviceStatus 대신 status(S/A/R)+inspectionStatus
     * (REG/REQ/REJ/APR)+block/dormant 를 내려주므로 목록과 동일한 어휘로 환원한다.
     * serviceStatus 필드가 이미 있으면 그대로 사용한다.
     *
     * @param  array<string, mixed>  $detail  kapi 템플릿 상세(또는 목록) 행
     * @return string serviceStatus 코드
     */
    public static function serviceStatusFromDetail(array $detail): string
    {
        if (! empty($detail['serviceStatus'])) {
            return (string) $detail['serviceStatus'];
        }

        $inspection = (string) ($detail['inspectionStatus'] ?? '');
        $status = (string) ($detail['status'] ?? '');
        $block = (bool) ($detail['block'] ?? false);
        $dormant = (bool) ($detail['dormant'] ?? false);

        return match (true) {
            $block => 'BLK',
            $dormant => 'DMT',
            $inspection === 'REQ' => 'REQ',
            $inspection === 'REJ' => 'REJ',
            $inspection === 'APR' && $status === 'S' => 'STP',
            $inspection === 'APR' && $status === 'A' => 'ACT',
            $inspection === 'APR' => 'RDY',
            default => 'REG',
        };
    }

    /**
     * 발송 가능 상태(승인) 여부를 반환합니다.
     *
     * @return bool 승인 상태면 true
     */
    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /**
     * 카카오 등록 내용(content) 수정·삭제가 가능한 상태인지 반환합니다.
     *
     * kapi 는 status R + inspectionStatus REG/REJ 에서만 update/delete 를 허용한다(부록 A-4).
     * 우리 상태로는 Draft(등록·검수취소·승인취소 복귀)와 Rejected(반려)가 이에 해당한다.
     *
     * @return bool 수정 가능 상태면 true
     */
    public function allowsContentEdit(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}
