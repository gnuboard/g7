<?php

namespace App\Extension\Helpers;

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
    /**
     * 엔티티를 동기화합니다 (user_overrides 보존 upsert).
     *
     * 내부적으로 `HasUserOverrides::syncOrCreateFromUpgrade` 를 위임 호출합니다.
     *
     * @template T of Model
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
     * @param  class-string<Model>  $modelClass  대상 모델 클래스
     * @param  array<string, mixed>  $scopeFilter  삭제 대상 한정 조건 (예: ['extension_identifier' => 'x', 'type' => 'refund']). 빈 배열이면 전체 테이블 대상.
     * @param  string  $keyField  비교 기준 컬럼명 (예: 'code', 'slug', 'type')
     * @param  array<int, string|int>  $currentKeys  현재 유효한 키 목록
     * @return int 삭제된 행 수
     */
    public function cleanupStale(
        string $modelClass,
        array $scopeFilter,
        string $keyField,
        array $currentKeys,
    ): int {
        $query = $modelClass::query();
        foreach ($scopeFilter as $column => $value) {
            $query->where($column, $value);
        }
        $query->whereNotIn($keyField, $currentKeys);

        $targets = $query->get(['id', $keyField]);
        foreach ($targets as $row) {
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
}
