<?php

namespace Modules\Sirsoft\Ecommerce\Fields;

use App\Fields\FieldContext;
use App\Fields\FieldFormSchema;
use App\Fields\FieldRegistry;
use App\Fields\FieldRuleFactory;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Support\LocalizedValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;

/**
 * 주문서 항목 창구 (항목 집합 `sirsoft-ecommerce.order_contact`).
 *
 * 주문서에서 받는 주문자와 배송지 항목을 다룬다. 항목은 세 갈래다.
 *
 * | 갈래 | 항목 | 규칙 | 저장 |
 * | --- | --- | --- | --- |
 * | 잠긴 항목 | 주문자 이름과 연락처, 수령인 이름과 연락처, 배송 주소 | 주문 요청의 고정 규칙 (운영자도 다른 확장도 사용과 필수를 바꿀 수 없다) | 주문 주소의 컬럼 |
 * | 설정 가능한 기본 항목 | 주문자 이메일, 상세 주소, 배송 메모 | 운영자가 정한 사용과 필수를 항목 엔진에서 받아 기존 요청 키에 건다 | 주문 주소의 컬럼 (종전 코드가 저장) |
 * | 추가 항목 | 운영자가 추가한 항목, 다른 확장이 기여한 항목 | 항목 엔진 | 공용 값 테이블 또는 기여 확장의 핸들러 |
 *
 * 항목 엔진은 항목 키를 요청 속성 경로로 쓰지만 기본 항목의 요청 키는 종전 그대로다(`orderer.email` 등).
 * 그 대응은 {@see self::REQUEST_PATHS} 한 곳에 둔다. 추가 항목의 요청 키는 `extra_fields.{항목 키}` 다.
 *
 * 비회원 주문의 주문자 이메일은 설정과 무관하게 늘 사용, 늘 필수다(주문 조회와 알림의 유일한 통로).
 *
 * @since 1.3.0
 */
class OrderContactFields
{
    /** 항목 집합 식별자 */
    public const SET = 'sirsoft-ecommerce.order_contact';

    /** 추가 항목의 요청 묶음 키 */
    public const BUNDLE = 'extra_fields';

    /**
     * 주문 행 `order_meta` 안에서 추가 항목 스냅숏을 두는 키.
     *
     * 주문을 저장할 때 추가 항목의 이름(언어별)과 보기용 글자를 남긴다. 운영자가 그 항목을 지우면 값 행도 함께 지워지는데,
     * 주문은 기록물이라 지난 주문 상세에는 그 항목이 계속 보여야 한다. 익명화와 파기는 스냅숏도 지운다.
     */
    public const SNAPSHOT_META_KEY = 'extra_fields_snapshot';

    /** 표면: 주문서 */
    public const SURFACE = 'checkout';

    /** 값 소유자 종류: 주문 */
    public const OWNER_TYPE = 'ecommerce_order';

    /** 화면 구획: 주문자 */
    public const GROUP_ORDERER = 'orderer';

    /** 화면 구획: 배송지 */
    public const GROUP_SHIPPING = 'shipping';

    /** 이 집합이 받는 유형 (주문서에는 파일을 올릴 자리가 없다. 파일과 이미지를 뺀 코어 유형) */
    public const ALLOWED_TYPES = [
        'text', 'textarea', 'number', 'boolean', 'date', 'datetime', 'date_range',
        'choice', 'multi_choice', 'phone', 'email', 'url', 'address',
    ];

    /** 잠긴 항목 (엔진 밖 고정 규칙. 늘 사용, 늘 필수) */
    public const LOCKED_FIELDS = ['orderer_name', 'orderer_phone', 'recipient_name', 'recipient_phone', 'address'];

    /** 설정 가능한 기본 항목의 항목 키 => 주문 요청 경로 */
    public const REQUEST_PATHS = [
        'orderer_email' => 'orderer.email',
        'address_detail' => 'shipping.address_detail',
        'shipping_memo' => 'shipping_memo',
    ];

    /**
     * 설정 가능한 기본 항목의 기본값과 종전 규칙.
     *
     * 항목 정의 행이 아직 없는 구간(모듈을 갱신하기 전, 선언 동기화 전)에는 이 값으로 종전과 같게 동작한다.
     * `rule` 은 그 구간에 그대로 쓰는 종전 규칙이고 모양(문자열 또는 배열)도 종전과 같다.
     *
     * @var array<string, array{enabled: bool, required: bool, rule: string|array<int, string>}>
     */
    protected const DEFAULTS = [
        'orderer_email' => ['enabled' => true, 'required' => false, 'rule' => ['nullable', 'email', 'max:255']],
        // 해외 주소 폼에는 상세 주소가 없다. 해외 주소 1행이 없을 때만 필수다.
        'address_detail' => ['enabled' => true, 'required' => true, 'rule' => 'required_without:shipping.address_line_1|nullable|string|max:255'],
        'shipping_memo' => ['enabled' => true, 'required' => false, 'rule' => 'nullable|string|max:500'],
    ];

    /**
     * @param  FieldRegistry  $registry  항목 등록소
     * @param  FieldRuleFactory  $ruleFactory  항목 검증 규칙 팩토리
     * @param  FieldValueService  $valueService  항목 값 서비스
     * @param  FieldFormSchema  $schema  화면용 항목 계약
     * @param  OrderRepositoryInterface  $orders  주문 저장소 (지운 항목 값 스냅숏)
     */
    public function __construct(
        protected FieldRegistry $registry,
        protected FieldRuleFactory $ruleFactory,
        protected FieldValueService $valueService,
        protected FieldFormSchema $schema,
        protected OrderRepositoryInterface $orders,
    ) {}

    /**
     * 주문서 표면의 요청 문맥을 만듭니다.
     *
     * @param  User|null  $actor  주문하는 회원 (비회원은 null)
     * @param  Model|null  $owner  값의 소유자 (저장할 때의 주문)
     * @return FieldContext 요청 문맥 (`extra.is_guest` 로 비회원 주문인지 알린다)
     */
    public function context(?User $actor, ?Model $owner = null): FieldContext
    {
        return new FieldContext(
            surface: self::SURFACE,
            owner: $owner,
            actor: $actor,
            extra: ['is_guest' => $actor === null],
        );
    }

    /**
     * 설정 가능한 기본 항목 3개의 사용과 필수를 반환합니다.
     *
     * @param  User|null  $actor  주문하는 회원 (비회원은 null)
     * @return array<string, array{enabled: bool, required: bool}> 항목 키 => 사용과 필수
     */
    public function builtin(?User $actor): array
    {
        $definitions = $this->definitions($actor);

        $builtin = [];
        foreach (self::DEFAULTS as $key => $default) {
            $definition = $definitions->get($key);
            $enabled = $definition instanceof FieldDefinition
                ? (bool) $definition->enabled && $this->registry->isAvailable($definition)
                : $default['enabled'];
            $required = $definition instanceof FieldDefinition ? (bool) $definition->required : $default['required'];

            $builtin[$key] = ['enabled' => $enabled, 'required' => $enabled && $required];
        }

        // 비회원 주문의 이메일은 주문 조회와 알림의 유일한 통로라 설정과 무관하게 받는다.
        if ($actor === null) {
            $builtin['orderer_email'] = ['enabled' => true, 'required' => true];
        }

        return $builtin;
    }

    /**
     * 주문 요청에 걸 항목 규칙을 반환합니다 (설정 가능한 기본 항목 + 추가 항목).
     *
     * 잠긴 항목의 규칙은 주문 요청 클래스가 그대로 가진다. 꺼진 항목은 규칙에서 빠진다.
     *
     * | 항목 | 조건 | 규칙 |
     * | --- | --- | --- |
     * | 주문자 이메일 | 비회원 | 늘 `required` |
     * | 주문자 이메일 | 회원 | 필수면 `required`, 아니면 `nullable` |
     * | 상세 주소 | 필수 | `required_without:shipping.address_line_1` (해외 주소 폼에는 상세 주소가 없다) |
     * | 상세 주소 | 선택 | `nullable` |
     * | 배송 메모 | 필수 | `required` |
     * | 추가 항목 | 필수 | `extra_fields.{키}` 에 `required` (화면은 빈 값의 키를 보내지 않는다) |
     *
     * @param  User|null  $actor  주문하는 회원 (비회원은 null)
     * @return array<string, mixed> 요청 속성 경로 => 규칙
     */
    public function rules(?User $actor): array
    {
        $context = $this->context($actor);
        $definitions = $this->definitions($actor);
        $factoryRules = $definitions->isEmpty() ? [] : $this->ruleFactory->rules(self::SET, $context);

        $rules = [];
        foreach (self::REQUEST_PATHS as $key => $path) {
            $rule = $this->builtinRule($key, $definitions->get($key), $factoryRules[$key] ?? null, $actor);
            if ($rule !== null) {
                $rules[$path] = $rule;
            }
        }

        $extra = array_filter(
            $factoryRules,
            fn (string $attribute) => str_starts_with($attribute, self::BUNDLE.'.'),
            ARRAY_FILTER_USE_KEY,
        );
        if ($extra !== []) {
            $rules[self::BUNDLE] = ['nullable', 'array'];
            $rules += $extra;
        }

        return $rules;
    }

    /**
     * 검증 오류 문구에 쓸 추가 항목의 이름을 반환합니다.
     *
     * @param  User|null  $actor  주문하는 회원 (비회원은 null)
     * @return array<string, string> 요청 속성 경로 => 항목 이름
     */
    public function attributes(?User $actor): array
    {
        return $this->ruleFactory->attributes(self::SET, $this->context($actor), skipColumns: true);
    }

    /**
     * 운영자가 추가 항목에 적은 형식 오류 문구를 반환합니다.
     *
     * @param  User|null  $actor  주문하는 회원 (비회원은 null)
     * @return array<string, string> `{속성 경로}.regex` => 문구
     */
    public function messages(?User $actor): array
    {
        return array_filter(
            $this->ruleFactory->messages(self::SET, $this->context($actor)),
            fn (string $attribute) => str_starts_with($attribute, self::BUNDLE.'.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * 주문서 화면이 항목을 그릴 때 쓰는 값을 반환합니다 (주문서 응답의 `order_fields`).
     *
     * 잠긴 항목과 설정 가능한 기본 항목은 화면이 종전 칸으로 그리므로 목록에 넣지 않는다.
     * 유형을 쓸 수 없는 항목도 뺀다.
     *
     * @param  User|null  $actor  주문하는 회원 (비회원은 null)
     * @return array{fields_version: string, builtin: array<string, array{enabled: bool, required: bool}>, orderer: array<int, array<string, mixed>>, shipping: array<int, array<string, mixed>>} 정의 버전, 기본 항목의 사용과 필수, 구획별 추가 항목 계약
     */
    public function formPayload(?User $actor): array
    {
        $groups = [self::GROUP_ORDERER => [], self::GROUP_SHIPPING => []];

        foreach ($this->schema->forSurface(self::SET, $this->context($actor)) as $field) {
            if (! str_starts_with((string) $field['name'], self::BUNDLE.'.') || $field['renderer'] === 'unsupported') {
                continue;
            }
            $groups[$this->sectionOf((array) $field['ui'])][] = $field;
        }

        return [
            'fields_version' => $this->registry->version(self::SET),
            'builtin' => $this->builtin($actor),
            self::GROUP_ORDERER => $groups[self::GROUP_ORDERER],
            self::GROUP_SHIPPING => $groups[self::GROUP_SHIPPING],
        ];
    }

    /**
     * 주문 요청의 추가 항목 값을 저장합니다.
     *
     * 호출자가 주문 저장과 같은 트랜잭션 안에서 부른다. 값 테이블 저장 항목과 핸들러 저장 항목이 같은 길로 간다.
     *
     * @param  Order  $order  방금 저장한 주문
     * @param  mixed  $input  요청의 `extra_fields` 값 (항목 키 => 값)
     * @param  User|null  $actor  주문하는 회원 (비회원은 null)
     * @return array<string, array{old: mixed, new: mixed}> 저장한 항목 키 => 이전 값과 새 값
     */
    public function store(Order $order, mixed $input, ?User $actor): array
    {
        if (! is_array($input) || $input === []) {
            return [];
        }

        $context = $this->context($actor, $order);
        $parts = $this->valueService->partition(self::SET, [self::BUNDLE => $input], $context);
        $written = $this->valueService->write($order, self::SET, $parts['values'], $context);

        $this->rememberSnapshot($order);

        return $written;
    }

    /**
     * 주문에 저장된 추가 항목의 이름과 보기용 글자를 주문 행에 스냅숏으로 남깁니다.
     *
     * 항목을 나중에 지워도 지난 주문 상세가 그 항목을 그릴 수 있게 한다(`valuesFor()`).
     *
     * @param  Order  $order  방금 값을 저장한 주문
     * @return void 반환값 없음
     */
    protected function rememberSnapshot(Order $order): void
    {
        $snapshot = [];
        foreach ($this->liveRows($order) as $row) {
            $snapshot[] = [
                'key' => $row['key'],
                'name' => $row['name'],
                'section' => $row['section'],
                'display_value' => $row['display_value'],
            ];
        }
        if ($snapshot === []) {
            return;
        }

        $meta = is_array($order->order_meta) ? $order->order_meta : [];
        $meta[self::SNAPSHOT_META_KEY] = $snapshot;
        $this->orders->update($order, ['order_meta' => $meta]);
    }

    /**
     * 주문에 저장된 추가 항목 값을 보기용으로 반환합니다 (주문 응답의 `extra_fields`).
     *
     * 주문 한 건의 응답에서만 부른다(목록 응답에는 싣지 않는다). 추가 항목이 하나도 없는 사이트에서는 값 테이블을 읽지 않는다.
     *
     * @param  Order  $order  주문
     * @return array<int, array{key: string, label: string, section: string, display_value: string|null}> 값이 있는 항목 목록 (표시 순서)
     */
    public function valuesFor(Order $order): array
    {
        if (! $order->exists) {
            return [];
        }

        $rows = array_map(
            fn (array $row) => ['key' => $row['key'], 'label' => $row['label'], 'section' => $row['section'], 'display_value' => $row['display_value']],
            $this->liveRows($order),
        );

        // 운영자가 지운 항목 — 값 행은 함께 지워졌지만 주문을 저장할 때 남긴 스냅숏으로 그린다(주문은 기록물)
        $snapshot = (is_array($order->order_meta) ? $order->order_meta : [])[self::SNAPSHOT_META_KEY] ?? [];
        if (is_array($snapshot) && $snapshot !== []) {
            $declared = $this->registry->forSet(self::SET)->pluck('key')->all();
            foreach ($snapshot as $entry) {
                $key = is_array($entry) ? (string) ($entry['key'] ?? '') : '';
                if ($key === '' || in_array($key, $declared, true)) {
                    continue;
                }
                $rows[] = [
                    'key' => $key,
                    'label' => LocalizedValue::pick(is_array($entry['name'] ?? null) ? $entry['name'] : [], app()->getLocale()) ?? $key,
                    'section' => (string) ($entry['section'] ?? 'shipping'),
                    'display_value' => isset($entry['display_value']) ? (string) $entry['display_value'] : null,
                ];
            }
        }

        return $rows;
    }

    /**
     * 지금 있는 항목 정의로 주문의 추가 항목 값을 읽습니다 (스냅숏에 남길 언어별 이름 포함).
     *
     * 주문 뒤에 운영자가 끈 항목도 주문 기록이므로 읽는다. 유형을 쓸 수 없는 항목은 글자로 바꿀 수 없어 뺀다.
     * 추가 항목이 하나도 없는 사이트에서는 값 테이블을 읽지 않는다.
     *
     * @param  Order  $order  주문
     * @return array<int, array{key: string, name: array<string, string>, label: string, section: string, display_value: string|null}> 값이 있는 항목 목록 (표시 순서)
     */
    protected function liveRows(Order $order): array
    {
        $definitions = $this->registry->forSet(self::SET)
            ->filter(fn (FieldDefinition $definition) => $definition->storage !== FieldDefinition::STORAGE_COLUMN
                && $this->registry->isAvailable($definition))
            ->values();
        if ($definitions->isEmpty()) {
            return [];
        }

        $values = $this->valueService->read($order, self::SET, $definitions);

        $rows = [];
        foreach ($definitions as $definition) {
            $value = $values[$definition->key] ?? null;
            if ($this->valueService->isEmpty($definition, $value)) {
                continue;
            }
            $rows[] = [
                'key' => $definition->key,
                'name' => is_array($definition->name) ? $definition->name : [],
                'label' => $definition->localized('name') ?? $definition->key,
                'section' => $this->sectionOf($definition->ui ?? []),
                'display_value' => $this->valueService->display($definition, $value),
            ];
        }

        return $rows;
    }

    /**
     * 주문 한 건의 추가 항목 값을 파기합니다 (보유기간 처리, 주문 삭제).
     *
     * @param  Order  $order  주문
     * @return int 지운 값 행 수
     */
    public function purge(Order $order): int
    {
        $removed = $this->valueService->purgeOwner($order, self::SET);

        // 스냅숏도 같은 자유 입력 사본이라 지운다. 익명화는 주문 메타를 고칠 때 스냅숏을 이미 빼 두므로(수정 시각 보존)
        // 여기서는 남은 경우(주문 삭제 경로)에만 키를 뺀다.
        $fresh = $this->orders->find((int) $order->id);
        $meta = $fresh !== null && is_array($fresh->order_meta) ? $fresh->order_meta : null;
        if ($meta !== null && array_key_exists(self::SNAPSHOT_META_KEY, $meta)) {
            unset($meta[self::SNAPSHOT_META_KEY]);
            $this->orders->update($fresh, ['order_meta' => $meta]);
        }

        return $removed;
    }

    /**
     * 집합의 모든 항목을 키로 찾을 수 있게 반환합니다 (꺼진 항목 포함).
     *
     * @param  User|null  $actor  주문하는 회원 (비회원은 null)
     * @return Collection<string, FieldDefinition> 항목 키 => 항목 (집합이 아직 등록되지 않았으면 빈 목록)
     */
    protected function definitions(?User $actor): Collection
    {
        return $this->registry->forSet(self::SET, null, $this->context($actor))->keyBy('key');
    }

    /**
     * 설정 가능한 기본 항목 한 건의 주문 요청 규칙을 만듭니다.
     *
     * 존재 규칙(`required`, `nullable`)과 유형 규칙은 팩토리에서 받고, 규칙의 모양(문자열 또는 배열)은 종전 요청과 같게 둔다.
     * 기본 설정에서는 종전 규칙과 글자까지 같다.
     *
     * @param  string  $key  항목 키
     * @param  FieldDefinition|null  $definition  항목 정의 (아직 동기화되지 않았으면 null)
     * @param  mixed  $factoryRule  팩토리가 돌려준 규칙 (꺼진 항목이면 null)
     * @param  User|null  $actor  주문하는 회원 (비회원은 null)
     * @return string|array<int, mixed>|null 규칙 (꺼져 있어 받지 않으면 null)
     */
    protected function builtinRule(string $key, ?FieldDefinition $definition, mixed $factoryRule, ?User $actor): string|array|null
    {
        if ($key === 'orderer_email' && $actor === null) {
            return ['required', 'email', 'max:255'];
        }
        if (! $definition instanceof FieldDefinition) {
            return self::DEFAULTS[$key]['rule'];
        }
        if (! is_array($factoryRule) || $factoryRule === []) {
            return null;
        }

        $required = in_array('required', $factoryRule, true);
        $typeRules = array_values(array_filter($factoryRule, fn ($rule) => ! in_array($rule, ['required', 'nullable'], true)));

        if ($key === 'orderer_email') {
            // `email` 규칙이 글자인지도 함께 보므로 `string` 은 종전처럼 싣지 않는다.
            return [$required ? 'required' : 'nullable', ...array_values(array_filter($typeRules, fn ($rule) => $rule !== 'string'))];
        }

        $presence = match (true) {
            ! $required => ['nullable'],
            $key === 'address_detail' => ['required_without:shipping.address_line_1', 'nullable'],
            default => ['required'],
        };
        $rule = [...$presence, ...$typeRules];

        // 종전 규칙은 문자열이다. 필터가 객체나 클로저 규칙을 더했으면 배열로 둔다.
        return array_filter($rule, fn ($part) => ! is_string($part)) === [] ? implode('|', $rule) : $rule;
    }

    /**
     * 항목이 그려질 화면 구획을 반환합니다.
     *
     * @param  array<string, mixed>  $ui  항목의 화면 배치 값
     * @return string `orderer` 또는 `shipping` (구획을 정하지 않은 항목은 배송지 구획)
     */
    protected function sectionOf(array $ui): string
    {
        return ($ui['group'] ?? null) === self::GROUP_ORDERER ? self::GROUP_ORDERER : self::GROUP_SHIPPING;
    }
}
