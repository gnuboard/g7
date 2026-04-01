<?php

namespace App\ActivityLog;

use Illuminate\Database\Eloquent\Model;

/**
 * 모델 변경 감지 유틸리티.
 *
 * 모델의 $activityLogFields 메타데이터와 스냅샷을 비교하여
 * 구조화된 변경 이력(changes JSON)을 생성합니다.
 */
class ChangeDetector
{
    /**
     * 모델의 현재 상태와 스냅샷을 비교하여 변경 사항을 감지합니다.
     *
     * @param Model $model 현재 모델 상태
     * @param array|null $snapshot 변경 전 스냅샷 (toArray() 결과)
     * @return array|null 변경 사항 배열 또는 변경 없으면 null
     */
    public static function detect(Model $model, ?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        $fields = static::getActivityLogFields($model);
        if (empty($fields)) {
            return null;
        }

        $changes = [];

        foreach ($fields as $field => $meta) {
            $old = $snapshot[$field] ?? null;
            $new = $model->getAttribute($field);

            // Enum 객체를 값으로 변환
            if ($old instanceof \BackedEnum) {
                $old = $old->value;
            }
            if ($new instanceof \BackedEnum) {
                $new = $new->value;
            }

            if ($old !== $new) {
                $change = [
                    'field' => $field,
                    'label_key' => $meta['label_key'],
                    'old' => $old,
                    'new' => $new,
                    'type' => $meta['type'] ?? 'text',
                ];

                // enum 타입이면 라벨 키 추가
                if (($meta['type'] ?? 'text') === 'enum' && isset($meta['enum'])) {
                    $enumClass = $meta['enum'];
                    $change['old_label_key'] = $old !== null ? $enumClass::tryFrom($old)?->labelKey() : null;
                    $change['new_label_key'] = $new !== null ? $enumClass::tryFrom($new)?->labelKey() : null;
                }

                $changes[] = $change;
            }
        }

        return ! empty($changes) ? $changes : null;
    }

    /**
     * 모델의 $activityLogFields 메타데이터를 반환합니다.
     *
     * @param Model $model 대상 모델
     * @return array 필드 메타데이터 배열
     */
    private static function getActivityLogFields(Model $model): array
    {
        if (property_exists($model, 'activityLogFields')) {
            return $model::$activityLogFields;
        }

        return [];
    }
}
