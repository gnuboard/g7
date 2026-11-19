<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 항목 정의를 바꿀 수 없는 충돌.
 *
 * 다른 관리자가 먼저 항목을 바꿨거나(`fields_changed`), 다른 설정이 그 항목을 쓰고 있어(`field_in_use`)
 * 요청을 처리하지 않았음을 알린다. 컨트롤러가 409 와 `error_code` 로 바꾼다.
 *
 * @since 7.2.0
 */
class FieldDefinitionConflictException extends RuntimeException
{
    /** 다른 관리자가 먼저 항목을 바꿨다 */
    public const FIELDS_CHANGED = 'fields_changed';

    /** 다른 설정이 이 항목을 쓰고 있다 */
    public const FIELD_IN_USE = 'field_in_use';

    /**
     * @param  string  $errorCode  충돌 종류 (FIELDS_CHANGED|FIELD_IN_USE)
     * @param  string  $messageKey  응답 메시지 번역 키
     * @param  array<string, mixed>  $data  화면이 쓰는 부가 데이터 (새 버전, 사용처 목록 등)
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $messageKey,
        public readonly array $data = [],
    ) {
        parent::__construct($messageKey);
    }

    /**
     * 낡은 화면에서 보낸 요청임을 알리는 예외를 만듭니다.
     *
     * @param  string  $currentVersion  지금의 정의 버전
     * @return self 예외
     */
    public static function fieldsChanged(string $currentVersion): self
    {
        return new self(self::FIELDS_CHANGED, 'fields.errors.fields_changed', ['fields_version' => $currentVersion]);
    }

    /**
     * 다른 설정이 항목을 쓰고 있음을 알리는 예외를 만듭니다.
     *
     * @param  array<int, array<string, mixed>>  $usages  사용처 목록
     * @return self 예외
     */
    public static function inUse(array $usages): self
    {
        return new self(self::FIELD_IN_USE, 'fields.errors.field_in_use', ['usages' => $usages]);
    }
}
