<?php

namespace App\Extension;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
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
     * Enum 직렬화 마커 키
     */
    private const ENUM_MARKER = '__hook_enum__';

    /**
     * 날짜 값 직렬화 마커 키 (Carbon·CarbonImmutable·DateTime·DateTimeImmutable).
     */
    private const DATETIME_MARKER = '__hook_datetime__';

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
        // Eloquent Model → 클래스명 + PK + attributes 스냅샷
        // attributes 는 hard-delete 모델의 after_delete 훅 페이로드 복원용 fallback.
        // 큐 워커가 find($id) 로 조회 못 하더라도 attributes 로 in-memory 모델 재생성 가능.
        if ($value instanceof Model) {
            return [
                self::MODEL_MARKER => true,
                'class' => get_class($value),
                'id' => $value->getKey(),
                'attributes' => $value->getAttributes(),
            ];
        }

        // Backed Enum → 클래스명 + value (역직렬화 시 from() 사용)
        if ($value instanceof \BackedEnum) {
            return [
                self::ENUM_MARKER => true,
                'class' => get_class($value),
                'value' => $value->value,
            ];
        }

        // Pure (Unit) Enum → 클래스명 + name (역직렬화 시 constant() 사용)
        if ($value instanceof \UnitEnum) {
            return [
                self::ENUM_MARKER => true,
                'class' => get_class($value),
                'name' => $value->name,
            ];
        }

        // 날짜 → 클래스명 + 마이크로초·시간대까지 담은 문자열. 예전에는 직렬화 불가로 null 이 돼
        // 큐 리스너가 가입 시각·동의 시각을 비어 있는 값으로 받았다(#138 후속).
        if ($value instanceof \DateTimeInterface) {
            return [
                self::DATETIME_MARKER => true,
                'class' => get_class($value),
                'value' => $value->format('Y-m-d\TH:i:s.uP'),
                'timezone' => $value->getTimezone()->getName(),
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
                // 1차: DB 조회로 복원 (살아있는 레코드 또는 SoftDeletes 의 trashed)
                $found = in_array(SoftDeletes::class, class_uses_recursive($class), true)
                    ? $class::withTrashed()->find($id)
                    : $class::find($id);

                if ($found !== null) {
                    return $found;
                }

                // 2차: hard-delete 된 모델은 직렬화 시점의 attributes 스냅샷으로 in-memory 재생성
                // (after_delete 훅의 큐 워커가 listener 호출 시 null 대신 살아있을 때 상태 전달)
                if (isset($value['attributes']) && is_array($value['attributes'])) {
                    /** @var Model $instance */
                    $instance = new $class;
                    $instance->setRawAttributes($value['attributes']);
                    $instance->exists = false; // in-memory only — save() 가 update 시도 못 하도록

                    return $instance;
                }
            }

            Log::warning('훅 인자 역직렬화 실패: 모델 클래스를 찾을 수 없거나 attributes 부재.', [
                'class' => $class,
                'id' => $id,
            ]);

            return null;
        }

        // Collection 복원
        if (! empty($value[self::DATETIME_MARKER])) {
            return self::restoreDateTime($value);
        }

        if (! empty($value[self::COLLECTION_MARKER])) {
            return collect(self::deserialize($value['items']));
        }

        // Enum 복원
        if (! empty($value[self::ENUM_MARKER])) {
            $class = $value['class'];

            if (! enum_exists($class)) {
                Log::warning('훅 인자 역직렬화 실패: enum 클래스를 찾을 수 없습니다.', [
                    'class' => $class,
                ]);

                return null;
            }

            // BackedEnum: value 키로 from()
            if (array_key_exists('value', $value)) {
                return $class::from($value['value']);
            }

            // UnitEnum: name 키로 constant()
            if (array_key_exists('name', $value)) {
                return constant($class.'::'.$value['name']);
            }

            return null;
        }

        // 일반 배열 → 재귀 역직렬화
        return array_map([self::class, 'deserializeValue'], $value);
    }

    /**
     * 날짜 마커를 원래 클래스의 날짜 객체로 복원합니다.
     *
     * 클래스가 없거나 날짜 클래스가 아니면 Carbon 으로 복원한다 — 시각 자체는 잃지 않는다.
     *
     * @param  array<string, mixed>  $value  날짜 마커 배열
     * @return \DateTimeInterface|null 복원된 날짜 (값이 깨졌으면 null)
     */
    private static function restoreDateTime(array $value): ?\DateTimeInterface
    {
        $class = (string) ($value['class'] ?? '');
        $raw = (string) ($value['value'] ?? '');
        $timezone = new \DateTimeZone((string) ($value['timezone'] ?? 'UTC'));

        try {
            $instance = new \DateTimeImmutable($raw);
            $instance = $instance->setTimezone($timezone);

            if ($class !== '' && class_exists($class) && is_subclass_of($class, \DateTimeInterface::class)) {
                if (method_exists($class, 'instance')) {
                    return $class::instance($instance);
                }

                if (is_a($class, \DateTimeImmutable::class, true)) {
                    return $class::createFromInterface($instance);
                }

                if (is_a($class, \DateTime::class, true)) {
                    return $class::createFromInterface($instance);
                }
            }

            return Carbon::instance($instance);
        } catch (\Throwable $e) {
            Log::warning('훅 인자 역직렬화 실패: 날짜 값을 해석할 수 없습니다.', [
                'class' => $class,
                'value' => $raw,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
