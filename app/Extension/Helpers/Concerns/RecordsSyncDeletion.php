<?php

namespace App\Extension\Helpers\Concerns;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Enums\ActivityLogType;
use Illuminate\Database\Eloquent\Model;

/**
 * 선언형 동기화 헬퍼가 「새 판 선언에서 빠진 항목」을 지우기 직전 운영자 데이터를 활동 로그에 남기는 공통 부분.
 *
 * `user_overrides` 는 항목이 **남아 있을 때** 운영자가 고친 필드를 지키는 장치다. 항목이 파일(선언)에서 **빠지면** 헬퍼가
 * 그 항목을 지우는데, 그때 운영자가 고친 내용이 기록 없이 사라졌다. 그래서 지우기 직전에 운영자 데이터가 있으면
 * `{종류}.sync_deleted` 로 스냅샷을 남긴다. 스냅샷은 활동 로그 보관 기간이 지나면 정리되고 복원 기능은 없다.
 */
trait RecordsSyncDeletion
{
    use ResolvesActivityLogType;

    /**
     * 행에 운영자가 고친 필드(`user_overrides`)가 있는지 판정합니다.
     *
     * @param  Model  $row  행
     * @return bool 있으면 true
     */
    protected function hasOperatorOverrides(Model $row): bool
    {
        $overrides = $row->getAttribute('user_overrides');

        if (is_string($overrides)) {
            $overrides = json_decode($overrides, true);
        }

        return is_array($overrides) && $overrides !== [];
    }

    /**
     * 선언에서 빠진 항목을 지우기 직전의 스냅샷을 남깁니다.
     *
     * @param  string  $kind  종류 (`notification_definition`·`notification_template`·`menu`·`permission`·`role`·`identity_policy`·`schedule`·`entity`)
     * @param  string  $key  항목 키 (식별자·슬러그·이름)
     * @param  string  $extensionType  소유 확장 종류
     * @param  string  $extensionIdentifier  소유 확장 식별자
     * @param  array<string, mixed>  $snapshot  지우기 직전 데이터 (행·자식·배정)
     * @param  array<string, string|array<string, string>>  $params  설명 치환값 추가분 (언어별 묶음이면 로그를 볼 때의 언어로 고른다)
     * @return void 반환값 없음
     */
    protected function recordSyncDeletion(string $kind, string $key, string $extensionType, string $extensionIdentifier, array $snapshot, array $params = []): void
    {
        $this->logActivity($kind.'.sync_deleted', [
            'log_type' => ActivityLogType::System,
            'description_key' => 'activity_log.description.'.$kind.'_sync_deleted',
            'description_params' => ['key' => $key, 'extension' => $extensionIdentifier] + $params,
            'properties' => [
                'kind' => $kind,
                'key' => $key,
                'extension_type' => $extensionType,
                'extension_identifier' => $extensionIdentifier,
                'reason' => 'declaration_removed',
                'has_operator_overrides' => true,
                'snapshot' => $snapshot,
            ],
        ]);
    }
}
