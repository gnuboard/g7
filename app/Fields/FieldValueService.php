<?php

namespace App\Fields;

use App\Contracts\Repositories\FieldValueRepositoryInterface;
use App\Fields\Contracts\FieldTypeInterface;
use App\Fields\Contracts\FieldValueHandlerInterface;
use App\Fields\Storage\ColumnFieldStorage;
use App\Fields\Storage\FieldFileStorage;
use App\Fields\Storage\ValueTableFieldStorage;
use App\Models\FieldDefinition;
use App\Models\FieldValue;
use App\Models\User;
use App\Services\RequiredFieldsChecker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 항목 값 읽기·쓰기·선적재·파기.
 *
 * 저장 방식 세 가지(컬럼, 확장 핸들러, 공용 값 테이블)를 한 창구로 묶는다. 컬럼 값은 이 서비스가 저장하지 않고
 * 소유자 생성·수정 데이터로 돌려준다(소유자와 한 번에 저장되도록).
 *
 * @since 7.2.0
 */
class FieldValueService
{
    /**
     * @param  FieldRegistry  $registry  항목 등록소
     * @param  FieldValueRepositoryInterface  $values  값 저장소
     * @param  ColumnFieldStorage  $columnStorage  컬럼 저장소
     * @param  ValueTableFieldStorage  $valueStorage  값 테이블 저장소
     * @param  FieldFileStorage  $fileStorage  파일 항목 저장소 (첨부파일)
     */
    public function __construct(
        protected FieldRegistry $registry,
        protected FieldValueRepositoryInterface $values,
        protected ColumnFieldStorage $columnStorage,
        protected ValueTableFieldStorage $valueStorage,
        protected FieldFileStorage $fileStorage,
    ) {}

    /**
     * 요청 데이터에서 표면의 항목 값을 꺼내 저장 방식별로 나눕니다.
     *
     * 요청에 속성이 실린 항목만 다룬다(실리지 않은 항목은 건드리지 않는다 — 부분 수정).
     *
     * @param  string  $set  집합 식별자
     * @param  array<string, mixed>  $data  검증을 통과한 요청 데이터
     * @param  FieldContext  $context  요청 문맥
     * @return array{columns: array<string, mixed>, values: array<string, mixed>} columns 는 컬럼명 => 값(소유자 데이터에 합친다), values 는 항목 키 => 정규화 값(write 에 넘긴다)
     */
    public function partition(string $set, array $data, FieldContext $context): array
    {
        $columns = [];
        $values = [];

        foreach ($this->registry->forSurface($set, $context) as $definition) {
            $type = $this->registry->typeOf($definition);
            if ($type === null) {
                continue;
            }

            $attribute = $this->registry->attributeFor($definition);
            if (! $this->hasInput($type, $definition, $attribute, $data)) {
                continue;
            }

            $value = $type->normalize($definition, $type->extract($definition, $attribute, $data));

            if ($definition->storage === FieldDefinition::STORAGE_COLUMN) {
                $columns = array_merge($columns, $this->columnStorage->toColumns($definition, $value));
            } else {
                $values[$definition->key] = $value;
            }
        }

        return ['columns' => $columns, 'values' => $values];
    }

    /**
     * 컬럼이 아닌 항목 값을 저장합니다.
     *
     * 호출자가 소유자 저장과 같은 트랜잭션 안에서 부른다.
     *
     * @param  Model  $owner  값의 소유자 (저장되어 ID 가 있어야 한다)
     * @param  string  $set  집합 식별자
     * @param  array<string, mixed>  $values  항목 키 => 정규화 값 (partition 의 values)
     * @param  FieldContext  $context  요청 문맥
     * @return array<string, array{old: mixed, new: mixed}> 바뀐 항목 키 => 이전·새 값
     */
    public function write(Model $owner, string $set, array $values, FieldContext $context): array
    {
        if ($values === []) {
            return [];
        }

        $definitions = $this->registry->active($set, $context->scopeKey, $context)
            ->filter(fn (FieldDefinition $definition) => array_key_exists($definition->key, $values)
                && $definition->storage !== FieldDefinition::STORAGE_COLUMN)
            ->values();
        if ($definitions->isEmpty()) {
            return [];
        }

        $current = $this->read($owner, $set, $definitions);
        $ownerType = $this->ownerTypeOf($owner);
        $changes = [];

        foreach ($definitions as $definition) {
            $type = $this->registry->typeOf($definition);
            if ($type === null) {
                continue;
            }

            $new = $values[$definition->key];
            $old = $current[$definition->key] ?? null;
            if ($this->sameValue($old, $new)) {
                continue;
            }

            if ($definition->storage === FieldDefinition::STORAGE_HANDLER) {
                $this->handlerFor($definition)?->write($owner, $definition, $new, $context);
            } else {
                $this->valueStorage->write($definition, $type, $ownerType, (int) $owner->getKey(), $new);
            }

            $changes[$definition->key] = ['old' => $old, 'new' => $new];
        }

        // 회원 값이 바뀌었으면 비어 있는 필수 항목 판정 캐시를 버린다.
        if ($changes !== [] && $owner instanceof User && $set === FieldSet::CORE_USER) {
            app(RequiredFieldsChecker::class)->forget($owner);
        }

        return $changes;
    }

    /**
     * 소유자 한 명(건)의 항목 값을 읽습니다.
     *
     * @param  Model  $owner  소유자
     * @param  string  $set  집합 식별자
     * @param  Collection<int, FieldDefinition>|null  $definitions  읽을 항목 (null 이면 사용 중인 전부)
     * @return array<string, mixed> 항목 키 => 값
     */
    public function read(Model $owner, string $set, ?Collection $definitions = null): array
    {
        return $this->preload(new Collection([$owner]), $set, $definitions)[$owner->getKey()] ?? [];
    }

    /**
     * 소유자 여럿의 항목 값을 한 번에 읽습니다 (값 테이블은 쿼리 한 번).
     *
     * @param  Collection<int, Model>  $owners  소유자 목록 (같은 종류)
     * @param  string  $set  집합 식별자
     * @param  Collection<int, FieldDefinition>|null  $definitions  읽을 항목 (null 이면 사용 중인 전부)
     * @return array<int|string, array<string, mixed>> 소유자 ID => (항목 키 => 값)
     */
    public function preload(Collection $owners, string $set, ?Collection $definitions = null): array
    {
        $owners = $owners->filter(fn ($owner) => $owner instanceof Model && $owner->exists)->values();
        if ($owners->isEmpty()) {
            return [];
        }

        $definitions ??= $this->registry->active($set);
        // 파일·이미지 항목의 값은 값 테이블이 아니라 첨부파일이다 — 따로 한 번에 읽는다.
        $fileDefinitions = $definitions->filter(fn (FieldDefinition $definition) => $this->isFileField($definition))->values();
        $fileIndex = $this->fileStorage->read($owners, $fileDefinitions);
        $valueDefinitions = $definitions->filter(fn (FieldDefinition $definition) => $definition->storage === FieldDefinition::STORAGE_VALUE
            && ! $this->isFileField($definition));

        $rows = $valueDefinitions->isEmpty()
            ? new Collection
            : $this->values->forOwners(
                $this->ownerTypeOf($owners->first()),
                $owners->map(fn (Model $owner) => (int) $owner->getKey())->all(),
                $valueDefinitions->pluck('id')->all(),
            );
        $rowIndex = [];
        foreach ($rows as $row) {
            $rowIndex[$row->owner_id][$row->field_definition_id] = $row;
        }

        $result = [];
        foreach ($owners as $owner) {
            $ownerValues = [];
            foreach ($definitions as $definition) {
                $type = $this->registry->typeOf($definition);
                if ($type === null) {
                    continue;
                }

                if ($this->isFileField($definition)) {
                    $ownerValues[$definition->key] = $fileIndex[$owner->getKey()][$definition->key] ?? [];

                    continue;
                }

                $ownerValues[$definition->key] = match ($definition->storage) {
                    FieldDefinition::STORAGE_COLUMN => $this->columnStorage->read($owner, $definition),
                    FieldDefinition::STORAGE_HANDLER => $this->readFromHandler($owner, $definition),
                    default => $this->valueStorage->hydrate($definition, $type, $rowIndex[$owner->getKey()][$definition->id] ?? null),
                };
            }
            $result[$owner->getKey()] = $ownerValues;
        }

        return $result;
    }

    /**
     * 값이 첨부파일인 항목(파일, 이미지와 그 유형을 이어받은 확장 유형)인지 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return bool 파일 항목이면 true
     */
    public function isFileField(FieldDefinition $definition): bool
    {
        return $this->registry->typeOf($definition)?->renderer() === 'file';
    }

    /**
     * 값이 비었는지 반환합니다 (필수 판정 기준).
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $value  값
     * @return bool 비었으면 true
     */
    public function isEmpty(FieldDefinition $definition, mixed $value): bool
    {
        return $this->registry->typeOf($definition)?->isEmpty($value) ?? true;
    }

    /**
     * 보기용 문자열을 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $value  값
     * @param  string|null  $locale  로케일
     * @return string|null 보기용 문자열
     */
    public function display(FieldDefinition $definition, mixed $value, ?string $locale = null): ?string
    {
        return $this->registry->typeOf($definition)?->display($definition, $value, $locale);
    }

    /**
     * 중복 금지 항목의 값이 다른 소유자와 겹치는지 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의 (값 테이블 저장)
     * @param  mixed  $value  정규화한 값
     * @param  Model|null  $owner  제외할 소유자 (수정 중인 본인)
     * @return bool 겹치면 true
     */
    public function hasUniqueConflict(FieldDefinition $definition, mixed $value, ?Model $owner = null): bool
    {
        if (! $definition->constraint('unique', false) || $definition->storage !== FieldDefinition::STORAGE_VALUE) {
            return false;
        }

        $key = $this->registry->typeOf($definition)?->uniqueKey($definition, $value);
        if ($key === null) {
            return false;
        }

        return $this->values->uniqueKeyExists(
            $definition->id,
            $key,
            $owner?->exists ? $this->ownerTypeOf($owner) : null,
            $owner?->exists ? (int) $owner->getKey() : null,
        );
    }

    /**
     * 소유자의 항목 값을 모두 파기합니다 (탈퇴 회원 파기, 소유자 삭제).
     *
     * 값 테이블의 행을 지우고, 핸들러 저장 항목은 각 핸들러의 purge 를 부른다.
     *
     * @param  Model  $owner  소유자
     * @param  string  $set  집합 식별자
     * @return int 지운 값 테이블 행 수
     */
    public function purgeOwner(Model $owner, string $set): int
    {
        foreach ($this->registry->forSet($set) as $definition) {
            if ($definition->storage !== FieldDefinition::STORAGE_HANDLER) {
                continue;
            }
            try {
                $this->handlerFor($definition)?->purge($owner, $definition);
            } catch (\Throwable $e) {
                Log::warning('항목 값 파기: 핸들러 실패', ['field' => $definition->key, 'error' => $e->getMessage()]);
            }
        }

        return $this->values->deleteForOwner($this->ownerTypeOf($owner), (int) $owner->getKey());
    }

    /**
     * 모델의 소유자 종류 문자열을 반환합니다.
     *
     * 회원은 `user`. 그 밖의 모델은 `fieldOwnerType()` 메서드로 정한다(게시글 `board_post`, 주문 `ecommerce_order` 등).
     *
     * @param  Model  $owner  소유자
     * @return string 소유자 종류
     */
    public function ownerTypeOf(Model $owner): string
    {
        if ($owner instanceof User) {
            return FieldValue::OWNER_USER;
        }
        if (method_exists($owner, 'fieldOwnerType')) {
            return (string) $owner->fieldOwnerType();
        }

        return $owner->getMorphClass();
    }

    /**
     * 항목의 확장 핸들러를 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return FieldValueHandlerInterface|null 핸들러 (클래스가 없거나 계약을 구현하지 않으면 null)
     */
    public function handlerFor(FieldDefinition $definition): ?FieldValueHandlerInterface
    {
        $class = $definition->storage_target;
        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            return null;
        }

        $handler = app($class);

        return $handler instanceof FieldValueHandlerInterface ? $handler : null;
    }

    /**
     * 요청에 이 항목의 속성이 실렸는지 반환합니다.
     *
     * @param  FieldTypeInterface  $type  유형
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string  $attribute  요청 속성 경로
     * @param  array<string, mixed>  $data  요청 데이터
     * @return bool 하나라도 실렸으면 true
     */
    protected function hasInput(FieldTypeInterface $type, FieldDefinition $definition, string $attribute, array $data): bool
    {
        foreach ($type->inputPaths($definition, $attribute) as $path) {
            if (Arr::has($data, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 핸들러에서 값을 읽습니다 (핸들러 오류가 화면 전체를 깨지 않게 감싼다).
     *
     * @param  Model  $owner  소유자
     * @param  FieldDefinition  $definition  항목 정의
     * @return mixed 값
     */
    protected function readFromHandler(Model $owner, FieldDefinition $definition): mixed
    {
        try {
            return $this->handlerFor($definition)?->read($owner, $definition);
        } catch (\Throwable $e) {
            Log::warning('항목 값 읽기: 핸들러 실패', ['field' => $definition->key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 두 값이 같은지 반환합니다 (배열은 내용 비교, 숫자 문자열과 숫자는 같은 값).
     *
     * @param  mixed  $old  이전 값
     * @param  mixed  $new  새 값
     * @return bool 같으면 true
     */
    protected function sameValue(mixed $old, mixed $new): bool
    {
        if (is_array($old) || is_array($new)) {
            return json_encode($old) === json_encode($new);
        }
        if (($old === null || $old === '') && ($new === null || $new === '')) {
            return true;
        }
        if (is_bool($old) || is_bool($new)) {
            return $old === $new;
        }

        return (string) $old === (string) $new;
    }
}
