<?php

namespace App\Services;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Contracts\Repositories\FieldValueRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\FieldEditScope;
use App\Enums\FieldSourceType;
use App\Enums\FieldStorageType;
use App\Exceptions\FieldDefinitionConflictException;
use App\Extension\HookManager;
use App\Fields\Contracts\FieldTypeInterface;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Fields\FieldTypeRegistry;
use App\Fields\Storage\ColumnFieldStorage;
use App\Models\FieldDefinition;
use App\Models\FieldValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 운영자의 항목 관리(환경설정 > 회원 > 가입 항목 표 등) 유스케이스.
 *
 * 표의 변경은 즉시 저장된다. 낡은 표에서 온 요청은 `fields_version` 대조로 걸러 낸다.
 *
 * @since 7.2.0
 */
class FieldDefinitionService
{
    /** 선언 항목(코어·확장)에서 운영자가 바꿀 수 있는 속성 */
    public const DECLARED_EDITABLE = [
        'name', 'description', 'placeholder',
        'enabled', 'required', 'show_on_signup', 'edit_scope', 'require_existing_members',
        'public_profile', 'admin_list_column', 'admin_searchable', 'idv_lock',
    ];

    /** 운영자가 만든 항목에서 바꿀 수 있는 속성 (키와 유형은 만든 뒤 바꾸지 못한다) */
    public const CUSTOM_EDITABLE = [
        ...self::DECLARED_EDITABLE,
        'options', 'constraints', 'display_name_usable', 'idv_claim', 'ui',
    ];

    /**
     * @param  FieldDefinitionRepositoryInterface  $definitions  항목 정의 저장소
     * @param  FieldValueRepositoryInterface  $values  항목 값 저장소
     * @param  UserRepositoryInterface  $users  회원 저장소 (미입력 회원 수)
     * @param  FieldRegistry  $registry  항목 등록소
     * @param  FieldTypeRegistry  $types  유형 등록소
     * @param  ColumnFieldStorage  $columnStorage  컬럼 저장소 (미입력 회원 수 계산)
     * @param  MemberFieldFileService  $memberFiles  회원 파일 항목 첨부 (항목 삭제 때 세고 지운다)
     */
    public function __construct(
        protected FieldDefinitionRepositoryInterface $definitions,
        protected FieldValueRepositoryInterface $values,
        protected UserRepositoryInterface $users,
        protected FieldRegistry $registry,
        protected FieldTypeRegistry $types,
        protected ColumnFieldStorage $columnStorage,
        protected MemberFieldFileService $memberFiles,
    ) {}

    /**
     * 집합의 항목 목록을 반환합니다 (운영자 표 — 꺼진 항목과 사용 불가 유형 포함).
     *
     * @param  string  $set  집합 식별자
     * @return Collection<int, FieldDefinition> 표시 순서의 항목 목록
     */
    public function list(string $set): Collection
    {
        return $this->registry->forSet($set);
    }

    /**
     * 집합의 정의 버전을 반환합니다.
     *
     * @param  string  $set  집합 식별자
     * @return string 정의 버전
     */
    public function version(string $set): string
    {
        return $this->registry->version($set);
    }

    /**
     * 요청이 실은 정의 버전이 지금과 같은지 확인합니다.
     *
     * 버전을 싣지 않은 요청은 통과시킨다(옛 클라이언트·스크립트 호환).
     *
     * @param  string  $set  집합 식별자
     * @param  string|null  $version  요청이 실은 버전
     * @return void 반환값 없음
     *
     * @throws FieldDefinitionConflictException 다른 관리자가 먼저 항목을 바꿨을 때
     */
    public function assertVersion(string $set, ?string $version): void
    {
        if ($version === null || $version === '') {
            return;
        }

        $current = $this->version($set);
        if (! hash_equals($current, $version)) {
            throw FieldDefinitionConflictException::fieldsChanged($current);
        }
    }

    /**
     * 집합 안의 항목을 ID 로 찾습니다.
     *
     * @param  string  $set  집합 식별자
     * @param  int  $id  항목 ID
     * @return FieldDefinition|null 항목 (다른 집합의 항목이면 null)
     */
    public function find(string $set, int $id): ?FieldDefinition
    {
        $definition = $this->definitions->findById($id);

        return $definition !== null && $definition->field_set === $set ? $definition : null;
    }

    /**
     * 항목을 화면에 내줄 모습으로 반환합니다 (다른 설정이 거는 강제 잠금을 적용한 사본).
     *
     * 저장 직후의 응답이 목록과 같은 값을 보이게 한다. 표시 이름 설정이 닉네임을 요구하면 닉네임 항목은
     * 저장된 값과 무관하게 사용(과 필수)으로 나간다.
     *
     * @param  FieldDefinition  $definition  저장소에서 읽은 항목
     * @return FieldDefinition 화면용 항목 (등록소에 없으면 받은 항목 그대로)
     */
    public function presented(FieldDefinition $definition): FieldDefinition
    {
        return $this->registry->forSet($definition->field_set)
            ->first(fn (FieldDefinition $candidate) => $candidate->id === $definition->id) ?? $definition;
    }

    /**
     * 운영자 항목을 만듭니다.
     *
     * @param  FieldSet  $set  집합
     * @param  array<string, mixed>  $data  검증된 입력
     * @return FieldDefinition 만든 항목
     */
    public function create(FieldSet $set, array $data): FieldDefinition
    {
        $attributes = $this->normalizeFlags(array_merge(
            array_intersect_key($data, array_flip(self::CUSTOM_EDITABLE)),
            [
                'field_set' => $set->key,
                'key' => (string) $data['key'],
                'type' => (string) $data['type'],
                'source_type' => FieldSourceType::Custom->value,
                'source_identifier' => FieldSourceType::Custom->value,
                'storage' => FieldStorageType::Value->value,
                'storage_target' => null,
                'sort_order' => $this->definitions->maxSortOrder($set->key) + 10,
            ],
        ), $this->types->get((string) $data['type']));

        $definition = $this->definitions->create($attributes);

        HookManager::doAction('core.field_definition.after_create', $definition);

        return $definition;
    }

    /**
     * 항목을 수정합니다.
     *
     * 선언 항목은 바꿀 수 있는 속성이 좁고, 선언한 쪽이 잠근 속성(`locks`)은 무시한다.
     * 선택지를 지우면 그 값을 고른 소유자의 값을 같은 트랜잭션에서 비운다.
     *
     * @param  FieldDefinition  $definition  항목
     * @param  array<string, mixed>  $data  검증된 입력
     * @return FieldDefinition 수정한 항목
     */
    public function update(FieldDefinition $definition, array $data): FieldDefinition
    {
        $editable = $definition->isCustom() ? self::CUSTOM_EDITABLE : self::DECLARED_EDITABLE;
        $attributes = array_intersect_key($data, array_flip($editable));
        // 선언한 쪽의 잠금과, 표시 이름 설정이 닉네임 항목에 거는 잠금을 함께 본다.
        foreach (array_keys($this->registry->locksFor($definition)) as $locked) {
            unset($attributes[$locked]);
        }

        if (array_key_exists('constraints', $attributes) && is_array($attributes['constraints'])) {
            // 화면이 보내지 않는 선언 전용 제한 값(parts 등)이 사라지지 않게 합친다.
            $attributes['constraints'] = array_merge($definition->constraints ?? [], $attributes['constraints']);
            // 화면이 지운 제한 값은 null 로 온다 — 키째 빼서 「제한 없음」으로 저장한다.
            $attributes['constraints'] = array_filter($attributes['constraints'], fn ($value) => $value !== null);
        }

        $attributes = $this->normalizeFlags(
            $attributes,
            $this->types->get($definition->type),
            $definition,
        );

        DB::transaction(function () use ($definition, $attributes) {
            if (array_key_exists('options', $attributes)) {
                $this->clearRemovedOptions($definition, (array) ($attributes['options'] ?? []));
            }
            if ($attributes !== []) {
                $this->definitions->update($definition, $attributes);
            }
        });

        HookManager::doAction('core.field_definition.after_update', $definition, $attributes);

        return $definition;
    }

    /**
     * 운영자 항목과 그 값을 삭제합니다.
     *
     * 삭제 전 필터 `core.field_definition.filter_usages` 로 사용처를 모아, 다른 설정이 이 항목을 쓰고 있으면
     * 삭제하지 않고 충돌로 알린다(표시 이름 종류로 쓰이는 항목 등).
     *
     * 파일·이미지 항목의 값은 값 테이블이 아니라 첨부(`field:{키}`)라, 회원들이 올린 첨부도 함께 지운다. 파일 삭제는
     * 되돌릴 수 없으므로 대상을 먼저 모아 두고 항목 행이 지워진 트랜잭션이 커밋된 뒤 첨부 삭제 경로(저장 파일·썸네일 정리 포함)로 지운다.
     *
     * @param  FieldDefinition  $definition  항목 (운영자 항목)
     * @return int 함께 지운 값 수 (값 행 + 파일 항목의 첨부)
     *
     * @throws FieldDefinitionConflictException 다른 설정이 이 항목을 쓰고 있을 때
     */
    public function delete(FieldDefinition $definition): int
    {
        $usages = HookManager::applyFilters('core.field_definition.filter_usages', [], $definition);
        if (is_array($usages) && $usages !== []) {
            throw FieldDefinitionConflictException::inUse(array_values($usages));
        }

        $snapshot = clone $definition;
        // 지울 첨부는 ID 만 모은다 — 회원 수만큼 첨부 모델을 메모리에 올리지 않는다
        $attachmentIds = $this->memberFiles->attachmentIdsForDefinition($definition);
        $removed = DB::transaction(function () use ($definition) {
            $count = $this->values->deleteForDefinition($definition->id);
            $this->definitions->delete($definition);

            return $count;
        });

        $removed += $this->memberFiles->purgeAttachmentIds($attachmentIds);

        HookManager::doAction('core.field_definition.after_delete', $snapshot);

        return $removed;
    }

    /**
     * 표시 순서를 바꿉니다.
     *
     * @param  string  $set  집합 식별자
     * @param  array<int, int>  $orderedIds  새 순서의 항목 ID 목록
     * @return void 반환값 없음
     */
    public function reorder(string $set, array $orderedIds): void
    {
        $this->definitions->reorder($set, $orderedIds);
    }

    /**
     * 항목이 비어 있는 기존 회원 수를 반환합니다 (회원 집합 전용 — 필수로 바꾸기 전 안내).
     *
     * @param  FieldDefinition  $definition  항목
     * @return int 회원 수 (회원 집합이 아니거나 셀 수 없는 저장 방식이면 0)
     */
    public function missingCount(FieldDefinition $definition): int
    {
        if ($definition->field_set !== FieldSet::CORE_USER) {
            return 0;
        }

        return match ($definition->storage) {
            FieldStorageType::Column->value => $this->users->countMembersWithEmptyColumns(
                $this->requiredColumns($definition),
            ),
            FieldStorageType::Value->value => $this->users->countMembersWithoutFieldValue($definition->id),
            default => 0,
        };
    }

    /**
     * 항목에 값이 있는 소유자 수를 반환합니다.
     *
     * 파일·이미지 항목은 값이 첨부라 파일을 올린 소유자 수를 센다(값 테이블에는 행이 없다). 유형을 준 확장이 빠져 유형을
     * 알 수 없어도 값 행이 없으면 첨부를 센다 — 삭제({@see delete()})는 유형과 무관하게 그 항목의 첨부를 지우므로 안내와 맞춘다.
     * 첨부 수는 DB 집계로 센다(회원 수만큼 모델을 올리지 않는다).
     *
     * @param  FieldDefinition  $definition  항목
     * @return int 값 행 수 또는 파일을 올린 소유자 수 (값 테이블 저장이 아니면 0)
     */
    public function valuesCount(FieldDefinition $definition): int
    {
        if ($definition->storage !== FieldStorageType::Value->value) {
            return 0;
        }
        if ($this->registry->typeOf($definition)?->renderer() === 'file') {
            return $this->memberFiles->ownersCountForDefinition($definition);
        }

        $rows = $this->values->countForDefinition($definition->id);

        return $rows > 0 ? $rows : $this->memberFiles->ownersCountForDefinition($definition);
    }

    /**
     * 확장(모듈·플러그인)의 이름을 요청 언어로 반환합니다 (확장이 준 입력 유형의 출처 배지).
     *
     * @param  string  $type  확장 종류 (module|plugin)
     * @param  string  $identifier  확장 식별자
     * @return string 확장 이름 (못 찾으면 식별자)
     */
    public function extensionLabel(string $type, string $identifier): string
    {
        return $this->registry->extensionLabel($type, $identifier);
    }

    /**
     * 선택지별 사용 수를 반환합니다.
     *
     * 여러 개 선택은 한 행에 값이 여럿이라 행을 훑어 센다.
     *
     * @param  FieldDefinition  $definition  항목
     * @return array<string, int> 선택지 값 => 사용 수 (「기타」는 `__other__`)
     */
    public function optionUsage(FieldDefinition $definition): array
    {
        if ($definition->storage !== FieldStorageType::Value->value
            || $this->types->get($definition->type)?->supports(FieldTypeInterface::FEATURE_OPTIONS) !== true) {
            return [];
        }

        if ($definition->type !== 'multi_choice') {
            return $this->values->countByIndex($definition->id);
        }

        $usage = [];
        $this->values->chunkForDefinition($definition->id, function ($rows) use (&$usage) {
            foreach ($rows as $row) {
                foreach ((array) ($row->value_json ?? []) as $picked) {
                    $usage[(string) $picked] = ($usage[(string) $picked] ?? 0) + 1;
                }
            }
        });

        return $usage;
    }

    /**
     * 서로 얽힌 속성을 한 모양으로 맞춥니다.
     *
     * - 본인 수정 범위가 아니면 「기존 회원에게도 입력 요구」를 끈다(관리자만 넣는 값을 회원에게 요구할 수 없다).
     * - 유형이 지원하지 않는 기능(중복 금지, 표시 이름, 목록 열, 검색)은 끈다.
     *
     * @param  array<string, mixed>  $attributes  저장할 속성
     * @param  FieldTypeInterface|null  $type  유형
     * @param  FieldDefinition|null  $current  수정 중인 항목 (만들 때는 null)
     * @return array<string, mixed> 맞춘 속성
     */
    protected function normalizeFlags(array $attributes, ?FieldTypeInterface $type, ?FieldDefinition $current = null): array
    {
        $scope = $attributes['edit_scope'] ?? $current?->edit_scope ?? FieldEditScope::Self_->value;
        if ($scope !== FieldEditScope::Self_->value
            && (($attributes['require_existing_members'] ?? $current?->require_existing_members) === true)) {
            $attributes['require_existing_members'] = false;
        }

        if ($type !== null) {
            foreach ([
                'display_name_usable' => FieldTypeInterface::FEATURE_DISPLAY_NAME,
                'admin_list_column' => FieldTypeInterface::FEATURE_LIST_COLUMN,
                'admin_searchable' => FieldTypeInterface::FEATURE_SEARCH,
            ] as $flag => $feature) {
                if (($attributes[$flag] ?? false) && ! $type->supports($feature)) {
                    $attributes[$flag] = false;
                }
            }
            if (isset($attributes['constraints']['unique']) && ! $type->supports(FieldTypeInterface::FEATURE_UNIQUE)) {
                $attributes['constraints']['unique'] = false;
            }
        }

        return $attributes;
    }

    /**
     * 지워진 선택지를 고른 값을 비웁니다.
     *
     * @param  FieldDefinition  $definition  항목
     * @param  array<int, mixed>  $newOptions  새 선택지 목록
     * @return void 반환값 없음
     */
    protected function clearRemovedOptions(FieldDefinition $definition, array $newOptions): void
    {
        if ($definition->storage !== FieldStorageType::Value->value) {
            return;
        }

        $valueOf = fn ($option) => is_array($option) && isset($option['value']) ? (string) $option['value'] : null;
        $before = array_filter(array_map($valueOf, $definition->options ?? []), fn ($value) => $value !== null);
        $after = array_filter(array_map($valueOf, $newOptions), fn ($value) => $value !== null);
        $removed = array_values(array_diff($before, $after));
        if ($removed === []) {
            return;
        }

        if ($definition->type !== 'multi_choice') {
            $this->values->deleteByIndex($definition->id, $removed);

            return;
        }

        // 여러 개 선택: 지워진 값만 빼고, 남는 것이 없으면 행을 지운다.
        $this->values->chunkForDefinition($definition->id, function ($rows) use ($definition, $removed) {
            /** @var FieldValue $row */
            foreach ($rows as $row) {
                $picked = (array) ($row->value_json ?? []);
                $kept = array_values(array_diff($picked, $removed));
                if (count($kept) === count($picked)) {
                    continue;
                }
                if ($kept === []) {
                    $this->values->deleteOne($definition->id, $row->owner_type, $row->owner_id);

                    continue;
                }
                $this->values->upsert($definition->id, $row->owner_type, $row->owner_id, [
                    'value_json' => $kept,
                    'value_index' => mb_substr(implode(',', $kept), 0, 191),
                ]);
            }
        });
    }

    /**
     * 필수 판정에 쓰는 컬럼을 반환합니다 (주소는 우편번호와 기본 주소).
     *
     * @param  FieldDefinition  $definition  컬럼 저장 항목
     * @return array<int, string> 컬럼명 목록
     */
    protected function requiredColumns(FieldDefinition $definition): array
    {
        $columns = $this->columnStorage->columns($definition);

        return $definition->type === 'address' ? array_slice($columns, 0, 2) : $columns;
    }
}
