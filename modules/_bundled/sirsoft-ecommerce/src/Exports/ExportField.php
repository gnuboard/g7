<?php

namespace Modules\Sirsoft\Ecommerce\Exports;

use Closure;

/**
 * 내보내기 필드 하나의 정의
 *
 * 모델 선언 클래스(`Declarations\*Declaration::fields()`)나 유형 클래스의 계산 필드에서 만들어진다. 파일의 한 열(다국어 「언어별
 * 컬럼」 형식이면 언어 수만큼의 열)이 된다.
 */
final class ExportField
{
    /**
     * @param  string  $key  필드 키 (출처.컬럼, 예: order.total_amount)
     * @param  string  $labelKey  헤더 라벨 다국어 키
     * @param  ExportFieldType  $type  값 종류
     * @param  bool  $personal  개인정보 여부 (true 면 사유 입력·전용 권한 요구)
     * @param  Closure  $resolver  행 → 원값 (fn (object $row): mixed)
     * @param  bool  $summable  합계 행 대상 여부
     * @param  string  $group  화면 컬럼 키 (없으면 extra = 「추가 필드」)
     * @param  array<int, string>  $units  쓸 수 있는 행 단위 (빈 배열이면 전 단위)
     * @param  string|null  $enumClass  상태값 Enum 클래스 (문자열 원값을 라벨로 바꿀 때)
     * @param  bool  $parentLevel  상위 단위 값 — 하위 단위 행에서는 상위의 첫 행에만 쓰는 금액(D13)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $labelKey,
        public readonly ExportFieldType $type,
        public readonly bool $personal,
        public readonly Closure $resolver,
        public readonly bool $summable = false,
        public readonly string $group = 'extra',
        public readonly array $units = [],
        public readonly ?string $enumClass = null,
        public readonly bool $parentLevel = false,
    ) {}

    /**
     * 이 필드를 주어진 행 단위에서 쓸 수 있는지 반환합니다.
     *
     * @param  string  $unit  행 단위
     * @return bool 사용 가능 여부
     */
    public function availableIn(string $unit): bool
    {
        return $this->units === [] || in_array($unit, $this->units, true);
    }

    /**
     * 일부 속성만 바꾼 사본을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  바꿀 속성 (생성자 인자 이름)
     * @return self 사본
     */
    public function with(array $overrides): self
    {
        return new self(
            key: $overrides['key'] ?? $this->key,
            labelKey: $overrides['labelKey'] ?? $this->labelKey,
            type: $overrides['type'] ?? $this->type,
            personal: $overrides['personal'] ?? $this->personal,
            resolver: $overrides['resolver'] ?? $this->resolver,
            summable: $overrides['summable'] ?? $this->summable,
            group: $overrides['group'] ?? $this->group,
            units: $overrides['units'] ?? $this->units,
            enumClass: array_key_exists('enumClass', $overrides) ? $overrides['enumClass'] : $this->enumClass,
            parentLevel: $overrides['parentLevel'] ?? $this->parentLevel,
        );
    }
}
