<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;

/**
 * 비즈뿌리오 발송 이력 Repository 계약.
 *
 * 발송 시 pending 이력을 생성하고, webhook 리포트로 refkey 매칭 후 상태를 갱신한다.
 * 발송 이력 화면(계획서 §6-5)의 목록 조회를 담당한다.
 */
interface BizppurioDispatchRepositoryInterface
{
    /**
     * 발송 이력 1건을 생성합니다 (발송 시점, 통상 pending).
     *
     * @param  array<string, mixed>  $data  이력 데이터
     * @return BizppurioDispatch 생성된 이력
     */
    public function create(array $data): BizppurioDispatch;

    /**
     * refkey 로 발송 이력을 조회합니다 (webhook 매칭).
     *
     * @param  string  $refkey  우리 부여 키
     * @return BizppurioDispatch|null 매칭된 이력 또는 null(위조)
     */
    public function findByRefkey(string $refkey): ?BizppurioDispatch;

    /**
     * 발송 이력의 속성을 갱신합니다.
     *
     * @param  BizppurioDispatch  $dispatch  대상 이력
     * @param  array<string, mixed>  $data  갱신 데이터
     * @return BizppurioDispatch 갱신된 이력
     */
    public function update(BizppurioDispatch $dispatch, array $data): BizppurioDispatch;

    /**
     * 필터·검색 조건으로 발송 이력을 페이지네이션 조회합니다 (이력 화면).
     *
     * @param  array<string, mixed>  $filters  channel / status / date_from / date_to / keyword
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator<BizppurioDispatch>
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * refkey 로 dispatch 를 찾아 코어 알림 로그 id 를 연결합니다 (A-2 연결고리).
     *
     * LinkNotificationLogListener 가 발송 사이클 직후 코어 로그 id 를 이 dispatch 에 기록할 때
     * 호출한다. refkey 가 없거나(비-비즈뿌리오 로그) 이미 연결됐으면 아무 것도 하지 않는다.
     *
     * @param  string  $refkey  발송 사이클에서 부여한 refkey
     * @param  int  $notificationLogId  코어 notification_logs.id
     * @return bool 연결 성공 여부(대상 없으면 false)
     */
    public function linkNotificationLog(string $refkey, int $notificationLogId): bool;

    /**
     * 코어 알림 로그 id 목록으로 dispatch 를 일괄 조회해 log id 키 맵으로 반환합니다 (A-2 표시).
     *
     * 코어 알림 발송 이력 화면이 현재 페이지의 log id 배열로 비즈뿌리오 결과를 한 번에 조회한다
     * (N+1 회피). 매칭되지 않는 log id(메일·DB 등 비-비즈뿌리오)는 맵에서 빠진다.
     *
     * @param  array<int, int>  $notificationLogIds  코어 로그 id 목록
     * @return Collection<int, BizppurioDispatch> notification_log_id 를 키로 하는 dispatch 맵
     */
    public function findByNotificationLogIdsKeyed(array $notificationLogIds): Collection;

    /**
     * 코어 로그에 연결된 최근 dispatch 를 log id 키 맵으로 반환합니다 (A-2 표시, 타이밍 무관).
     *
     * 화면이 파라미터 없이 GET 으로 최근 결과 맵을 받아 row.id 로 매칭한다(kginicis test-map 선례).
     * params 로 로그 id 를 넘기지 않으므로 목록 data_source 로드 순서에 의존하지 않는다. 이력 화면은
     * 페이지네이션(20건)이라 최근 N건 맵으로 현재 화면을 덮는다. notification_log_id 가 없는
     * dispatch(아직 연결 전)는 제외한다.
     *
     * @param  int  $limit  최근 dispatch 조회 상한
     * @return Collection<int, BizppurioDispatch> notification_log_id 를 키로 하는 dispatch 맵
     */
    public function recentLinkedKeyed(int $limit = 1000): Collection;
}
