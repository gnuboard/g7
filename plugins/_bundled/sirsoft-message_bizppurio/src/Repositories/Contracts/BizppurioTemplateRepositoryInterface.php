<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;

/**
 * 비즈뿌리오 알림 템플릿 Repository 인터페이스 (#597).
 */
interface BizppurioTemplateRepositoryInterface
{
    /**
     * PK 로 템플릿을 조회합니다.
     *
     * @param  int  $id  템플릿 PK
     * @return BizppurioTemplate|null 매칭 행 또는 null
     */
    public function find(int $id): ?BizppurioTemplate;

    /**
     * 알림 유형으로 템플릿을 조회합니다 (발송 시 행 해석).
     *
     * @param  string  $notificationType  코어 notification_definitions.type
     * @return BizppurioTemplate|null 매칭 행 또는 null
     */
    public function findByType(string $notificationType): ?BizppurioTemplate;

    /**
     * 관리 화면 목록을 페이지네이션 조회합니다 (알림 정의 라벨·소속 조인).
     *
     * @param  array<string, mixed>  $filters  status / search
     * @param  int  $page  페이지 번호
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function paginateWithDefinitions(array $filters, int $page, int $perPage): LengthAwarePaginator;

    /**
     * 알림 설정 탭 행 표시용 전체 요약을 조회합니다 (notification_type 키 맵 소스).
     *
     * 행 수는 운영자가 등록한 알림 정의 수에 묶인 설정성 테이블이므로 상한 없이 전량
     * 조회한다(페이지네이션 규정의 설정성 테이블 예외). content 등 대형 JSON 컬럼은
     * 요약에 필요 없으므로 제외한다(컬럼 프루닝).
     *
     * @return Collection<int, BizppurioTemplate> 요약 컬럼만 실린 행 컬렉션
     */
    public function allSummaries(): Collection;

    /**
     * 특정 상태의 행 전체를 조회합니다 (동기화 커맨드 대상 선별).
     *
     * 대상 행 수는 알림 정의 수에 묶인다(설정성 테이블 예외 — 상한 불요).
     *
     * @param  string  $status  BizppurioTemplateStatus value
     * @return Collection<int, BizppurioTemplate>
     */
    public function allByStatus(string $status): Collection;

    /**
     * 템플릿 행을 생성합니다.
     *
     * @param  array<string, mixed>  $data  생성 데이터
     * @return BizppurioTemplate 생성된 행
     */
    public function create(array $data): BizppurioTemplate;

    /**
     * 템플릿 행을 갱신합니다.
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @param  array<string, mixed>  $data  갱신 데이터
     * @return BizppurioTemplate 갱신된 행
     */
    public function update(BizppurioTemplate $template, array $data): BizppurioTemplate;

    /**
     * 템플릿 행을 삭제합니다.
     *
     * @param  BizppurioTemplate  $template  대상 행
     */
    public function delete(BizppurioTemplate $template): void;

    /**
     * 특정 템플릿 코드가 이미 사용 중인지 확인합니다 (자체 채번 충돌 방지).
     *
     * @param  string  $templateCode  검사할 코드
     * @return bool 사용 중이면 true
     */
    public function templateCodeExists(string $templateCode): bool;

    /**
     * 검수 신청을 위해 행을 원자적으로 선점합니다.
     *
     * `status IN (draft, rejected)` 인 행만 조건부 UPDATE 로 requested 로 전이한다.
     * 더블 클릭·이중 탭·복수 관리자처럼 같은 draft 상태를 동시에 든 요청 중
     * 정확히 하나만 true 를 받는다 — 경합의 패자는 kapi 에 도달하기 전에 걸러진다.
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @return bool 선점 성공(이 요청이 신청을 진행) 여부
     */
    public function claimForInspection(BizppurioTemplate $template): bool;
}
