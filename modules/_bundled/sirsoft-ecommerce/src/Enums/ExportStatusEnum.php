<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 내보내기 작업 진행 상태
 *
 * 대기 → 진행중 → 완료 → (보관 기간 경과) 만료, 또는 진행 중 실패.
 * 대기는 큐에 실렸지만 워커가 아직 집지 않은 상태다.
 */
enum ExportStatusEnum: string
{
    case PENDING = 'pending';       // 대기
    case PROCESSING = 'processing'; // 진행중
    case COMPLETED = 'completed';   // 완료
    case FAILED = 'failed';         // 실패
    case EXPIRED = 'expired';       // 만료 (보관 기간 경과로 파일 삭제됨)

    /**
     * 화면 표시 라벨을 반환합니다.
     *
     * @return string 현재 로케일 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.export_status.'.$this->value);
    }

    /**
     * 모든 값 목록을 반환합니다.
     *
     * @return array<int, string> 상태 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 아직 끝나지 않은(대기·진행중) 상태 값 목록을 반환합니다 — 요청자당 동시 요청 상한을 셀 때 쓴다.
     *
     * @return array<int, string> 활성 상태 값
     */
    public static function activeValues(): array
    {
        return [self::PENDING->value, self::PROCESSING->value];
    }

    /**
     * 아직 끝나지 않은(대기·진행중) 상태인지 반환합니다 — 관리 화면 자동 갱신 판정.
     *
     * @return bool 활성 여부
     */
    public function isActive(): bool
    {
        return in_array($this->value, self::activeValues(), true);
    }

    /**
     * 이 상태의 작업을 삭제할 수 있는지 반환합니다.
     *
     * 진행중인 작업은 잡이 임시 파일을 쓰고 있어 지우면 경합한다 — 대기는 지워도 잡이 원자 전이에서 0행이
     * 되어 아무것도 하지 않는다.
     *
     * @return bool 삭제 가능 여부
     */
    public function isDeletable(): bool
    {
        return $this !== self::PROCESSING;
    }
}
