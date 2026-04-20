<?php

namespace App\Extension;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 훅 리스너 인자를 큐 직렬화에 안전한 형태로 변환합니다.
 *
 * Eloquent Model은 클래스명 + PK로 변환하고,
 * 큐 워커에서 실행 시 다시 DB에서 조회하여 복원합니다.
 */
class HookArgumentSerializer
{
    /**
     * Model 직렬화 마커 키
     */
    private const MODEL_MARKER = '__hook_model__';

    /**
     * Collection 직렬화 마커 키
     */
    private const COLLECTION_MARKER = '__hook_collection__';

    /**
     * 인자 배열을 직렬화 안전한 형태로 변환합니다.
     *
     * @param  array  $args  원본 인자 배열
     * @return array 직렬화된 인자 배열
     */
    public static function serialize(array $args): array
    {
        return array_map([self::class, 'serializeValue'], $args);
    }

    /**
     * 직렬화된 인자 배열을 원본으로 복원합니다.
     *
     * @param  array  $args  직렬화된 인자 배열
     * @return array 복원된 인자 배열
     */
    public static function deserialize(array $args): array
    {
        return array_map([self::class, 'deserializeValue'], $args);
    }

    /**
     * 단일 값을 직렬화합니다.
     *
     * @param  mixed  $value  원본 값
     * @return mixed 직렬화된 값
     */
    private static function serializeValue(mixed $value): mixed
    {
        // Eloquent Model → 클래스명 + PK
        if ($value instanceof Model) {
            return [
                self::MODEL_MARKER => true,
                'class' => get_class($value),
                'id' => $value->getKey(),
            ];
        }

        // Collection → 배열로 변환 (내부 요소도 재귀 직렬화)
        if ($value instanceof Collection) {
            return [
                self::COLLECTION_MARKER => true,
                'items' => self::serialize($value->all()),
            ];
        }

        // 배열 → 재귀 직렬화
        if (is_array($value)) {
            return array_map([self::class, 'serializeValue'], $value);
        }

        // 스칼라 값 (string, int, float, bool, null)
        if (is_scalar($value) || is_null($value)) {
            return $value;
        }

        // Closure 등 직렬화 불가능한 객체 → null로 대체 + 경고
        Log::warning('훅 인자 직렬화 불가: 해당 인자를 null로 대체합니다.', [
            'type' => get_debug_type($value),
        ]);

        return null;
    }

    /**
     * 단일 값을 역직렬화합니다.
     *
     * @param  mixed  $value  직렬화된 값
     * @return mixed 복원된 값
     */
    private static function deserializeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        // Model 복원
        if (! empty($value[self::MODEL_MARKER])) {
            $class = $value['class'];
            $id = $value['id'];

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                return $class::find($id);
            }

            Log::warning('훅 인자 역직렬화 실패: 모델 클래스를 찾을 수 없습니다.', [
                'class' => $class,
                'id' => $id,
            ]);

            return null;
        }

        // Collection 복원
        if (! empty($value[self::COLLECTION_MARKER])) {
            return collect(self::deserialize($value['items']));
        }

        // 일반 배열 → 재귀 역직렬화
        return array_map([self::class, 'deserializeValue'], $value);
    }
}
