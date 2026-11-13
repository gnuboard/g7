<?php

namespace App\Fields\Types;

use App\Models\FieldDefinition;

/**
 * 파일 첨부.
 *
 * 값은 폼 본문으로 오지 않는다 — 전용 업로드 API 가 첨부(attachments, collection `field:{key}`)로 저장하고,
 * 이 유형은 허용 확장자·개수·크기 제한과 보기용 문자열만 책임진다. 가입 화면에서는 받지 않는다.
 *
 * @since 7.2.0
 */
class FileType extends AbstractFieldType
{
    /** 항목 하나가 가질 수 있는 파일 수 상한 */
    public const MAX_COUNT_LIMIT = 10;

    /** 파일 하나의 크기 상한(MB) */
    public const MAX_SIZE_LIMIT_MB = 50;

    /** 기본 허용 확장자 */
    protected const DEFAULT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'hwp', 'hwpx', 'txt'];

    /** 가입 화면에서 받지 않으므로 FEATURE_SIGNUP 이 없다 */
    protected array $features = [];

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return 'file';
    }

    /**
     * {@inheritDoc}
     */
    public function category(): string
    {
        return 'file';
    }

    /**
     * {@inheritDoc}
     */
    public function renderer(): string
    {
        return 'file';
    }

    /**
     * {@inheritDoc}
     *
     * 폼 본문으로 받지 않으므로 규칙이 없다.
     */
    public function rules(FieldDefinition $definition, string $attribute): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function inputPaths(FieldDefinition $definition, string $attribute): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function extract(FieldDefinition $definition, string $attribute, array $data): mixed
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function normalize(FieldDefinition $definition, mixed $input): mixed
    {
        return null;
    }

    /**
     * {@inheritDoc}
     *
     * 값은 첨부 요약 목록(`[{id, name, ...}]`)으로 읽힌다.
     */
    public function isEmpty(mixed $value): bool
    {
        return ! is_array($value) || $value === [];
    }

    /**
     * {@inheritDoc}
     */
    public function serialize(FieldDefinition $definition, mixed $value): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function deserialize(FieldDefinition $definition, array $columns): mixed
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function display(FieldDefinition $definition, mixed $value, ?string $locale = null): ?string
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return implode(', ', array_filter(array_map(
            fn ($file) => is_array($file) ? (string) ($file['name'] ?? '') : '',
            $value,
        )));
    }

    /**
     * 업로드 파일 검증 규칙을 반환합니다 (전용 업로드 API 가 쓴다).
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return array<int, mixed> `file` 속성 규칙
     */
    public function uploadRules(FieldDefinition $definition): array
    {
        return [
            'required', 'file',
            'max:'.($this->maxSizeMb($definition) * 1024),
            'extensions:'.implode(',', $this->allowedExtensions($definition)),
        ];
    }

    /**
     * 허용 확장자 목록을 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return array<int, string> 소문자 확장자 목록
     */
    public function allowedExtensions(FieldDefinition $definition): array
    {
        $declared = $definition->constraint('extensions');
        $extensions = is_array($declared) && $declared !== []
            ? array_values(array_unique(array_map(fn ($ext) => strtolower(ltrim((string) $ext, '.')), $declared)))
            : static::DEFAULT_EXTENSIONS;

        // 운영자가 적은 값이라도 서버에서 실행될 수 있는 확장자는 받지 않는다.
        return array_values(array_diff($extensions, ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'phps', 'htaccess', 'exe', 'sh', 'bat', 'cmd', 'js', 'html', 'htm', 'svg']));
    }

    /**
     * 항목이 가질 수 있는 파일 수를 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return int 파일 수 상한
     */
    public function maxCount(FieldDefinition $definition): int
    {
        return max(1, min(self::MAX_COUNT_LIMIT, (int) $definition->constraint('max_count', 1)));
    }

    /**
     * 파일 하나의 크기 상한(MB)을 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return int 크기 상한(MB)
     */
    public function maxSizeMb(FieldDefinition $definition): int
    {
        return max(1, min(self::MAX_SIZE_LIMIT_MB, (int) $definition->constraint('max_size_mb', 10)));
    }

    /**
     * {@inheritDoc}
     */
    public function constraintRules(): array
    {
        return [
            'constraints.extensions' => ['nullable', 'array', 'max:30'],
            'constraints.extensions.*' => ['string', 'max:10', 'regex:/^\.?[a-zA-Z0-9]+$/'],
            'constraints.max_count' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_COUNT_LIMIT],
            'constraints.max_size_mb' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_SIZE_LIMIT_MB],
        ];
    }
}
