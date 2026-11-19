<?php

namespace Modules\Sirsoft\Board\Fields;

use App\Contracts\Repositories\FieldValueRepositoryInterface;
use App\Fields\FieldContext;
use App\Fields\FieldFormSchema;
use App\Fields\FieldRegistry;
use App\Fields\FieldRuleFactory;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;

/**
 * 비회원 작성자 항목 창구 (항목 집합 `sirsoft-board.guest_author`).
 *
 * 비회원이 글과 댓글을 쓸 때 받는 항목을 다룬다. 이름과 비밀번호는 글과 댓글의 컬럼에 저장하는 고정 항목이라
 * 항목 엔진 밖에 두고(늘 사용, 늘 필수), 그 밖의 항목(이메일, 다른 확장이 기여한 항목, 운영자가 추가한 항목)은
 * 항목 엔진이 규칙과 저장을 맡는다.
 *
 * | 무엇 | 어디에 |
 * | --- | --- |
 * | 요청 키 | `guest_fields.{항목 키}` |
 * | 값 저장 | 공용 값 테이블. 소유자 종류는 글 `board_post`, 댓글 `board_comment` |
 * | 게시판별 사용, 필수 | `boards.guest_author_fields` (없으면 모듈 설정 `basic_defaults.guest_author_fields`, 그것도 없으면 항목 선언의 값) |
 * | 표면 | 글 `guest_post`, 댓글 `guest_comment` |
 *
 * 게시판별 설정 모양: `{항목 키: {post: {enabled, required}, comment: {enabled, required}}}`.
 *
 * @since 1.3.0
 */
class GuestAuthorFields
{
    /** 항목 집합 식별자 */
    public const SET = 'sirsoft-board.guest_author';

    /** 요청 묶음 키 */
    public const BUNDLE = 'guest_fields';

    /** 표면: 비회원 글쓰기 */
    public const SURFACE_POST = 'guest_post';

    /** 표면: 비회원 댓글 쓰기 */
    public const SURFACE_COMMENT = 'guest_comment';

    /** 값 소유자 종류: 글 */
    public const OWNER_POST = 'board_post';

    /** 값 소유자 종류: 댓글 */
    public const OWNER_COMMENT = 'board_comment';

    /** 게시판별 설정의 표면 키 => 표면 */
    public const SETTING_SURFACES = ['post' => self::SURFACE_POST, 'comment' => self::SURFACE_COMMENT];

    /** 항목 엔진 밖의 고정 항목 (글과 댓글의 컬럼. 늘 사용, 늘 필수) */
    public const FIXED_FIELDS = ['author_name', 'password'];

    /** 이 집합이 받지 않는 유형 (비회원은 파일을 올릴 소유 계정이 없다) */
    public const EXCLUDED_TYPES = ['file', 'image'];

    /**
     * 범위 키(게시판 ID)로 찾은 게시판 메모.
     *
     * @var array<string, Board|null>
     */
    protected array $boardMemo = [];

    /**
     * @param  FieldRegistry  $registry  항목 등록소
     * @param  FieldRuleFactory  $ruleFactory  항목 검증 규칙 팩토리
     * @param  FieldValueService  $valueService  항목 값 서비스
     * @param  FieldFormSchema  $schema  화면용 항목 계약
     * @param  FieldValueRepositoryInterface  $valueRepository  항목 값 저장소 (게시판 단위 파기)
     * @param  BoardRepositoryInterface  $boards  게시판 저장소
     */
    public function __construct(
        protected FieldRegistry $registry,
        protected FieldRuleFactory $ruleFactory,
        protected FieldValueService $valueService,
        protected FieldFormSchema $schema,
        protected FieldValueRepositoryInterface $valueRepository,
        protected BoardRepositoryInterface $boards,
    ) {}

    /**
     * 표면의 요청 문맥을 만듭니다.
     *
     * @param  Board  $board  게시판
     * @param  string  $surface  표면 (SURFACE_POST | SURFACE_COMMENT)
     * @param  Model|null  $owner  값의 소유자 (저장할 때의 글 또는 댓글)
     * @return FieldContext 요청 문맥 (범위 키는 게시판 ID)
     */
    public function context(Board $board, string $surface, ?Model $owner = null): FieldContext
    {
        $actor = Auth::user();

        return new FieldContext(
            surface: $surface,
            owner: $owner,
            actor: $actor instanceof User ? $actor : null,
            scopeKey: (string) $board->getKey(),
            extra: ['board' => $board],
        );
    }

    /**
     * 비회원 글 또는 댓글 요청에 더할 검증 규칙을 반환합니다.
     *
     * 고정 항목(이름, 비밀번호)의 규칙은 요청 클래스가 그대로 가진다. 여기서는 엔진 항목의 규칙만 만든다.
     *
     * @param  Board  $board  게시판
     * @param  string  $surface  표면
     * @return array<string, array<int, mixed>> 요청 속성 경로 => 규칙
     */
    public function rules(Board $board, string $surface): array
    {
        $rules = $this->ruleFactory->rules(self::SET, $this->context($board, $surface));
        if ($rules === []) {
            return [];
        }

        return [self::BUNDLE => ['nullable', 'array']] + $rules;
    }

    /**
     * 검증 오류 문구에 쓸 속성 이름을 반환합니다.
     *
     * @param  Board  $board  게시판
     * @param  string  $surface  표면
     * @return array<string, string> 요청 속성 경로 => 항목 이름
     */
    public function attributes(Board $board, string $surface): array
    {
        return $this->ruleFactory->attributes(self::SET, $this->context($board, $surface));
    }

    /**
     * 운영자가 적은 형식 오류 문구를 반환합니다.
     *
     * @param  Board  $board  게시판
     * @param  string  $surface  표면
     * @return array<string, string> `{속성 경로}.regex` => 문구
     */
    public function messages(Board $board, string $surface): array
    {
        return $this->ruleFactory->messages(self::SET, $this->context($board, $surface));
    }

    /**
     * 사용자 화면이 비회원 칸을 그릴 때 쓰는 값을 반환합니다 (게시판 응답의 `guest_fields`).
     *
     * @param  Board  $board  게시판
     * @return array{fixed: array<string, array{required: bool}>, post: array<int, array<string, mixed>>, comment: array<int, array<string, mixed>>} 고정 항목의 필수 여부와 표면별 항목 계약
     */
    public function formPayload(Board $board): array
    {
        $fixed = [];
        foreach (self::FIXED_FIELDS as $key) {
            $fixed[$key] = ['required' => true];
        }

        return [
            'fixed' => $fixed,
            'post' => $this->schema->forSurface(self::SET, $this->context($board, self::SURFACE_POST)),
            'comment' => $this->schema->forSurface(self::SET, $this->context($board, self::SURFACE_COMMENT)),
        ];
    }

    /**
     * 요청 데이터의 비회원 항목 값을 저장합니다.
     *
     * 호출자가 글 또는 댓글 저장과 같은 트랜잭션 안에서 부른다. 회원이 쓴 글과 댓글에는 저장하지 않는다.
     *
     * @param  Model  $owner  방금 저장한 글 또는 댓글 (`user_id` 가 비어 있어야 한다)
     * @param  Board  $board  게시판
     * @param  string  $surface  표면
     * @param  mixed  $input  요청의 `guest_fields` 값 (항목 키 => 값)
     * @return array<string, array{old: mixed, new: mixed}> 바뀐 항목 키 => 이전 값과 새 값
     */
    public function store(Model $owner, Board $board, string $surface, mixed $input): array
    {
        if (! is_array($input) || $input === [] || $owner->getAttribute('user_id') !== null) {
            return [];
        }

        $context = $this->context($board, $surface, $owner);
        $parts = $this->valueService->partition(self::SET, [self::BUNDLE => $input], $context);

        return $this->valueService->write($owner, self::SET, $parts['values'], $context);
    }

    /**
     * 글 또는 댓글에 저장된 비회원 항목 값을 보기용으로 반환합니다 (관리자 화면).
     *
     * @param  Collection<int, Model>  $owners  글 또는 댓글 목록 (같은 종류)
     * @return array<int|string, array<int, array{key: string, label: string, value: mixed, display_value: string|null}>> 소유자 ID => 값 목록 (값이 없는 항목은 싣지 않는다)
     */
    public function valuesFor(Collection $owners): array
    {
        $owners = $owners->filter(fn ($owner) => $owner instanceof Model && $owner->exists && $owner->getAttribute('user_id') === null)->values();
        if ($owners->isEmpty()) {
            return [];
        }

        $definitions = $this->registry->forSet(self::SET)
            ->filter(fn (FieldDefinition $definition) => $definition->storage !== FieldDefinition::STORAGE_COLUMN && $this->registry->isAvailable($definition))
            ->values();
        if ($definitions->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($this->valueService->preload($owners, self::SET, $definitions) as $ownerId => $values) {
            $rows = [];
            foreach ($definitions as $definition) {
                $value = $values[$definition->key] ?? null;
                if ($this->valueService->isEmpty($definition, $value)) {
                    continue;
                }
                $rows[] = [
                    'key' => $definition->key,
                    'label' => $definition->localized('name') ?? $definition->key,
                    'value' => $value,
                    'display_value' => $this->valueService->display($definition, $value),
                ];
            }
            if ($rows !== []) {
                $result[$ownerId] = $rows;
            }
        }

        return $result;
    }

    /**
     * 글 또는 댓글 한 건의 비회원 항목 값을 파기합니다 (보유기간 처리, 영구 삭제).
     *
     * @param  Model  $owner  글 또는 댓글
     * @return int 지운 값 행 수
     */
    public function purge(Model $owner): int
    {
        return $this->valueService->purgeOwner($owner, self::SET);
    }

    /**
     * 소유자 ID 묶음의 비회원 항목 값을 파기합니다 (게시판 삭제처럼 여러 건을 한꺼번에 지울 때).
     *
     * 값 테이블에는 게시판 ID 가 없어 소유자 ID 로만 찾을 수 있다. 묶음(호출하는 쪽이 1000건 단위로 나눈다)마다 삭제 쿼리 한 번.
     *
     * @param  string  $ownerType  소유자 종류 (OWNER_POST | OWNER_COMMENT)
     * @param  array<int, int>  $ownerIds  소유자 ID 목록
     * @return int 지운 값 행 수
     */
    public function purgeMany(string $ownerType, array $ownerIds): int
    {
        if ($ownerIds === []) {
            return 0;
        }

        return $this->valueRepository->deleteForOwners($ownerType, array_map('intval', $ownerIds));
    }

    /**
     * 게시판 설정 폼이 그릴 「비회원 작성자 항목 표」의 행을 반환합니다.
     *
     * 고정 항목(이름, 비밀번호)은 잠긴 행으로 맨 위에 둔다. 다른 확장이 기여한 항목은 출처에 그 확장 이름이 붙고,
     * 기여한 쪽이 건 잠금(`locks`)은 그 칸의 스위치를 잠근다. 운영자가 만든 항목(`is_custom`)만 지울 수 있고, 지우기와
     * 만들기는 코어 항목 API(`/api/admin/field-sets/sirsoft-board.guest_author/fields`)가 받는다.
     *
     * @param  Board|null  $board  게시판 (새 게시판이면 null. 모듈 기본값으로 채운다)
     * @return array<int, array<string, mixed>> 표의 행
     */
    public function settingsRows(?Board $board = null): array
    {
        $rows = [];
        foreach (self::FIXED_FIELDS as $key) {
            $rows[] = [
                'key' => $key,
                'label' => __('sirsoft-board::validation.attributes.post.'.$key),
                'type' => $key === 'password' ? 'password' : 'text',
                'fixed' => true,
                'available' => true,
                'source_type' => 'module',
                'source_label' => null,
                'post' => ['enabled' => true, 'required' => true, 'enabled_locked' => true, 'required_locked' => true],
                'comment' => ['enabled' => true, 'required' => true, 'enabled_locked' => true, 'required_locked' => true],
            ];
        }

        $stored = $this->storedSettings($board);
        foreach ($this->registry->forSet(self::SET) as $definition) {
            $locks = $this->registry->locksFor($definition);
            $available = $this->registry->isAvailable($definition);
            $row = [
                // 운영자가 만든 항목만 이 표에서 지울 수 있다(지우기 요청은 코어 항목 API 가 받는다)
                'id' => $definition->id,
                'is_custom' => $definition->isCustom(),
                'key' => $definition->key,
                'label' => $definition->localized('name') ?? $definition->key,
                'type' => $definition->type,
                'fixed' => false,
                // 유형을 제공한 확장이 꺼져 있으면 사용할 수 없다 (표에는 「사용 불가」로 남는다)
                'available' => $available,
                'source_type' => $definition->source_type,
                // 이 모듈이 선언한 항목은 출처를 비운다. 다른 확장이 기여한 항목만 그 확장 이름을 보인다.
                'source_label' => $definition->source_identifier === 'sirsoft-board' ? null : $this->registry->sourceLabel($definition),
            ];
            foreach (array_keys(self::SETTING_SURFACES) as $surfaceKey) {
                $effective = $this->effective($definition, $stored, $surfaceKey);
                $row[$surfaceKey] = [
                    'enabled' => $effective['enabled'],
                    'required' => $effective['required'],
                    'enabled_locked' => ! $available || array_key_exists('enabled', $locks),
                    'required_locked' => ! $available || array_key_exists('required', $locks),
                    'lock_reason' => $this->lockReason($locks),
                ];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * 모듈 기본값(`basic_defaults.guest_author_fields`)을 반환합니다 — 새 게시판이 물려받는 설정.
     *
     * 저장된 기본값이 없으면 항목 선언의 값으로 채운 설정을 돌려준다. 고정 항목은 넣지 않는다.
     *
     * @return array<string, array<string, array{enabled: bool, required: bool}>> 항목 키 => 표면별 사용, 필수
     */
    public function defaultSettings(): array
    {
        $values = [];
        foreach ($this->settingsRows(null) as $row) {
            if ($row['fixed']) {
                continue;
            }
            $values[$row['key']] = [
                'post' => ['enabled' => $row['post']['enabled'], 'required' => $row['post']['required']],
                'comment' => ['enabled' => $row['comment']['enabled'], 'required' => $row['comment']['required']],
            ];
        }

        return $values;
    }

    /**
     * 요청으로 받은 게시판별 설정을 저장할 모양으로 정리합니다.
     *
     * 이 집합에 없는 항목과 고정 항목은 버린다. 필수는 사용이 켜져 있을 때만 켜진다. 기여한 쪽이 잠근 속성은 항목 선언의 값으로 되돌린다.
     *
     * @param  mixed  $input  요청 값 (`{항목 키: {post: {enabled, required}, comment: {...}}}`)
     * @return array<string, array<string, array{enabled: bool, required: bool}>>|null 저장할 설정 (받은 것이 없으면 null)
     */
    public function normalizeSettings(mixed $input): ?array
    {
        if (! is_array($input)) {
            return null;
        }

        $settings = [];
        foreach ($this->registry->forSet(self::SET) as $definition) {
            $entry = $input[$definition->key] ?? null;
            if (! is_array($entry)) {
                continue;
            }

            $locks = $this->registry->locksFor($definition);
            foreach (array_keys(self::SETTING_SURFACES) as $surfaceKey) {
                $surface = is_array($entry[$surfaceKey] ?? null) ? $entry[$surfaceKey] : [];
                $enabled = array_key_exists('enabled', $locks)
                    ? (bool) $definition->enabled
                    : filter_var($surface['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $required = array_key_exists('required', $locks)
                    ? (bool) $definition->required
                    : filter_var($surface['required'] ?? false, FILTER_VALIDATE_BOOLEAN);

                $settings[$definition->key][$surfaceKey] = ['enabled' => $enabled, 'required' => $enabled && $required];
            }
        }

        return $settings === [] ? null : $settings;
    }

    /**
     * 게시판별 사용, 필수 설정을 항목 목록에 입힙니다 (필터 `core.field.filter_definitions` 구독자가 부른다).
     *
     * 표면과 게시판을 아는 요청에서만 입힌다. 그 밖(운영자 표, 선언 동기화)에서는 항목 선언의 값을 그대로 둔다.
     *
     * @param  Collection<int, FieldDefinition>  $definitions  항목 목록 (사본)
     * @param  string|null  $scopeKey  범위 키 (게시판 ID)
     * @param  array<string, mixed>  $context  요청 문맥 배열 (`surface`, `extra.board`)
     * @return Collection<int, FieldDefinition> 설정을 입힌 항목 목록
     */
    public function applyBoardSettings(Collection $definitions, ?string $scopeKey, array $context): Collection
    {
        $surfaceKey = array_search($context['surface'] ?? null, self::SETTING_SURFACES, true);
        if ($surfaceKey === false) {
            return $definitions;
        }

        $board = Arr::get($context, 'extra.board');
        $board = $board instanceof Board ? $board : $this->boardByScope($scopeKey);
        $stored = $this->storedSettings($board);

        return $definitions->map(function ($definition) use ($stored, $surfaceKey) {
            if (! $definition instanceof FieldDefinition) {
                return $definition;
            }

            $effective = $this->effective($definition, $stored, (string) $surfaceKey);
            $definition->enabled = $effective['enabled'];
            $definition->required = $effective['required'];

            return $definition;
        });
    }

    /**
     * 항목 한 건이 한 표면에서 쓰이는지와 필수인지 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  array<string, mixed>  $stored  게시판(또는 모듈 기본값)의 설정
     * @param  string  $surfaceKey  설정의 표면 키 (post | comment)
     * @return array{enabled: bool, required: bool} 사용과 필수
     */
    protected function effective(FieldDefinition $definition, array $stored, string $surfaceKey): array
    {
        $locks = $this->registry->locksFor($definition);
        $entry = is_array($stored[$definition->key][$surfaceKey] ?? null) ? $stored[$definition->key][$surfaceKey] : [];

        $enabled = array_key_exists('enabled', $entry) && ! array_key_exists('enabled', $locks)
            ? (bool) $entry['enabled']
            : (bool) $definition->enabled;
        $required = array_key_exists('required', $entry) && ! array_key_exists('required', $locks)
            ? (bool) $entry['required']
            : (bool) $definition->required;

        return ['enabled' => $enabled, 'required' => $enabled && $required];
    }

    /**
     * 게시판에 적용되는 설정을 반환합니다 (게시판 설정 → 모듈 기본값 → 빈 배열).
     *
     * @param  Board|null  $board  게시판
     * @return array<string, mixed> 설정
     */
    protected function storedSettings(?Board $board): array
    {
        $own = $board?->getAttribute('guest_author_fields');
        if (is_array($own)) {
            return $own;
        }

        $default = g7_module_settings('sirsoft-board', 'basic_defaults.guest_author_fields', null);

        return is_array($default) ? $default : [];
    }

    /**
     * 범위 키(게시판 ID)로 게시판을 찾습니다.
     *
     * @param  string|null  $scopeKey  범위 키
     * @return Board|null 게시판 (없으면 null)
     */
    protected function boardByScope(?string $scopeKey): ?Board
    {
        if ($scopeKey === null || ! ctype_digit($scopeKey)) {
            return null;
        }

        if (! array_key_exists($scopeKey, $this->boardMemo)) {
            $this->boardMemo[$scopeKey] = $this->boards->find((int) $scopeKey);
        }

        return $this->boardMemo[$scopeKey];
    }

    /**
     * 잠금 사유를 화면용 글자로 반환합니다.
     *
     * @param  array<string, mixed>  $locks  속성 => 사유 (번역 키)
     * @return string|null 첫 사유 (잠금이 없으면 null)
     */
    protected function lockReason(array $locks): ?string
    {
        foreach (['enabled', 'required'] as $attribute) {
            $reason = $locks[$attribute] ?? null;
            if (is_string($reason) && $reason !== '') {
                $translated = __($reason);

                return is_string($translated) ? $translated : $reason;
            }
        }

        return null;
    }
}
