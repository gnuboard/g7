<?php

namespace App\Services;

use App\Contracts\Repositories\ActivityLogRepositoryInterface;
use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * 활동 로그 서비스
 *
 * 활동 로그의 조회 및 삭제 기능을 제공합니다.
 * 기록은 Log::channel('activity') → ActivityLogHandler를 통해 직접 수행합니다.
 */
class ActivityLogService
{
    /**
     * 활동 로그(접속기록) 법정 최소 보관 일수 — 개인정보의 안전성 확보조치 기준 제8조 접속기록 1년(#736 D14).
     */
    public const LEGAL_MIN_RETENTION_DAYS = 365;

    /**
     * 권한 부여·변경·말소 기록 법정 보관 일수 — 같은 기준 제5조③ 3년(#736 D14). 설정 키가 없을 때의 기본값.
     */
    public const LEGAL_PERMISSION_RECORD_DAYS = 1095;

    /**
     * ActivityLogService 생성자
     *
     * @param  ActivityLogRepositoryInterface  $repository  활동 로그 리포지토리
     */
    public function __construct(
        private ActivityLogRepositoryInterface $repository
    ) {}

    /**
     * 특정 모델의 활동 로그 목록을 조회합니다.
     *
     * @param  Model  $model  대상 모델
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator 페이지네이션된 로그 목록
     */
    public function getLogsForModel(Model $model, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->getPaginatedForModel($model, $filters);
    }

    /**
     * 활동 로그 목록을 조회합니다.
     *
     * 요청에 `cursor` 가 있으면 키셋(커서) 방식으로 응답합니다.
     *
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator|CursorPaginator 페이지네이션된 로그 목록
     */
    public function getList(array $filters = []): LengthAwarePaginator|CursorPaginator
    {
        return $this->repository->getPaginated($filters);
    }

    /**
     * 활동 로그를 삭제합니다.
     *
     * @param  int  $id  삭제할 활동 로그 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool
    {
        HookManager::doAction('core.activity_log.before_delete', $id);

        $result = $this->repository->delete($id);

        HookManager::doAction('core.activity_log.after_delete', $id);

        return $result;
    }

    /**
     * 여러 활동 로그를 일괄 삭제합니다.
     *
     * @param  array<int>  $ids  삭제할 활동 로그 ID 목록
     * @return int 삭제된 건수
     */
    public function deleteMany(array $ids): int
    {
        HookManager::doAction('core.activity_log.before_delete_many', $ids);

        $count = $this->repository->deleteMany($ids);

        HookManager::doAction('core.activity_log.after_delete_many', $ids, $count);

        return $count;
    }

    /**
     * 환경설정 > 보안 「활동 로그 보관 기간」을 읽습니다 (설정 한계로 클램프).
     *
     * 단일 키 저장 경로는 범위를 검사하지 않으므로 판독부가 최종 방어선이다(비밀번호 정책 키와 같은 방식).
     *
     * @return int 보관 일수
     */
    public function configuredRetentionDays(): int
    {
        $min = (int) config('core.settings_limits.security_activity_log_retention_days_min', self::LEGAL_MIN_RETENTION_DAYS);
        $max = (int) config('core.settings_limits.security_activity_log_retention_days_max', 3650);
        $value = (int) g7_core_settings('security.activity_log_retention_days', self::LEGAL_MIN_RETENTION_DAYS);

        return max($min, min($max, $value));
    }

    /**
     * 이번 파기에 실제로 적용할 보관 일수를 정합니다.
     *
     * 명시값이 없으면 환경설정 값을 쓰고, 어느 쪽이든 법정 최소(365일) 아래로는 내려가지 않는다 —
     * 파기는 되돌릴 수 없으므로 커맨드 옵션(`--days`)으로도 법정 기간 안의 기록을 지울 수 없다.
     *
     * @param  int|null  $days  명시 보관 일수 (null 이면 환경설정)
     * @return int 적용 보관 일수
     */
    public function effectiveRetentionDays(?int $days = null): int
    {
        return max(self::LEGAL_MIN_RETENTION_DAYS, $days ?? $this->configuredRetentionDays());
    }

    /**
     * 보존 기간이 지난 활동 로그를 정리합니다 (자동 파기).
     *
     * 운영자가 고른 ID 를 지우는 deleteMany 와는 별개의 훅을 발행한다. 자동 파기는
     * 대상을 ID 로 지목하지 않고(기간으로 정한다), 사람이 없는 예약 실행이라
     * 본인인증 같은 대화형 가드를 태울 수 없기 때문이다. 확장은 이 훅으로
     * 외부 보관 등 자기 처리를 붙인다.
     *
     * 두 단계로 지운다 — 일반 로그는 적용 보관 일수, 역할·권한 변경 기록
     * (`core.activity_log.long_retention_actions`)은 그 값과 3년(`long_retention_days`) 중 긴 쪽이 지난 것만.
     * 개인정보의 안전성 확보조치 기준 제5조③(권한 부여·변경·말소 내역 3년 보관, #736 D14). 훅에는 적용 보관 일수를 싣는다.
     *
     * @param  int|null  $days  보존 기간 (일) — null 이면 환경설정 「활동 로그 보관 기간」, 어느 쪽이든 최소 365일
     * @return int 삭제된 건수
     */
    public function prune(?int $days = null): int
    {
        $days = $this->effectiveRetentionDays($days);
        $longActions = array_values(array_filter(
            (array) config('core.activity_log.long_retention_actions', []),
            'is_string',
        ));
        // audit:allow service-config-value-clamp 상한을 깎는 클램프가 아니다 — 두 법정 보관 기간 중 긴 쪽을 고른다(권한 기록을 일반 기록보다 먼저 지우지 않는다)
        $longDays = max($days, (int) config('core.activity_log.long_retention_days', self::LEGAL_PERMISSION_RECORD_DAYS));

        HookManager::doAction('core.activity_log.before_prune', $days);

        $count = $this->repository->deleteOlderThan($days, $longActions)
            + $this->repository->deleteOlderThanForActions($longDays, $longActions);

        HookManager::doAction('core.activity_log.after_prune', $days, $count);

        return $count;
    }
}
