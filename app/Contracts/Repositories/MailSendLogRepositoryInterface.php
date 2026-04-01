<?php

namespace App\Contracts\Repositories;

use App\Models\MailSendLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 메일 발송 이력 리포지토리 인터페이스
 */
interface MailSendLogRepositoryInterface
{
    /**
     * 발송 이력을 생성합니다.
     *
     * @param array $data 생성 데이터
     * @return MailSendLog 생성된 발송 이력 모델
     */
    public function create(array $data): MailSendLog;

    /**
     * 발송 이력 목록을 페이지네이션하여 조회합니다.
     *
     * @param array $filters 필터 조건 (module, template_type, status, search, date_from, date_to)
     * @param int $perPage 페이지 당 항목 수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 발송 통계를 조회합니다.
     *
     * @return array{total: int, sent: int, failed: int, today: int} 통계 정보
     */
    public function getStatistics(): array;

    /**
     * 발송 이력을 삭제합니다.
     *
     * @param int $id 삭제할 발송 이력 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool;

    /**
     * 여러 발송 이력을 일괄 삭제합니다.
     *
     * @param array<int> $ids 삭제할 발송 이력 ID 목록
     * @return int 삭제된 건수
     */
    public function deleteMany(array $ids): int;
}
