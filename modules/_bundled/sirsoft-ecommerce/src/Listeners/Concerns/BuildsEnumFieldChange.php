<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Listeners\Concerns;

/**
 * 상태(Enum) 필드 한 칸의 변경 항목을 코어 `changes` 형식으로 만듭니다.
 *
 * 활동 로그 화면(관리자 활동 로그 · 주문 상세 · 클레임 상세)은 `changes` 에 실린 항목만
 * "변경 전 → 변경 후" 표로 그린다. 상태 전이의 전/후를 `properties` 에만 두면 값은
 * 저장되는데 화면 어디에도 나타나지 않는다 — 그래서 훅이 전/후 값을 직접 주는 전이는
 * 모델 `$activityLogFields` 경로(`ChangeDetector`)를 거치지 않고 여기서 같은 모양을 만든다.
 *
 * 형식은 `ChangeDetector::detect()` 결과와 1:1 이다 (field / label_key / old / new / type +
 * enum 라벨 키). `ActivityLogResource` 가 `old_label_key`·`new_label_key` 를 표시 시점 로케일로
 * 해석하므로 라벨 문자열을 박제하지 않는다.
 */
trait BuildsEnumFieldChange
{
    /**
     * Enum 필드의 변경 항목을 만듭니다.
     *
     * @param  string  $field  필드명 (`status` / `option_status` / `order_status`)
     * @param  string  $labelKey  필드 라벨 다국어 키
     * @param  class-string<\BackedEnum>  $enumClass  값을 해석할 Enum 클래스 (`labelKey()` 보유)
     * @param  string|null  $old  변경 전 값
     * @param  string|null  $new  변경 후 값
     * @return array{field: string, label_key: string, old: string|null, new: string|null, type: string, old_label_key: string|null, new_label_key: string|null} 변경 항목
     */
    protected function enumFieldChange(
        string $field,
        string $labelKey,
        string $enumClass,
        ?string $old,
        ?string $new,
    ): array {
        return [
            'field' => $field,
            'label_key' => $labelKey,
            'old' => $old,
            'new' => $new,
            'type' => 'enum',
            'old_label_key' => $old !== null ? $enumClass::tryFrom($old)?->labelKey() : null,
            'new_label_key' => $new !== null ? $enumClass::tryFrom($new)?->labelKey() : null,
        ];
    }
}
