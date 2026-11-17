<?php

namespace App\Extension\Helpers;

use App\Extension\Helpers\Concerns\RecordsSyncDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * 범용 엔티티 동기화 헬퍼.
 *
 * 단일 스코프·단일 키 구조의 모듈 내부 도메인 엔티티를 대상으로
 * upsert + stale cleanup 패턴을 제공합니다.
 *
 * 전용 helper (ExtensionMenuSyncHelper, ExtensionRoleSyncHelper,
 * NotificationSyncHelper) 와의 역할 구분:
 *  - 전용 helper: 다중 모델 관계·피벗 참조 차단 등 도메인 로직 포함
 *  - 본 helper: 단일 테이블 · 단일 unique 키 · 선택적 scope 필터 범용 처리
 *
 * 사용 조건: 대상 모델은 `HasUserOverrides` trait 적용 필수.
 */
class GenericEntitySyncHelper
{
    use RecordsSyncDeletion;

    /**
     * 엔티티를 동기화합니다 (user_overrides 보존 upsert).
     *
     * 내부적으로 `HasUserOverrides::syncOrCreateFromUpgrade` 를 위임 호출합니다.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $modelClass  HasUserOverrides 적용 모델 클래스
     * @param  array<string, mixed>  $finder  updateOrCreate 의 unique 키 조건
     * @param  array<string, mixed>  $attributes  갱신할 속성
     * @return T 동기화된 모델 인스턴스
     */
    public function sync(string $modelClass, array $finder, array $attributes): Model
    {
        return $modelClass::syncOrCreateFromUpgrade($finder, $attributes);
    }

    /**
     * 주어진 scope 내에서 currentKeys 에 없는 row 를 삭제합니다 (완전 동기화).
     *
     * 정책: `user_overrides` 무관 — seeder/config 에 없는 row 는 삭제.
     *
     * **운영자가 직접 행을 만드는 표라면 운영자 행을 가를 범위 조건 없이 부르지 말 것** — 예: `['created_by' => null]`
     * (시더가 만든 행만). 조건 없이 부르면 언어팩을 켜고 끌 때(시더 재실행)마다 운영자가 만든 행이 흔적 없이 지워진다
     * (#727 — 게시판 유형·클레임 사유·배송유형·택배사). 가를 표식이 없는 표는 호출하지 않는다.
     *
     * @param  class-string<Model>  $modelClass  대상 모델 클래스
     * @param  array<string, mixed>  $scopeFilter  삭제 대상 한정 조건 (예: ['extension_identifier' => 'x', 'type' => 'refund']). 빈 배열이면 전체 테이블 대상.
     * @param  string  $keyField  비교 기준 컬럼명 (예: 'code', 'slug', 'type')
     * @param  array<int, string|int>  $currentKeys  현재 유효한 키 목록
     * @param  string|array<string, string>|null  $entityLabel  활동 로그에 적을 엔티티 이름 (운영자가 고친 행을 지울 때). 언어별 묶음
     *                                                          (`LocalizedValue::translateAll()`)을 넘기면 로그를 볼 때의 언어로 나온다. 비우면 모델 이름
     * @param  string|null  $ownerIdentifier  활동 로그에 적을 소유 확장 식별자 (범위 조건에 없을 때)
     * @return int 삭제된 행 수
     */
    public function cleanupStale(
        string $modelClass,
        array $scopeFilter,
        string $keyField,
        array $currentKeys,
        string|array|null $entityLabel = null,
        ?string $ownerIdentifier = null,
    ): int {
        // 검수 서버(stage)는 공유 DB 의 선언 항목을 지우지 않고 대장에 미룬다 — 운영 서버가 extension:apply-deferred-cleanup 으로 같은 규칙대로 지운다
        if (DeferredDeclarativeCleanup::shouldDefer()) {
            return DeferredDeclarativeCleanup::defer($this, __FUNCTION__, func_get_args());
        }

        $query = $modelClass::query();
        foreach ($scopeFilter as $column => $value) {
            $query->where($column, $value);
        }
        $query->whereNotIn($keyField, $currentKeys);

        // 운영자가 고친 행을 기록하려면 user_overrides 까지 읽어야 한다 — 그 컬럼이 없는 모델은 전체 행을 읽는다.
        $targets = $query->get();
        $entity = $entityLabel ?? class_basename($modelClass);
        foreach ($targets as $row) {
            if ($this->hasOperatorOverrides($row)) {
                $scope = array_map('strval', $scopeFilter);
                $this->recordSyncDeletion('entity', (string) $row->getAttribute($keyField), (string) ($scope['extension_type'] ?? ''), (string) ($ownerIdentifier ?? $scope['extension_identifier'] ?? ''), [
                    'model' => $modelClass,
                    'row' => $row->toArray(),
                ], ['entity' => $entity]);
            }

            $row->delete();
        }

        $count = $targets->count();
        if ($count > 0) {
            Log::info('stale 엔티티 정리 완료', [
                'model' => $modelClass,
                'scope' => $scopeFilter,
                'key_field' => $keyField,
                'deleted' => $count,
                'keys' => $targets->pluck($keyField)->all(),
            ]);
        }

        return $count;
    }

    /**
     * 지워질 stale 엔티티를 미리 계산합니다 — 쓰기 없음, 실제 정리와 같은 조회식.
     *
     * @param  class-string<Model>  $modelClass  모델 클래스
     * @param  array<string, mixed>  $scopeFilter  범위 조건
     * @param  string  $keyField  키 컬럼
     * @param  array<int, string>  $currentKeys  새 판이 선언한 키
     * @return list<array{type: string, key: string, has_operator_overrides: bool}> 삭제 예정
     */
    public function planCleanup(string $modelClass, array $scopeFilter, string $keyField, array $currentKeys): array
    {
        $query = $modelClass::query();
        foreach ($scopeFilter as $column => $value) {
            $query->where($column, $value);
        }

        return $query->whereNotIn($keyField, $currentKeys)->get()
            ->map(fn (Model $row) => ['type' => 'entity', 'key' => (string) $row->getAttribute($keyField), 'has_operator_overrides' => $this->hasOperatorOverrides($row)])
            ->values()
            ->all();
    }
}
