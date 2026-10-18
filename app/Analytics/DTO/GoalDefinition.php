<?php

namespace App\Analytics\DTO;

use App\Analytics\LocalizedText;

/**
 * 등록된 전환(목표) 정의
 *
 * 코어(`config/analytics.php` `goals`)와 확장(`getAnalyticsGoals()`)이 선언한 목표를
 * 정규화한 결과입니다. 수집 경로는 이 정의가 있는 목표만 받습니다 — 등록부 없이
 * 임의 문자열을 받으면 오타 하나가 새 목표로 쌓이고, 어느 확장이 준 전환인지도
 * 가릴 수 없습니다.
 */
final readonly class GoalDefinition
{
    /**
     * 허용하는 성격 분류
     *
     * @var array<int, string>
     */
    public const CATEGORIES = ['purchase', 'signup', 'engagement', 'custom'];

    /**
     * 허용하는 중복 판정 방식
     *
     * @var array<int, string>
     */
    public const DEDUPE_MODES = ['every_time', 'once_per_visit', 'once_per_visitor'];

    /**
     * 허용하는 속성 값 종류
     *
     * @var array<int, string>
     */
    public const ATTRIBUTE_TYPES = ['decimal', 'int', 'string', 'enum', 'bool'];

    /**
     * 목표 정의를 생성합니다.
     *
     * @param  string  $key  소유자 안에서 고유한 키
     * @param  string  $ownerType  공급자 종류 (core, module, plugin)
     * @param  string  $ownerIdentifier  공급자 식별자 (코어는 core)
     * @param  array<string, string>  $label  로케일별 이름
     * @param  array<string, string>  $description  로케일별 설명
     * @param  string  $category  성격 분류
     * @param  string  $dedupe  중복 판정 방식
     * @param  array<string, array<string, mixed>>  $attributes  속성 스키마 (이름 → 규칙)
     * @param  int  $attributionWindowDays  귀속 기간 (일) — 표시용 메타데이터
     * @param  array<int, string>  $dimensions  함께 볼 차원 키 (같은 공급자가 선언한 것)
     */
    public function __construct(
        public string $key,
        public string $ownerType,
        public string $ownerIdentifier,
        public array $label,
        public array $description,
        public string $category,
        public string $dedupe,
        public array $attributes,
        public int $attributionWindowDays,
        public array $dimensions,
    ) {}

    /**
     * 수집·원장에 쓰는 정규화 키를 반환합니다.
     *
     * @return string `{공급자}.{키}`
     */
    public function qualifiedKey(): string
    {
        return $this->ownerIdentifier.'.'.$this->key;
    }

    /**
     * 확장이 공급한 목표인지 확인합니다.
     *
     * @return bool 모듈·플러그인이 공급했으면 true
     */
    public function isExtension(): bool
    {
        return $this->ownerType !== 'core';
    }

    /**
     * 현재 로케일의 이름을 반환합니다.
     *
     * @param  string|null  $locale  고를 로케일 (없으면 현재 앱 로케일)
     * @return string 이름 (지정 로케일 → en → 첫 값 → 키 순)
     */
    public function localizedLabel(?string $locale = null): string
    {
        return LocalizedText::pick($this->label, $this->qualifiedKey(), $locale);
    }

    /**
     * 전환 속성을 선언된 스키마로 검증합니다.
     *
     * 스키마에 없는 속성은 버립니다 — 확장이 선언하지 않은 값을 원장에 남기면 그것이
     * 무엇인지 아무도 설명할 수 없습니다. `revenue`·`currency`·`label` 은 원장 칸으로 가고
     * 나머지는 전환 행의 차원 칸에 남지 않고 버려집니다(원장에 자리가 없습니다).
     *
     * @param  array<string, mixed>  $values  전송된 속성
     * @return array{0: array<string, mixed>, 1: string|null} [통과한 속성, 위반 사유]
     */
    public function validateAttributes(array $values): array
    {
        $clean = [];

        foreach ($this->attributes as $name => $rule) {
            $present = array_key_exists($name, $values) && $values[$name] !== null && $values[$name] !== '';

            if (! $present) {
                if ((bool) ($rule['required'] ?? false)) {
                    return [[], "필수 속성 누락: {$name}"];
                }

                continue;
            }

            [$value, $error] = self::castAttribute($name, $values[$name], $rule);

            if ($error !== null) {
                return [[], $error];
            }

            $clean[$name] = $value;
        }

        return [$clean, null];
    }

    /**
     * 속성 값 하나를 규칙에 맞춰 변환합니다.
     *
     * @param  string  $name  속성 이름
     * @param  mixed  $value  전송된 값
     * @param  array<string, mixed>  $rule  속성 규칙
     * @return array{0: mixed, 1: string|null} [변환된 값, 위반 사유]
     */
    private static function castAttribute(string $name, mixed $value, array $rule): array
    {
        $type = (string) ($rule['type'] ?? 'string');

        switch ($type) {
            case 'decimal':
            case 'int':
                $invalid = $type === 'int'
                    ? filter_var($value, FILTER_VALIDATE_INT) === false
                    : ! is_numeric($value);

                if ($invalid) {
                    return [null, "속성 형식 위반: {$name} ({$type})"];
                }

                $number = $type === 'int' ? (int) $value : (float) $value;

                if (isset($rule['min']) && $number < $rule['min']) {
                    return [null, "속성 하한 위반: {$name} < {$rule['min']}"];
                }

                if (isset($rule['max']) && $number > $rule['max']) {
                    return [null, "속성 상한 위반: {$name} > {$rule['max']}"];
                }

                return [$number, null];

            case 'enum':
                $allowed = array_map('strval', (array) ($rule['values'] ?? []));

                if (! is_scalar($value) || ! in_array((string) $value, $allowed, true)) {
                    return [null, "속성 허용값 위반: {$name}"];
                }

                return [(string) $value, null];

            case 'bool':
                if (! is_bool($value) && ! in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    return [null, "속성 형식 위반: {$name} (bool)"];
                }

                return [filter_var($value, FILTER_VALIDATE_BOOLEAN), null];

            default:
                if (! is_scalar($value)) {
                    return [null, "속성 형식 위반: {$name} (string)"];
                }

                $max = (int) ($rule['max'] ?? 255);

                return [mb_substr((string) $value, 0, max(1, $max)), null];
        }
    }

    /**
     * API 응답용 배열로 바꿉니다.
     *
     * @return array<string, mixed> 목표 정의
     */
    public function toArray(): array
    {
        return [
            'key' => $this->qualifiedKey(),
            'owner_type' => $this->ownerType,
            'owner_identifier' => $this->ownerIdentifier,
            'label' => $this->localizedLabel(),
            'description' => LocalizedText::pick($this->description, ''),
            'category' => $this->category,
            'dedupe' => $this->dedupe,
            'attributes' => $this->attributes,
            'attribution_window_days' => $this->attributionWindowDays,
            'dimensions' => $this->dimensions,
        ];
    }
}
