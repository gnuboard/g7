<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;

/**
 * 주소(우편번호, 기본 주소, 상세 주소).
 *
 * 값은 `{zipcode, address, address_detail}` 이다. 필수 판정은 우편번호와 기본 주소로 한다(상세 주소는 선택).
 * 코어 회원 주소처럼 하위 값이 최상위 요청 키로 흩어진 항목은 `constraints.parts` 에 요청 키를 적는다
 * (예: `{zipcode: 'zipcode', address: 'address', address_detail: 'address_detail'}`).
 *
 * @since 7.2.0
 */
class AddressType extends AbstractFieldType
{
    /** 하위 값 이름과 최대 길이 — 회원 요청의 기존 규칙과 같다 */
    public const PARTS = ['zipcode' => 10, 'address' => 255, 'address_detail' => 255];

    protected array $features = [self::FEATURE_SIGNUP, self::FEATURE_SEARCH];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'address';
    }

    /**
     * {@inheritDoc}
     */
    public function category(): string
    {
        return 'address';
    }

    /**
     * {@inheritDoc}
     */
    public function renderer(): string
    {
        return 'address';
    }

    /**
     * 하위 값별 요청 속성 경로를 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string  $attribute  요청 속성 경로
     * @return array{zipcode: string, address: string, address_detail: string} 하위 값 => 요청 속성 경로
     */
    public function partPaths(FieldDefinition $definition, string $attribute): array
    {
        $declared = $definition->constraint('parts');
        $paths = [];
        foreach (array_keys(self::PARTS) as $part) {
            $paths[$part] = is_array($declared) && is_string($declared[$part] ?? null) && $declared[$part] !== ''
                ? $declared[$part]
                : $attribute.'.'.$part;
        }

        return $paths;
    }

    /**
     * {@inheritDoc}
     */
    public function rules(FieldDefinition $definition, string $attribute): array
    {
        $paths = $this->partPaths($definition, $attribute);
        $flat = is_array($definition->constraint('parts'));

        $rules = $flat ? [] : [$attribute => ['array:'.implode(',', array_keys(self::PARTS))]];
        foreach (self::PARTS as $part => $max) {
            $rules[$paths[$part]] = ['nullable', 'string', 'max:'.$max];
        }

        return $rules;
    }

    /**
     * {@inheritDoc}
     */
    public function requiredAttributes(FieldDefinition $definition, string $attribute): array
    {
        $paths = $this->partPaths($definition, $attribute);

        return [$paths['zipcode'], $paths['address']];
    }

    /**
     * {@inheritDoc}
     */
    public function inputPaths(FieldDefinition $definition, string $attribute): array
    {
        return array_values($this->partPaths($definition, $attribute));
    }

    /**
     * {@inheritDoc}
     */
    public function extract(FieldDefinition $definition, string $attribute, array $data): mixed
    {
        $value = [];
        $present = false;
        foreach ($this->partPaths($definition, $attribute) as $part => $path) {
            if (data_get($data, $path, $this) !== $this) {
                $present = true;
            }
            $value[$part] = data_get($data, $path);
        }

        return $present ? $value : null;
    }

    /**
     * {@inheritDoc}
     */
    public function normalize(FieldDefinition $definition, mixed $input): mixed
    {
        if (! is_array($input)) {
            return null;
        }

        $value = [];
        foreach (array_keys(self::PARTS) as $part) {
            $text = is_scalar($input[$part] ?? null) ? trim((string) $input[$part]) : '';
            $value[$part] = $text === '' ? null : $text;
        }

        return array_filter($value, fn ($part) => $part !== null) === [] ? null : $value;
    }

    /**
     * {@inheritDoc}
     *
     * 우편번호와 기본 주소가 모두 있어야 채워진 값이다.
     */
    public function isEmpty(mixed $value): bool
    {
        return ! is_array($value) || empty($value['zipcode']) || empty($value['address']);
    }

    /**
     * {@inheritDoc}
     */
    public function serialize(FieldDefinition $definition, mixed $value): array
    {
        if (! is_array($value)) {
            return ['value_json' => null, 'value_index' => null, 'value_text' => null];
        }

        $line = trim(($value['address'] ?? '').' '.($value['address_detail'] ?? ''));

        return [
            'value_json' => $value,
            'value_text' => $line === '' ? null : $line,
            'value_index' => $line === '' ? null : $this->indexOf($line),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function deserialize(FieldDefinition $definition, array $columns): mixed
    {
        $json = $columns['value_json'] ?? null;
        if (is_string($json)) {
            $json = json_decode($json, true);
        }

        return is_array($json) ? $this->normalize($definition, $json) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function display(FieldDefinition $definition, mixed $value, ?string $locale = null): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $zip = empty($value['zipcode']) ? '' : '('.$value['zipcode'].') ';
        $line = trim($zip.trim(($value['address'] ?? '').' '.($value['address_detail'] ?? '')));

        return $line === '' ? null : $line;
    }

    /**
     * {@inheritDoc}
     */
    public function uniqueKey(FieldDefinition $definition, mixed $value): ?string
    {
        return null;
    }
}
