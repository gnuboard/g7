<?php

namespace App\Extension\Helpers;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Contracts\Repositories\FieldValueRepositoryInterface;
use App\Enums\FieldEditScope;
use App\Enums\FieldSourceType;
use App\Enums\FieldStorageType;
use App\Fields\FieldSetRegistry;
use App\Fields\FieldTypeRegistry;
use App\Models\FieldDefinition;
use Illuminate\Support\Facades\Log;

/**
 * 항목 선언 동기화 Helper.
 *
 * 코어(`config/core.php` 의 `fields`)와 확장(`getFields()`)의 항목 선언을 field_definitions 로 동기화한다.
 * 운영자가 고친 속성은 user_overrides 로 보존된다(HasUserOverrides).
 *
 * stale 정리는 출처(source_type, source_identifier)가 같은 **선언 항목**으로 범위를 한정한다.
 * 운영자가 만든 항목(custom)은 어떤 동기화에서도 지우지 않는다.
 *
 * 다른 확장이 가진 집합에 선언하는 것(교차 기여)도 받는다. 다만 집합이 없거나, 기여를 받지 않거나,
 * 허용하지 않는 유형이거나, 키가 이미 있으면 그 선언은 건너뛰고 사유를 로그에 남긴다.
 *
 * @since 7.2.0
 */
class FieldDefinitionSyncHelper
{
    /** 선언에서 그대로 받는 불리언 속성과 기본값 */
    protected const BOOLEAN_DEFAULTS = [
        'enabled' => true,
        'required' => false,
        'show_on_signup' => true,
        'require_existing_members' => false,
        'public_profile' => false,
        'admin_list_column' => false,
        'admin_searchable' => false,
        'display_name_usable' => false,
        'idv_lock' => false,
    ];

    /**
     * @param  FieldDefinitionRepositoryInterface  $definitions  항목 정의 저장소
     * @param  FieldValueRepositoryInterface  $values  항목 값 저장소 (stale 항목의 값 정리)
     * @param  FieldSetRegistry  $sets  집합 등록소
     * @param  FieldTypeRegistry  $types  유형 등록소
     */
    public function __construct(
        protected FieldDefinitionRepositoryInterface $definitions,
        protected FieldValueRepositoryInterface $values,
        protected FieldSetRegistry $sets,
        protected FieldTypeRegistry $types,
    ) {}

    /**
     * 한 출처의 선언 전체를 동기화합니다.
     *
     * 선언이 빈 배열이면 stale 정리를 하지 않는다 — 환경 결함(부분 로드 등)으로 선언이 비어 보일 때
     * 운영자 설정이 든 행을 지우지 않기 위한 방어막이다(다른 선언 영역과 같은 규칙).
     *
     * @param  string  $sourceType  출처 (core|module|plugin)
     * @param  string  $sourceIdentifier  출처 식별자
     * @param  array<int, array<string, mixed>>  $declarations  항목 선언 목록
     * @return array{synced: array<int, string>, skipped: array<string, string>, removed: int} 동기화 결과 (`{집합}|{키}` 목록, 건너뛴 사유, 정리한 수)
     */
    public function sync(string $sourceType, string $sourceIdentifier, array $declarations): array
    {
        $result = ['synced' => [], 'skipped' => [], 'removed' => 0];

        if (! $this->definitions->tableExists()) {
            return $result; // 마이그레이션 미실행 환경 보호
        }
        if ($sourceType === FieldSourceType::Custom->value) {
            return $result; // 운영자 항목은 선언으로 동기화하지 않는다
        }

        if ($declarations === []) {
            if ($this->definitions->countBySource($sourceType, $sourceIdentifier) > 0) {
                Log::warning('항목 선언 정리 차단 — 선언이 비었는데 DB 행이 있습니다 (데이터 손실 방어)', [
                    'source_type' => $sourceType,
                    'source_identifier' => $sourceIdentifier,
                ]);
            }

            return $result;
        }

        foreach ($declarations as $declaration) {
            $id = ($declaration['field_set'] ?? '?').'|'.($declaration['key'] ?? '?');
            $reason = $this->rejectReason($sourceType, $sourceIdentifier, $declaration);
            if ($reason !== null) {
                $result['skipped'][$id] = $reason;
                Log::warning('항목 선언 건너뜀', ['field' => $id, 'source' => $sourceIdentifier, 'reason' => $reason]);

                continue;
            }

            $this->definitions->syncDeclared(
                (string) $declaration['field_set'],
                (string) $declaration['key'],
                self::attributesFrom($sourceType, $sourceIdentifier, $declaration),
            );
            $result['synced'][] = $id;
        }

        // 건너뛴 선언(소유 확장이 꺼져 있음 등)의 기존 행은 지우지 않는다 — 소유 확장을 다시 켜면
        // 운영자가 바꿔 둔 값과 함께 돌아와야 한다. 등록소가 읽을 때 걸러 낸다.
        $keep = [...$result['synced'], ...array_keys($result['skipped'])];
        foreach ($this->definitions->findStale($sourceType, $sourceIdentifier, $keep) as $stale) {
            $this->removeDefinition($stale);
            $result['removed']++;
        }

        if ($result['removed'] > 0) {
            Log::info('항목 선언: 선언에서 빠진 항목 정리', [
                'source_type' => $sourceType,
                'source_identifier' => $sourceIdentifier,
                'removed' => $result['removed'],
            ]);
        }

        return $result;
    }

    /**
     * 한 출처의 선언 항목을 모두 지웁니다 (확장 삭제).
     *
     * @param  string  $sourceType  출처 (module|plugin)
     * @param  string  $sourceIdentifier  출처 식별자
     * @return int 지운 항목 수
     */
    public function removeSource(string $sourceType, string $sourceIdentifier): int
    {
        if (! $this->definitions->tableExists() || $sourceType === FieldSourceType::Custom->value) {
            return 0;
        }

        $removed = 0;
        foreach ($this->definitions->findStale($sourceType, $sourceIdentifier, []) as $definition) {
            $this->removeDefinition($definition);
            $removed++;
        }

        return $removed;
    }

    /**
     * 선언을 받을 수 없는 사유를 반환합니다.
     *
     * @param  string  $sourceType  출처
     * @param  string  $sourceIdentifier  출처 식별자
     * @param  array<string, mixed>  $declaration  항목 선언
     * @return string|null 사유 (받을 수 있으면 null)
     */
    protected function rejectReason(string $sourceType, string $sourceIdentifier, array $declaration): ?string
    {
        $setKey = $declaration['field_set'] ?? null;
        $key = $declaration['key'] ?? null;
        $type = $declaration['type'] ?? null;

        if (! is_string($setKey) || ! is_string($key) || ! is_string($type)) {
            return 'field_set·key·type 은 필수입니다';
        }
        if (preg_match(FieldDefinition::KEY_PATTERN, $key) !== 1) {
            return '키 형식이 맞지 않습니다 (영문 소문자로 시작, 소문자·숫자·밑줄, 2~50자)';
        }
        if (! isset($declaration['name']) || ! is_array($declaration['name']) || $declaration['name'] === []) {
            return 'name(다국어 배열)은 필수입니다';
        }

        $set = $this->sets->get($setKey);
        if ($set === null) {
            return '집합이 없습니다 (집합을 가진 확장이 설치되지 않았거나 꺼져 있습니다)';
        }

        $ownsSet = $set->ownerType === $sourceType && $set->ownerIdentifier === $sourceIdentifier;
        if (! $ownsSet && ! $set->acceptsContributions) {
            return '이 집합은 다른 확장의 항목을 받지 않습니다';
        }
        if (! $set->allowsType($type)) {
            return '이 집합이 허용하지 않는 유형입니다';
        }
        if (! $this->types->has($type)) {
            return '등록되지 않은 유형입니다';
        }

        $storage = $declaration['storage'] ?? FieldStorageType::Value->value;
        if (! in_array($storage, FieldStorageType::values(), true)) {
            return '저장 방식이 올바르지 않습니다';
        }
        if ($storage === FieldStorageType::Column->value && ! $ownsSet) {
            return '컬럼 저장은 집합을 가진 쪽만 선언할 수 있습니다';
        }
        if ($storage === FieldStorageType::Handler->value && empty($declaration['storage_target'])) {
            return '핸들러 저장은 storage_target(핸들러 클래스)이 필요합니다';
        }

        // 같은 키를 다른 출처가 이미 갖고 있으면 먼저 등록된 쪽을 유지한다.
        $existing = $this->definitions->findByKey($setKey, $key);
        if ($existing !== null
            && ($existing->source_type !== $sourceType || $existing->source_identifier !== $sourceIdentifier)) {
            return '같은 키의 항목이 이미 있습니다';
        }

        return null;
    }

    /**
     * 선언을 저장 속성으로 바꿉니다.
     *
     * 동기화 전(코어 항목 행이 아직 없는 구간)에 등록소가 선언을 그대로 읽을 때도 같은 변환을 쓴다 —
     * 저장된 행과 선언에서 바로 만든 항목의 기본값이 갈리지 않게 하려는 것이다.
     *
     * @param  string  $sourceType  출처
     * @param  string  $sourceIdentifier  출처 식별자
     * @param  array<string, mixed>  $declaration  항목 선언
     * @return array<string, mixed> 저장 속성
     */
    public static function attributesFrom(string $sourceType, string $sourceIdentifier, array $declaration): array
    {
        $attributes = [
            'source_type' => $sourceType,
            'source_identifier' => $sourceIdentifier,
            'type' => (string) $declaration['type'],
            'storage' => (string) ($declaration['storage'] ?? FieldStorageType::Value->value),
            'storage_target' => $declaration['storage_target'] ?? null,
            'name' => $declaration['name'],
            'description' => $declaration['description'] ?? null,
            'placeholder' => $declaration['placeholder'] ?? null,
            'options' => $declaration['options'] ?? null,
            'constraints' => $declaration['constraints'] ?? null,
            'edit_scope' => in_array($declaration['edit_scope'] ?? null, FieldEditScope::values(), true)
                ? $declaration['edit_scope']
                : FieldEditScope::Self_->value,
            'idv_claim' => $declaration['idv_claim'] ?? null,
            'locks' => $declaration['locks'] ?? null,
            'legacy_keys' => $declaration['legacy_keys'] ?? null,
            'ui' => $declaration['ui'] ?? null,
            'sort_order' => (int) ($declaration['sort_order'] ?? 1000),
        ];

        foreach (self::BOOLEAN_DEFAULTS as $attribute => $default) {
            $attributes[$attribute] = (bool) ($declaration[$attribute] ?? $default);
        }

        // 본인만 고칠 수 있는 항목에만 「기존 회원에게도 입력 요구」가 의미가 있다.
        if ($attributes['edit_scope'] !== FieldEditScope::Self_->value) {
            $attributes['require_existing_members'] = false;
        }

        return $attributes;
    }

    /**
     * 항목과 그 값을 지웁니다.
     *
     * @param  FieldDefinition  $definition  지울 항목
     * @return void 반환값 없음
     */
    protected function removeDefinition(FieldDefinition $definition): void
    {
        $this->values->deleteForDefinition($definition->id);
        $this->definitions->delete($definition);
    }
}
