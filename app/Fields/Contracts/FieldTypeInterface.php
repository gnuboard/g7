<?php

namespace App\Fields\Contracts;

use App\Models\FieldDefinition;

/**
 * 항목 입력 유형 계약.
 *
 * 유형은 검증 규칙, 입력값 정규화, 저장 직렬화, 표시 문자열, 지원 기능을 책임진다.
 * 코어가 15종을 제공하고, 확장은 `getFieldTypes()` 또는 필터 `core.field.filter_types` 로 더한다.
 *
 * @since 7.2.0
 */
interface FieldTypeInterface
{
    /** 지원 기능: 중복 금지 */
    public const FEATURE_UNIQUE = 'unique';

    /** 지원 기능: 관리자 목록 검색 */
    public const FEATURE_SEARCH = 'search';

    /** 지원 기능: 관리자 목록 열 */
    public const FEATURE_LIST_COLUMN = 'list_column';

    /** 지원 기능: 표시 이름 종류로 사용 */
    public const FEATURE_DISPLAY_NAME = 'display_name';

    /** 지원 기능: 가입 화면에서 입력 */
    public const FEATURE_SIGNUP = 'signup';

    /** 지원 기능: 선택지 목록을 가짐 */
    public const FEATURE_OPTIONS = 'options';

    /**
     * 유형 키를 반환합니다.
     *
     * @return string 유형 키 (예: text, choice)
     */
    public function key(): string;

    /**
     * 운영자 화면에 보일 유형 이름을 반환합니다.
     *
     * @return string 번역된 유형 이름
     */
    public function label(): string;

    /**
     * 운영자 화면에 보일 유형 설명을 반환합니다.
     *
     * @return string 번역된 유형 설명
     */
    public function description(): string;

    /**
     * 유형 분류를 반환합니다 (항목 추가 화면의 타일 묶음).
     *
     * @return string 분류 키 (text|number|datetime|choice|contact|address|file|extension)
     */
    public function category(): string;

    /**
     * 화면이 고를 그리기 갈래를 반환합니다.
     *
     * @return string builtin|address|file|extension
     */
    public function renderer(): string;

    /**
     * 기능 지원 여부를 반환합니다.
     *
     * @param  string  $feature  FEATURE_* 상수
     * @return bool 지원하면 true
     */
    public function supports(string $feature): bool;

    /**
     * 값 검증 규칙을 반환합니다 (required·nullable 은 팩토리가 붙인다).
     *
     * 키는 요청 속성 경로다. 하위 값이 있는 유형(주소, 기간, 여러 개 선택)은 `{attribute}.zipcode` 같은
     * 하위 경로 규칙도 함께 돌려준다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string  $attribute  요청 속성 경로 (예: custom_fields.job)
     * @return array<string, array<int, mixed>> 속성 경로 => 규칙 배열
     */
    public function rules(FieldDefinition $definition, string $attribute): array;

    /**
     * 필수일 때 「채워졌다」고 볼 하위 경로를 반환합니다.
     *
     * 빈 배열이면 속성 자체에 required 를 건다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string  $attribute  요청 속성 경로
     * @return array<int, string> required 를 걸 속성 경로 목록
     */
    public function requiredAttributes(FieldDefinition $definition, string $attribute): array;

    /**
     * 이 항목이 요청에서 읽는 속성 경로 목록을 반환합니다.
     *
     * 대부분 `[$attribute]` 하나다. 코어 회원 주소처럼 하위 값이 최상위 키(zipcode, address, address_detail)로
     * 흩어진 항목은 그 키들을 돌려준다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string  $attribute  요청 속성 경로
     * @return array<int, string> 요청 속성 경로 목록
     */
    public function inputPaths(FieldDefinition $definition, string $attribute): array;

    /**
     * 검증을 통과한 요청 데이터에서 이 항목의 입력값을 꺼냅니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string  $attribute  요청 속성 경로
     * @param  array<string, mixed>  $data  요청 데이터
     * @return mixed 입력값 (요청에 없으면 null)
     */
    public function extract(FieldDefinition $definition, string $attribute, array $data): mixed;

    /**
     * 입력값을 저장용 값으로 정규화합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $input  검증을 통과한 입력값
     * @return mixed 정규화한 값 (빈 값이면 null)
     */
    public function normalize(FieldDefinition $definition, mixed $input): mixed;

    /**
     * 값이 비었는지 반환합니다.
     *
     * @param  mixed  $value  정규화한 값
     * @return bool 비었으면 true
     */
    public function isEmpty(mixed $value): bool;

    /**
     * 값을 공용 값 테이블의 칸으로 직렬화합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $value  정규화한 값
     * @return array{value_text?: ?string, value_index?: ?string, value_number?: int|float|string|null, value_date?: mixed, value_date_end?: mixed, value_json?: ?array<mixed>} 값 칸
     */
    public function serialize(FieldDefinition $definition, mixed $value): array;

    /**
     * 공용 값 테이블의 칸에서 값을 되살립니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  array<string, mixed>  $columns  값 칸 (value_text 등)
     * @return mixed 되살린 값
     */
    public function deserialize(FieldDefinition $definition, array $columns): mixed;

    /**
     * 보기용 문자열을 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $value  값
     * @param  string|null  $locale  로케일 (null 이면 현재 로케일)
     * @return string|null 보기용 문자열 (빈 값이면 null)
     */
    public function display(FieldDefinition $definition, mixed $value, ?string $locale = null): ?string;

    /**
     * 중복 금지 비교에 쓸 정규화 문자열을 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $value  값
     * @return string|null 중복 비교 키 (지원하지 않거나 빈 값이면 null)
     */
    public function uniqueKey(FieldDefinition $definition, mixed $value): ?string;

    /**
     * 운영자가 항목을 만들 때 넣는 제한 값(constraints)의 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> `constraints.{키}` => 규칙 배열
     */
    public function constraintRules(): array;
}
