# 항목 집합과 항목 선언

> 가입 화면, 내 정보, 관리자 회원 폼처럼 「입력 항목이 모이는 자리」의 검증 규칙, 저장, 운영자 표를 코어 항목 엔진(`App\Fields`)이 맡는다.
> 이 문서는 확장이 그 자리에 항목을 선언하고, 자기 집합과 입력 유형을 더하고, 값을 자기 테이블에 두는 방법을 설명한다.
> 운영자 표의 API 는 [항목 집합 API](../backend/api/field-sets.md)에 있다.

## TL;DR

- 항목은 `module.php` 또는 `plugin.php` 의 `getFields()` 로 선언한다. 선언은 `field_definitions` 행으로 동기화되고 운영자가 바꾼 값은 보존된다.
- 회원 항목은 코어 집합 `core.user` 에 선언한다. 자기 자리가 필요하면 `getFieldSets()` 로 집합을 더한다.
- 값을 자기 테이블에 두려면 `storage` 를 `handler` 로 하고 `storage_target` 에 `App\Fields\Contracts\FieldValueHandlerInterface` 구현 클래스명을 적는다.
- 검증 규칙은 `App\Fields\FieldRuleFactory` 한 곳이 만든다. 요청 클래스에 항목 규칙을 따로 적지 않는다.
- 운영자가 만든 항목(`source_type = custom`)은 어떤 동기화도 지우지 않는다.
- 선언 코드에서 파일을 읽을 때는 `__DIR__` 대신 `$this->getModulePath()`(플러그인은 `$this->getPluginPath()`)를 쓴다.

## 목차

- [구성](#구성)
- [집합](#집합)
- [항목 선언](#항목-선언)
- [저장 방식](#저장-방식)
- [핸들러 저장](#핸들러-저장)
- [유형](#유형)
- [표면과 문맥](#표면과-문맥)
- [검증 규칙](#검증-규칙)
- [동기화](#동기화)
- [교차 기여](#교차-기여)
- [기존 회원 입력 요구](#기존-회원-입력-요구)
- [본인인증 값 연결](#본인인증-값-연결)
- [훅](#훅)
- [번들 확장의 예](#번들-확장의-예)
- [관련 문서](#관련-문서)

## 구성

| 부분 | 위치 | 하는 일 |
| --- | --- | --- |
| 집합 | `App\Fields\FieldSet`, `App\Fields\FieldSetRegistry` | 항목이 모이는 자리의 선언. 요청마다 등록소가 코어 선언과 켜진 확장의 선언을 모은다 |
| 항목 정의 | `App\Models\FieldDefinition` (`field_definitions` 테이블) | 선언 항목과 운영자가 만든 항목 한 건 |
| 항목 등록소 | `App\Fields\FieldRegistry` | 집합의 항목을 지금 쓸 수 있는 모습으로 돌려준다. 꺼진 확장의 항목을 빼고 필터를 태운다 |
| 유형 | `App\Fields\Contracts\FieldTypeInterface`, `App\Fields\FieldTypeRegistry` | 검증 규칙, 정규화, 직렬화, 보기용 문자열 |
| 규칙 | `App\Fields\FieldRuleFactory` | 표면의 검증 규칙을 만드는 한 곳 |
| 값 | `App\Fields\FieldValueService` | 저장 방식 세 가지의 읽기, 쓰기, 파기 |
| 화면 계약 | `App\Fields\FieldFormSchema` | 화면이 입력칸을 그릴 때 받는 항목 계약 |
| 문맥 | `App\Fields\FieldContext` | 표면, 값의 소유자, 요청한 사용자 |
| 동기화 | `App\Extension\Helpers\FieldDefinitionSyncHelper` | 선언을 `field_definitions` 로 동기화 |

## 집합

집합은 항목이 모이는 자리다. 코어는 회원 집합 `core.user` 하나를 가진다(`App\Fields\FieldSet::CORE_USER`).

| 키 | `core.user` 의 값 |
| --- | --- |
| `owner_types` | `['user']` |
| `surfaces` | `signup`, `admin_create`, `admin_update`, `self_update`, `required_info` |
| `read_permission` | `core.settings.read` |
| `manage_permission` | `core.settings.update` |

확장은 `getFieldSets()` 로 자기 집합을 선언한다. 집합은 DB 에 저장되지 않는다. 요청에서 처음 쓰일 때 등록소가 코어 선언과 켜진 확장의 선언을 모은다.

```php
public function getFieldSets(): array
{
    return [
        [
            'key' => 'vendor-shop.order_contact',
            'label' => 'vendor-shop::fields.order_contact.set_label',
            'owner_types' => ['shop_order'],
            'surfaces' => ['order_create'],
            'allowed_types' => ['text', 'textarea', 'phone', 'email', 'address'],
            'read_permission' => 'vendor-shop.settings.read',
            'manage_permission' => 'vendor-shop.settings.update',
            'bundle_key' => 'extra_fields',
        ],
    ];
}
```

| 키 | 뜻 | 기본값 |
| --- | --- | --- |
| `key` | 집합 식별자. `{확장 식별자}.{이름}` 형식. 필수 | 없음 |
| `label` | 운영자 화면에 보일 이름. 번역 키 또는 문자열 | `key` 값 |
| `owner_types` | 값 소유자 종류 목록. 공용 값 테이블의 `owner_type` 으로 쓰인다 | `[]` |
| `surfaces` | 이 집합이 쓰는 표면 이름 목록 | `[]` |
| `allowed_types` | 받는 유형 키 목록 | 생략하면 전부 |
| `accepts_contributions` | 다른 확장이 이 집합에 항목을 선언할 수 있는지 | `true` |
| `allows_custom` | 운영자가 항목을 추가할 수 있는지 | `true` |
| `read_permission` | 항목 표 조회 권한 | `core.settings.read` |
| `manage_permission` | 항목 표 수정 권한 | `core.settings.update` |
| `bundle_key` | 컬럼이 아닌 항목이 들어가는 요청 묶음 키 | 없음 |

- 같은 키의 집합이 이미 있으면 먼저 등록된 쪽을 유지하고 경고 로그를 남긴다.
- 집합을 선언하면 코어 공용 항목 API(`/api/admin/field-sets/{set}/fields`)로 그 집합의 표를 보고 고칠 수 있다. 권한은 집합이 정한 두 권한이다.
- 값의 소유자가 회원이 아니면 소유자 모델에 `fieldOwnerType(): string` 을 두어 `owner_types` 의 값을 돌려준다. 메서드가 없으면 모델의 morph 클래스 이름이 쓰인다.

요청에서 항목 값이 놓이는 경로는 다음 순서로 정해진다. 검증 오류 키와 화면의 폼 경로도 같다.

| 조건 | 요청 경로 |
| --- | --- |
| 컬럼 저장 항목 | `{항목 키}` (최상위) |
| 집합에 `bundle_key` 가 있다 | `{bundle_key}.{항목 키}` |
| 그 밖의 선언 항목 | `{항목 키}` (최상위) |
| 그 밖의 운영자 추가 항목 | `custom_fields.{항목 키}` |

## 항목 선언

`getFields()` 는 항목 선언 배열의 목록을 돌려준다. 자기 집합뿐 아니라 `core.user` 나 다른 확장의 집합에도 선언할 수 있다.

```php
public function getFields(): array
{
    return [
        [
            'field_set' => 'core.user',
            'key' => 'shop_newsletter',
            'type' => 'boolean',
            'storage' => 'handler',
            'storage_target' => \Vendor\Shop\Fields\NewsletterFieldHandler::class,
            'name' => ['ko' => '소식지 수신', 'en' => 'Newsletter'],
            'description' => ['ko' => '새 상품 소식을 메일로 받습니다.', 'en' => 'Receive product news by email.'],
            'enabled' => true,
            'required' => false,
            'show_on_signup' => false,
            'edit_scope' => 'self',
            'ui' => ['group' => 'shop'],
            'sort_order' => 2100,
        ],
    ];
}
```

필수 키:

| 키 | 뜻 |
| --- | --- |
| `field_set` | 항목이 들어갈 집합 식별자 |
| `key` | 항목 키. 영문 소문자로 시작하고 소문자, 숫자, 밑줄만 쓰는 2~50자. 요청 키와 응답 키로 쓰인다 |
| `type` | 유형 키 ([유형](#유형)) |
| `name` | 이름. 다국어 배열(`['ko' => …, 'en' => …]`). 빈 배열은 받지 않는다 |

선택 키와 기본값:

| 키 | 뜻 | 기본값 |
| --- | --- | --- |
| `storage` | 저장 방식 `value`, `handler`, `column` ([저장 방식](#저장-방식)) | `value` |
| `storage_target` | `handler` 면 핸들러 클래스명, `column` 이면 컬럼명 | `null` |
| `description` | 안내 문구. 다국어 배열 | `null` |
| `placeholder` | 예시 글. 다국어 배열 | `null` |
| `options` | 선택지 `[{value, label}]`. `label` 은 다국어 배열 또는 문자열 | `null` |
| `constraints` | 유형별 제한 값 | `null` |
| `enabled` | 사용 여부 | `true` |
| `required` | 필수 여부 | `false` |
| `show_on_signup` | 가입 화면에 보일지 | `true` |
| `edit_scope` | `self`(본인 수정), `admin_edit`(관리자만 수정, 본인은 열람), `admin_only`(관리자만 열람). 그 밖의 값은 `self` 로 읽는다 | `self` |
| `require_existing_members` | 이미 가입한 회원에게도 입력을 요구할지. `edit_scope` 가 `self` 가 아니면 `false` 로 저장된다 | `false` |
| `public_profile` | 공개 프로필에 보일지 | `false` |
| `admin_list_column` | 관리자 회원 목록의 열로 쓸지 | `false` |
| `admin_searchable` | 관리자 회원 목록 검색 대상인지 | `false` |
| `display_name_usable` | 표시 이름 종류로 쓸 수 있는지 ([표시 이름](display-name.md#종류)) | `false` |
| `idv_claim` | 이 항목에 이을 본인인증 값의 키 ([본인인증 값 연결](#본인인증-값-연결)) | `null` |
| `idv_lock` | 인증 값으로 채우고 잠글지 | `false` |
| `locks` | 운영자 변경을 막을 속성과 사유. `['required' => '사유 번역 키']` | `null` |
| `legacy_keys` | 가입 요청에서 받아 줄 옛 요청 키 목록 | `null` |
| `ui` | 화면 표시 정보 | `null` |
| `sort_order` | 표시 순서 | `1000` |

### `locks`

`locks` 의 키는 운영자가 바꾸지 못하게 할 속성 이름(`enabled`, `required` 등)이고 값은 사유 문구의 번역 키다. 운영자 표에는 그 속성이 잠긴 채로 보이고, 잠긴 속성을 바꾸는 요청은 무시된다.

```php
'locks' => ['enabled' => 'vendor-shop::fields.locks.enabled'],
```

표시 이름 설정이 닉네임을 요구할 때 닉네임 항목에 걸리는 잠금처럼, 다른 설정이 요청 시점에 거는 잠금도 있다. 지금 걸린 잠금 전체는 `FieldRegistry::locksFor($definition)` 이 돌려준다.

### `legacy_keys`

항목 키를 새로 정하면서 예전 요청 키를 계속 받아야 할 때 쓴다. 가입 요청에서 정식 키가 비어 있고 옛 키가 실려 있으면 코어가 그 값을 정식 키로 복사한다.

```php
'legacy_keys' => ['preferred_currency'],
```

### `ui`

`ui` 는 그대로 화면 계약에 실린다. 서버가 읽는 키와 번들 템플릿이 읽는 키는 다음과 같다.

| 키 | 읽는 쪽 | 뜻 |
| --- | --- | --- |
| `surfaces` | 서버 | 이 항목을 받는 표면 목록. 적으면 그 표면에서만 받는다. 생략하면 표면 규칙대로 받는다 |
| `readonly` | 서버 | 항목 계약의 `readonly` 로 나간다. 선언에 적지 않고 필터 `core.field.filter_definitions` 구독자가 요청마다 채운다 |
| `locked_reason` | 서버 | 항목 계약의 `locked_reason` 으로 나간다. 화면이 안내 문구를 고르는 값이다(예: `identity`, `permission`) |
| `notice` | 서버 | 값에 붙는 알림 한 문장. 요청 언어로 완성한 글이며 보기 화면이 값 아래에 그린다. 입력 안내(`help`)는 보기 화면에 나오지 않으므로, 「저장된 값이 지금은 적용되지 않는다」처럼 보기 화면에서도 알려야 할 때 `core.field.filter_definitions` 구독자가 채운다(예: 이커머스의 배송 불가 국가) |
| `group` | 화면 | 구획 키. 코어 구획은 `basic`, `contact`, `location`, `profile` 이다. 구획 키가 `agreements` 인 항목은 번들 사용자 템플릿이 약관 동의 줄 자리에 그린다. 그 밖의 확장 항목은 선언한 확장별 묶음으로 그려진다 |
| `width` | 화면 | 입력칸 너비 `full` 또는 `half` |
| `role` | 화면 | 약관 묶음 안에서의 역할. 번들 마케팅 플러그인이 `master`, `channel`, `legal` 을 쓴다 |
| `parent` | 화면 | 상위 항목 키. 하위 동의를 상위 동의 아래에 들여 그린다 |
| `link` | 화면 | 약관 「보기」 링크 `{label, title, url, content_api, requires_module}`. `requires_module` 의 모듈이 꺼져 있으면 화면이 단추를 그리지 않는다 |

운영자 추가 항목에서 운영자가 정할 수 있는 `ui` 키는 `group` 과 `width` 둘이다.

### `constraints`

제한 값은 유형마다 다르다. 코어 유형이 받는 키는 다음과 같다.

| 키 | 쓰는 유형 | 뜻 |
| --- | --- | --- |
| `min_length`, `max_length` | `text`, `textarea` | 길이 제한 |
| `rows` | `textarea` | 입력칸 줄 수 |
| `regex`, `regex_message` | `text` | 정규식과 형식 오류 문구(다국어) |
| `format` | `text` | `timezone` 이면 시간대 이름만 받는다 |
| `widget` | `text`, `boolean` | 화면 입력 모양. `text` 는 `country`, `timezone`, `boolean` 은 `toggle`, `checkbox` |
| `inline_label` | `boolean` | 체크 옆에 보일 글(다국어) |
| `display` | `choice`, `multi_choice` | 선택지를 보이는 모양. `choice` 는 `select`, `radio`, `multi_choice` 는 `checkbox`, `select` |
| `allow_other` | `choice` | 「기타(직접 입력)」를 받을지 |
| `options_source` | `choice` | `supported_locales` 면 선택지를 사이트 지원 언어로 채운다 |
| `min_select`, `max_select` | `multi_choice` | 고를 수 있는 개수 |
| `min`, `max` | `number`, 날짜 유형 | 값의 범위 |
| `decimals`, `unit` | `number` | 소수 자릿수와 단위(다국어) |
| `schemes` | `url` | `*` 면 모든 스킴, 생략하면 http 와 https |
| `extensions`, `max_count`, `max_size_mb` | `file`, `image` | 확장자, 개수, 크기 제한 |
| `parts` | `address` | 하위 값을 최상위 키로 받을 때의 키 이름 |
| `unique` | 중복 금지를 지원하는 유형 | 다른 소유자와 겹치는 값을 막는다. 공용 값 테이블 저장 항목에만 적용된다 |
| `default` | 전부 | 값이 없을 때 화면이 보여 줄 초기값 |

`options_source`, `regex`, `format`, `schemes`, `parts` 는 서버 전용이라 화면 계약에 실리지 않는다.

## 저장 방식

| `storage` | 값이 놓이는 곳 | `storage_target` | 선언할 수 있는 쪽 |
| --- | --- | --- | --- |
| `value` | 공용 값 테이블 | 쓰지 않는다 | 누구나 |
| `handler` | 확장의 테이블. 핸들러가 읽고 쓴다 | 핸들러 클래스명. 필수 | 누구나 |
| `column` | 소유자 테이블의 컬럼 | 컬럼명 | 그 집합을 가진 쪽만 |

- `value` 는 따로 만들 것이 없다. 소유자 종류와 소유자 ID 로 값이 저장되고, 소유자가 파기될 때 함께 지워진다.
- `column` 값은 항목 엔진이 저장하지 않는다. `FieldValueService::partition()` 이 컬럼명과 값을 돌려주면 호출자가 소유자 저장 데이터에 합친다.
- 표시 이름 종류와 중복 금지는 `value` 저장 항목에서만 동작한다.
- 관리자 회원 목록의 「필수 항목 미입력」 필터는 회원 컬럼 항목과 `value` 저장 항목만 가른다. `handler` 저장 항목과 파일 항목은 SQL 로 비었는지 알 수 없어 필터에서 빠지고, 행의 미입력 표시에는 포함된다.

## 핸들러 저장

값을 자기 테이블에 두는 항목은 `App\Fields\Contracts\FieldValueHandlerInterface` 를 구현한다. 코어는 `storage_target` 의 클래스를 컨테이너에서 꺼내 쓴다. 클래스가 없거나 계약을 구현하지 않으면 그 항목은 읽히지 않고 저장되지 않는다.

```php
use App\Fields\Contracts\FieldValueHandlerInterface;
use App\Fields\FieldContext;
use App\Models\FieldDefinition;
use Illuminate\Database\Eloquent\Model;

class NewsletterFieldHandler implements FieldValueHandlerInterface
{
    public function read(Model $owner, FieldDefinition $definition): mixed
    {
        return $this->settings->isSubscribed((int) $owner->getKey());
    }

    public function write(Model $owner, FieldDefinition $definition, mixed $value, FieldContext $context): void
    {
        $this->settings->setSubscribed((int) $owner->getKey(), (bool) $value);
    }

    public function extraRules(FieldDefinition $definition, string $attribute, FieldContext $context): array
    {
        return [];
    }

    public function purge(Model $owner, FieldDefinition $definition): void
    {
        $this->settings->deleteFor((int) $owner->getKey());
    }
}
```

| 메서드 | 불리는 때 | 지킬 것 |
| --- | --- | --- |
| `read(Model $owner, FieldDefinition $definition): mixed` | 폼 조회, 필수 항목 판정, 저장 전 현재 값 비교 | 값이 없으면 `null`. 예외를 던지면 코어가 경고 로그를 남기고 `null` 로 읽는다 |
| `write(Model $owner, FieldDefinition $definition, mixed $value, FieldContext $context): void` | 코어가 소유자를 저장한 뒤 같은 트랜잭션 안 | `$value` 는 유형이 정규화한 값이고 비우면 `null` 이다 |
| `extraRules(FieldDefinition $definition, string $attribute, FieldContext $context): array` | 표면의 검증 규칙을 만들 때 | 유형 규칙에 더할 규칙만 돌려준다. 없으면 빈 배열 |
| `purge(Model $owner, FieldDefinition $definition): void` | 탈퇴 회원 파기, 회원 삭제 | 항목마다 불린다. 여러 항목이 한 행을 나눠 쓰면 두 번째 호출에서 지울 것이 없어도 안전해야 한다 |

`write()` 가 불리는 조건:

- 요청에 그 항목의 경로가 실려 있다. 실리지 않은 항목은 넘어오지 않고 저장된 값이 그대로 남는다.
- 그 표면에서 받는 항목이다([표면과 문맥](#표면과-문맥)).
- 값이 `read()` 가 돌려준 현재 값과 다르다.

한 핸들러 클래스가 여러 항목을 맡아도 된다. `$definition->key` 로 어느 칸인지 가른다.

코어를 거치지 않고 핸들러의 저장소에 직접 값을 쓰는 경로(확장의 자체 API 등)가 있으면, 저장 뒤 `app(\App\Services\RequiredFieldsChecker::class)->forget($user)` 를 부른다([기존 회원 입력 요구](#기존-회원-입력-요구)).

## 유형

코어 유형은 15종이다(`App\Fields\FieldTypeRegistry::CORE_TYPES`).

| 유형 키 | 분류 | 지원 기능 |
| --- | --- | --- |
| `text` | `text` | 가입, 중복 금지, 검색, 목록 열, 표시 이름 |
| `textarea` | `text` | 가입, 검색 |
| `number` | `number` | 가입, 중복 금지, 검색, 목록 열 |
| `boolean` | `choice` | 가입, 검색, 목록 열 |
| `date` | `datetime` | 가입, 검색, 목록 열 |
| `datetime` | `datetime` | 가입, 목록 열 |
| `date_range` | `datetime` | 가입 |
| `choice` | `choice` | 가입, 검색, 목록 열, 선택지 |
| `multi_choice` | `choice` | 가입, 목록 열, 선택지 |
| `phone` | `contact` | 가입, 중복 금지, 검색, 목록 열 |
| `email` | `contact` | 가입, 중복 금지, 검색, 목록 열 |
| `url` | `contact` | 가입, 검색 |
| `address` | `address` | 가입, 검색 |
| `file` | `file` | 없음 |
| `image` | `file` | 없음 |

- 필수 `boolean` 항목의 규칙은 `required` 가 아니라 `accepted` 다. 「아니오」는 필수 판정에서 빈 값이다.
- `file` 과 `image` 는 가입 화면에서 받지 않는다. 가입 화면의 항목 계약에는 `deferred: true` 로 실려 안내 줄만 그려진다.

### 유형 더하기

확장은 `getFieldTypes()` 로 유형을 더한다. `FieldTypeInterface` 구현 클래스명 또는 인스턴스의 목록이다.

```php
public function getFieldTypes(): array
{
    return [\Vendor\Shop\Fields\ColorType::class];
}
```

`App\Fields\Types\AbstractFieldType` 을 이어받으면 글 한 줄 모양(문자열 값 하나)이 기본 동작이고, `key()`, `category()`, `rules()` 만 채우면 된다.

```php
use App\Fields\Types\AbstractFieldType;
use App\Models\FieldDefinition;

class ColorType extends AbstractFieldType
{
    protected array $features = [self::FEATURE_SIGNUP, self::FEATURE_LIST_COLUMN];

    public function key(): string
    {
        return 'vendor_color';
    }

    public function label(): string
    {
        return __('vendor-shop::fields.types.color.label');
    }

    public function description(): string
    {
        return __('vendor-shop::fields.types.color.description');
    }

    public function category(): string
    {
        return 'extension';
    }

    public function renderer(): string
    {
        return 'extension';
    }

    public function rules(FieldDefinition $definition, string $attribute): array
    {
        return [$attribute => ['string', 'regex:/^#[0-9a-f]{6}$/i']];
    }
}
```

`FieldTypeInterface` 의 메서드:

| 메서드 | 뜻 | `AbstractFieldType` 의 기본 구현 |
| --- | --- | --- |
| `key(): string` | 유형 키 | 없음 |
| `label(): string` | 운영자 화면의 유형 이름 | 번역 키 `fields.types.{키}.label` |
| `description(): string` | 유형 설명 | 번역 키 `fields.types.{키}.description` |
| `category(): string` | 항목 추가 화면의 묶음. `text`, `number`, `datetime`, `choice`, `contact`, `address`, `file`, `extension` | 없음 |
| `renderer(): string` | 화면의 그리기 갈래. `builtin`, `address`, `file`, `extension` | `builtin` |
| `supports(string $feature): bool` | 기능 지원 여부 | `$features` 배열에 있는지 |
| `rules(FieldDefinition $definition, string $attribute): array` | 속성 경로 => 규칙 배열. `required` 와 `nullable` 은 팩토리가 붙인다 | 없음 |
| `requiredAttributes(FieldDefinition $definition, string $attribute): array` | 필수일 때 `required` 를 걸 하위 경로. 빈 배열이면 속성 자체에 건다 | 빈 배열 |
| `inputPaths(FieldDefinition $definition, string $attribute): array` | 이 항목이 요청에서 읽는 경로 목록 | `[$attribute]` |
| `extract(FieldDefinition $definition, string $attribute, array $data): mixed` | 요청 데이터에서 입력값을 꺼낸다 | `data_get($data, $attribute)` |
| `normalize(FieldDefinition $definition, mixed $input): mixed` | 저장용 값으로 정규화. 빈 값은 `null` | 앞뒤 공백을 뗀 문자열 |
| `isEmpty(mixed $value): bool` | 빈 값인지 | `null`, 빈 문자열, 빈 배열 |
| `serialize(FieldDefinition $definition, mixed $value): array` | 공용 값 테이블의 칸으로 직렬화 | `value_text` 와 `value_index` |
| `deserialize(FieldDefinition $definition, array $columns): mixed` | 값 칸에서 값을 되살린다 | `value_text` |
| `display(FieldDefinition $definition, mixed $value, ?string $locale = null): ?string` | 보기용 문자열 | 값 그대로 |
| `uniqueKey(FieldDefinition $definition, mixed $value): ?string` | 중복 비교 키 | 중복 금지를 지원하면 정규화한 색인 문자열 |
| `constraintRules(): array` | 운영자가 넣는 제한 값의 검증 규칙. `constraints.{키}` => 규칙 | 빈 배열 |

기능 상수는 `FEATURE_UNIQUE`, `FEATURE_SEARCH`, `FEATURE_LIST_COLUMN`, `FEATURE_DISPLAY_NAME`, `FEATURE_SIGNUP`, `FEATURE_OPTIONS` 다. `AbstractFieldType` 의 `$features` 기본값은 `[FEATURE_SIGNUP]` 이다.

- 같은 키의 유형이 이미 있으면 먼저 등록된 쪽을 유지하고 경고 로그를 남긴다.
- 유형을 제공한 확장이 꺼지면 그 유형은 목록에서 빠진다. 그 유형으로 만든 항목은 사용자 화면에서 숨겨지고 운영자 표에 「사용 불가」로 남는다. 저장된 값은 지워지지 않는다.
- 확장 유형의 입력칸을 화면에 그리는 방법은 [사용자 템플릿 계약](user-template-contract.md)을 본다.

## 표면과 문맥

표면은 항목 값을 받는 자리다. 코어 회원 집합의 표면은 다섯이다.

| 표면 | 상수 (`App\Fields\FieldContext`) | 받는 항목 |
| --- | --- | --- |
| 가입 | `SURFACE_SIGNUP` (`signup`) | 사용 중, 본인 수정 범위, 가입 화면 표시, 가입에서 받을 수 있는 유형 |
| 내 정보 수정 | `SURFACE_SELF_UPDATE` (`self_update`) | 사용 중, 본인 수정 범위 |
| 추가 정보 입력 | `SURFACE_REQUIRED_INFO` (`required_info`) | 사용 중, 본인 수정 범위. 코어의 추가 정보 입력은 문맥의 `onlyKeys` 로 비어 있는 필수 항목만 고른다 |
| 관리자 회원 추가 | `SURFACE_ADMIN_CREATE` (`admin_create`) | 사용 중인 전부 |
| 관리자 회원 수정 | `SURFACE_ADMIN_UPDATE` (`admin_update`) | 사용 중인 전부 |

- 항목이 `ui.surfaces` 로 표면을 한정했으면 위 조건에 더해 그 표면에서만 받는다.
- 그 표면에서 받지 않는 항목은 규칙에서 빠진다. 요청이 그 키를 보내도 검증된 데이터에 들어가지 않으므로 조용히 무시된다.
- 확장 집합은 표면 이름을 스스로 정한다. 코어 표면이 아닌 이름은 「사용 중인 전부」로 다뤄진다.

`FieldContext` 는 규칙과 저장이 돌아가는 문맥이다.

```php
new FieldContext(
    surface: 'order_create',
    owner: $order,        // 수정 표면의 대상. 생성 표면은 null
    actor: $user,         // 요청한 사용자
    scopeKey: null,       // 집합 안의 범위 키 (게시판 식별자 등)
    onlyKeys: null,       // 이 키의 항목만 대상으로 삼는다
    extra: [],            // 확장이 쓰는 부가 문맥
);

FieldContext::for(FieldContext::SURFACE_SELF_UPDATE, $user, $user);
```

훅에는 `toArray()` 의 배열(`surface`, `owner`, `actor`, `scope_key`, `only_keys`, `extra`)로 넘어간다.

## 검증 규칙

항목의 검증 규칙은 `App\Fields\FieldRuleFactory` 한 곳이 만든다. 요청 클래스는 고정 규칙에 팩토리의 규칙을 합친다. 항목 규칙을 요청 클래스에 따로 적으면 운영자가 표에서 바꾼 사용, 필수 여부와 어긋난다.

```php
$factory = app(\App\Fields\FieldRuleFactory::class);

$rules = [...$fixedRules, ...$factory->rules($set, $context)];
$attributes = $factory->attributes($set, $context);
$messages = $factory->messages($set, $context);
```

| 메서드 | 돌려주는 것 |
| --- | --- |
| `rules(string $set, FieldContext $context): array` | 요청 속성 경로 => 규칙 배열 |
| `attributes(string $set, FieldContext $context, bool $skipColumns = false): array` | 요청 속성 경로 => 항목 이름 |
| `messages(string $set, FieldContext $context): array` | `{속성 경로}.regex` => 운영자가 적은 형식 오류 문구 |
| `accepts(FieldDefinition $definition, mixed $value): bool` | 요청이 아닌 곳에서 온 값이 유형 규칙에 맞는지 |

규칙이 만들어지는 순서:

1. 표면에서 받는 항목만 고른다.
2. 유형의 `rules()` 를 받고 앞에 `required` 또는 `nullable` 을 붙인다. 관리자 회원 수정에서는 대상의 현재 값이 빈 필수 항목을 `nullable` 로 둔다. 새로 필수가 된 항목 때문에 관리자가 다른 칸을 못 고치는 일을 막기 위해서다.
3. 중복 금지 규칙, 핸들러의 `extraRules()`, 닉네임 정책 규칙, 표시 이름으로 쓰는 추가 항목의 금지어 규칙, 본인인증 잠금 규칙을 더한다.
4. 필터 `core.field.filter_rules` 를 태운다.

자기 집합의 값을 저장할 때는 `FieldValueService` 를 쓴다.

```php
$values = app(\App\Fields\FieldValueService::class);

$parts = $values->partition($set, $validated, $context);   // ['columns' => [...], 'values' => [...]]
$order = $orders->create([...$data, ...$parts['columns']]);
$values->write($order, $set, $parts['values'], $context);  // 소유자 저장과 같은 트랜잭션 안에서
```

화면이 그릴 항목 계약은 `FieldFormSchema::forSurface(string $set, FieldContext $context, array $current = [])` 가 만든다.

## 동기화

`getFields()` 선언은 `field_definitions` 행으로 동기화된다. 동기화는 확장 설치(`module:install`, `plugin:install`) 때 한 번 돌고, 그 뒤로는 `ModuleManager::syncDeclarativeArtifacts()` 와 `PluginManager::syncDeclarativeArtifacts()` 안에서 돈다. 이 둘은 확장 업데이트(`module:update`, `plugin:update`)와 코어 업그레이드의 선언 재동기화에서 불린다. 코어 항목(`config/core.php` 의 `fields`)은 설치와 코어 업데이트의 시더가 같은 헬퍼로 동기화한다.

| 상황 | 결과 |
| --- | --- |
| 선언에 새 항목이 있다 | 행을 만든다 |
| 선언의 값이 바뀌었다 | 행을 갱신한다. 운영자가 고친 속성은 그대로 둔다 |
| 선언에서 항목이 빠졌다 | 그 행과 그 항목에 저장된 값을 지운다 |
| 선언이 빈 배열이다 | 아무것도 지우지 않는다. DB 에 행이 있으면 경고 로그를 남긴다 |
| 선언을 받을 수 없다 | 그 선언만 건너뛰고 사유를 로그에 남긴다. 이미 있는 행은 지우지 않는다 |
| 확장을 데이터와 함께 삭제한다 | 그 확장이 선언한 행과 값을 지운다 |
| 확장이 꺼져 있다 | 행은 남는다. 등록소가 읽을 때 걸러 내어 화면과 검증에서 빠진다 |

선언이 빈 배열일 때 정리를 건너뛰는 것은 환경 결함으로 선언이 비어 보일 때 운영자 설정이 든 행을 지우지 않기 위한 방어막이다. 항목을 모두 없애려면 확장을 삭제한다.

### 운영자 수정 보존

운영자가 표에서 고친 속성은 `user_overrides` 에 기록되고 재동기화가 그 속성을 덮어쓰지 않는다. 다국어 속성(`name`, `description`, `placeholder`)은 언어 단위로 보존된다.

| 구분 | 속성 |
| --- | --- |
| 보존 대상 | `name`, `description`, `placeholder`, `options`, `constraints`, `enabled`, `required`, `show_on_signup`, `edit_scope`, `require_existing_members`, `public_profile`, `admin_list_column`, `admin_searchable`, `display_name_usable`, `idv_claim`, `idv_lock`, `sort_order` |
| 늘 선언 값으로 갱신 | `type`, `storage`, `storage_target`, `locks`, `legacy_keys`, `ui` |

선언 항목에서 운영자가 표로 바꿀 수 있는 속성은 `name`, `description`, `placeholder`, `enabled`, `required`, `show_on_signup`, `edit_scope`, `require_existing_members`, `public_profile`, `admin_list_column`, `admin_searchable`, `idv_lock` 이다. `locks` 에 적힌 속성은 그 가운데서 빠진다.

### 운영자 추가 항목

운영자가 표에서 만든 항목은 `source_type` 이 `custom` 이다. 선언 동기화, 선언에서 빠진 항목 정리, 확장 삭제 어느 것도 이 항목을 지우지 않는다. 운영자 추가 항목은 늘 공용 값 테이블에 저장되고, 집합이 `allows_custom` 을 끄면 만들 수 없다.

### 새 선언과 업그레이드 스텝

새 항목 선언만 더한 버전은 업그레이드 스텝이 필요 없다. 업데이트의 선언 재동기화가 행을 만든다. 스텝은 기존 데이터를 옮길 때만 쓴다([업그레이드 스텝 작성 가이드](upgrade-step-guide.md#작성자-책임-미래-신규-declaration-영역-도입)).

### 선언 코드에서 파일 읽기

선언 동기화는 확장 갱신 도중에 돈다. 그때는 확장의 번역 네임스페이스가 아직 등록되지 않았을 수 있어 `__()` 로 푼 이름이 번역 키 원문으로 저장될 수 있다. 다국어 이름은 언어 파일을 직접 읽어 배열로 만든다.

코어 도우미 `App\Extension\Helpers\ExtensionLangTexts::collect()` 가 확장 자체 언어 파일(`{언어 디렉터리}/{언어}/{파일}.php`)과 그 확장을 대상으로 하는 활성 언어팩의 `backend/{언어}/{파일}.php` 를 함께 읽는다. 확장 자체 파일이 가진 언어가 먼저이고, 언어팩은 확장에 없는 언어(ja 등)만 더한다. 확장 파일만 읽으면 일본어 화면에서 그 확장의 항목 이름만 한국어로 보인다. 확장 언어팩을 켜거나 끄면 코어가 그 확장의 항목 선언을 다시 동기화하므로, 팩을 나중에 설치한 사이트에도 이름이 반영된다.

경로는 `$this->getModulePath()`(플러그인은 `$this->getPluginPath()`)로 얻는다. 갱신 도중 새 코드를 다시 읽는 인스턴스에서는 `__DIR__` 이 확장 디렉터리를 가리키지 않을 수 있다.

```php
use App\Extension\Helpers\ExtensionLangTexts;

private function fieldTexts(string $path): array
{
    // 첫 인자는 확장 식별자(언어팩의 대상 식별자)
    return ExtensionLangTexts::collect('vendor-module', $this->getModulePath().'/src/lang', 'fields', $path);
}
```

## 교차 기여

다른 확장이 가진 집합에도 항목을 선언할 수 있다. 번들 게시판의 비회원 작성자 집합(`sirsoft-board.guest_author`)과 이커머스의 주문서 집합(`sirsoft-ecommerce.order_contact`)이 기여를 받는다. 기여한 항목은 그 집합의 운영자 표에 출처와 함께 나타난다.

다른 확장의 집합에 선언하려면 manifest 의 `dependencies` 에 그 확장을 적는다.

선언이 받아들여지는 조건과 거절 사유:

| 조건 | 맞지 않을 때의 사유 |
| --- | --- |
| `field_set`, `key`, `type` 이 문자열이다 | 필수 값 누락 |
| `key` 가 형식에 맞다 | 키 형식 오류 |
| `name` 이 비어 있지 않은 다국어 배열이다 | 이름 누락 |
| 집합이 등록되어 있다 | 집합 없음 (집합을 가진 확장이 설치되지 않았거나 꺼져 있다) |
| 자기 집합이거나 집합이 `accepts_contributions` 를 켰다 | 다른 확장의 항목을 받지 않는 집합 |
| 집합의 `allowed_types` 안의 유형이다 | 집합이 허용하지 않는 유형 |
| 유형이 등록되어 있다 | 등록되지 않은 유형 |
| `storage` 가 세 값 가운데 하나다 | 저장 방식 오류 |
| `column` 저장이면 자기 집합이다 | 컬럼 저장은 집합을 가진 쪽만 선언 가능 |
| `handler` 저장이면 `storage_target` 이 있다 | 핸들러 클래스 누락 |
| 같은 키를 다른 출처가 갖고 있지 않다 | 같은 키의 항목이 이미 있음 (먼저 등록된 쪽을 유지) |

거절된 선언은 건너뛰고 경고 로그에 사유를 남긴다. 다른 선언은 계속 동기화된다.

집합을 가진 확장이 꺼졌을 때:

- 그 집합이 등록소에서 빠지므로 그 집합의 항목 전부가 읽히지 않는다.
- 그 사이 기여 확장을 업데이트하면 그 선언은 「집합 없음」으로 건너뛴다. 이미 있던 행과 운영자가 바꿔 둔 값은 지워지지 않는다.
- 집합을 가진 확장을 다시 켜면 항목이 운영자 설정과 함께 돌아온다.

## 기존 회원 입력 요구

운영자가 필수 항목에 「기존 회원에게도 입력 요구」(`require_existing_members`)를 켜면, 그 항목이 비어 있는 회원은 추가 정보 입력 화면으로 안내되고 쓰기 요청이 거부된다. 판정은 `App\Services\RequiredFieldsChecker` 가 한다.

| 회원 | 판정 대상 |
| --- | --- |
| 보통 회원 | 사용 중, 필수, 본인 수정 범위이고 `require_existing_members` 를 켠 항목 |
| 필수 항목을 건너뛰고 만들어진 회원 | 사용 중, 필수, 본인 수정 범위인 모든 항목 |

그 회원이 고칠 수 없게 잠긴 칸은 판정에서 빠진다.

### 쓰기 차단

비어 있는 필수 항목이 있는 회원의 POST, PUT, PATCH, DELETE 요청은 `api` 미들웨어 그룹에서 거부된다. 모듈과 플러그인의 API 도 같은 판정을 받는다.

```json
{
  "success": false,
  "message": "추가 정보를 입력해야 이용할 수 있습니다.",
  "error_code": "required_fields_pending",
  "data": {
    "pending_required_fields": ["job"],
    "fields": [{ "key": "job", "label": "직업" }]
  }
}
```

응답 상태는 403 이다. 다음 요청은 거부하지 않는다.

| 조건 | 설명 |
| --- | --- |
| 인증된 회원이 없다 | 비회원 요청, 인증 미들웨어가 없는 라우트 |
| 읽기 요청 | GET, HEAD, OPTIONS |
| 관리자 API | URI 에 `admin` 구간이 있거나 관리자 미들웨어가 붙은 라우트 |
| 통과 표식 | 라우트에 미들웨어 별칭 `allow.pending_required_fields` 가 붙어 있다 |
| 확장 필터 | 필터 `core.required_fields.filter_exempt_routes` 가 돌려준 라우트 이름 |

### 허용 라우트 보태기

미입력 회원에게도 열어 둬야 하는 쓰기 라우트(진행 중인 결제의 마무리, 비밀번호 확인, 동의 기록 등)는 필터로 라우트 이름을 보탠다. 이름 끝에 `*` 를 쓰면 접두가 같은 라우트를 한꺼번에 허용한다.

```php
public static function getSubscribedHooks(): array
{
    return [
        'core.required_fields.filter_exempt_routes' => [
            'method' => 'exemptRoutes',
            'type' => 'filter',
            'priority' => 10,
        ],
    ];
}

public function exemptRoutes(mixed $routes, mixed ...$args): array
{
    $routes = is_array($routes) ? $routes : [];

    return array_values(array_unique([...$routes, 'api.plugins.vendor-pay.payment.close-report']));
}
```

새 주문이나 새 결제를 시작하는 라우트는 보태지 않는다. 차단의 목적이 그 행위를 막는 것이다.

### 판정 캐시

회원 한 명의 판정은 캐시된다. 코어가 회원 값을 저장하면 캐시를 스스로 버린다. 확장이 코어를 거치지 않고 핸들러의 저장소에 직접 값을 썼다면 저장 뒤 캐시를 버려야 한다. 버리지 않으면 값을 채운 회원이 캐시가 끝날 때까지 추가 정보 입력 화면으로 안내될 수 있다.

```php
app(\App\Services\RequiredFieldsChecker::class)->forget($user);
```

| 메서드 | 쓰임 |
| --- | --- |
| `pendingKeys(User $user, bool $fresh = false): array` | 회원 한 명의 비어 있는 필수 항목 키 |
| `pendingFor(Collection $users, bool $excludeLocked = true): array` | 회원 여럿의 판정. 회원 ID => 항목 키 목록 |
| `forget(User\|int\|string $user): void` | 회원 한 명의 판정 캐시를 버린다 |
| `settle(User $user): array` | 캐시를 버리고 다시 판정한다. 건너뛴 회원이 모두 채웠으면 그 표시를 지운다 |
| `describe(array $keys): array` | 항목 키 목록을 `{key, label}` 목록으로 바꾼다 |

쓰기 요청을 거부하기 직전에는 코어가 캐시를 버리고 다시 판정하므로, 낡은 캐시 때문에 요청이 거부되지는 않는다.

## 본인인증 값 연결

본인인증 수단이 확인한 이름, 휴대폰, 생년월일, 성별을 회원 항목에 채우고 잠글 수 있다. 어느 항목에 넣을지는 항목의 `idv_claim` 이 정하고, `idv_lock` 을 켠 항목은 서버가 인증 값으로 덮어쓰고 회원이 고치지 못하게 잠근다.

| `idv_claim` | 뜻 | 값의 모양 |
| --- | --- | --- |
| `name` | 이름 | 문자열 |
| `mobile` | 휴대폰 번호 | 숫자만 또는 하이픈 포함 |
| `birth_date` | 생년월일 | `YYYY-MM-DD` |
| `gender` | 성별 | `male` 또는 `female` |

본인인증 프로바이더가 인증 값을 내주려면 `App\Contracts\Extension\ProvidesVerifiedClaimsInterface` 를 함께 구현한다.

```php
use App\Contracts\Extension\ProvidesVerifiedClaimsInterface;
use App\Extension\IdentityVerification\DTO\VerifiedClaims;
use App\Models\IdentityVerificationLog;
use App\Models\User;

class VendorIdentityProvider implements IdentityVerificationInterface, ProvidesVerifiedClaimsInterface
{
    public function supportedClaims(): array
    {
        return ['name', 'mobile', 'birth_date', 'gender'];
    }

    public function verifiedClaims(IdentityVerificationLog $log, ?User $user): ?VerifiedClaims
    {
        $record = $this->records->findForLog($log, $user);

        return $record === null ? null : new VerifiedClaims(
            name: $record->name,
            mobile: $record->phone,
            birthDate: $record->birthday,
            gender: $record->gender,
        );
    }
}
```

| 메서드 | 뜻 |
| --- | --- |
| `supportedClaims(): array` | 이 수단이 줄 수 있는 인증 값 키 목록. 여기 없는 키에 연결된 항목은 이 수단으로 인증한 회원에게 잠기지 않는다 |
| `verifiedClaims(IdentityVerificationLog $log, ?User $user): ?VerifiedClaims` | 확인을 마친 요청 행의 인증 값. 값을 더는 알 수 없으면 `null` |

- 가입 전 본인인증에서는 회원이 없어 `$user` 가 `null` 로 온다. 그때도 값을 돌려줄 수 있어야 가입 화면이 채워진다.
- 계약을 구현하지 않은 수단(메일 인증번호 등)은 인증 값이 없는 것으로 다뤄져 채움과 잠금이 일어나지 않는다.
- 항목 유형의 규칙에 맞지 않는 값(선택지에 없는 성별 값 등)은 넣지 않는다.
- 인증 값이 오지 않아 비어 있는 항목은 잠그지 않는다.
- 내 정보 수정의 잠금은 지금 값이 그 회원의 최근 인증 값과 같은 항목에만 걸린다. 코어는 회원이 그 수단으로 마친 가장 최근의 본인인증 요청 행을 `verifiedClaims($log, $user)` 로 다시 물어 비교한다. 그래서 인증 값이 유형에 맞지 않아 회원이 직접 쓴 값, 운영자가 회원 인증 뒤에 잠금을 켠 경우의 이전 값은 잠기지 않는다. 회원이 있는 요청에서 `verifiedClaims()` 가 `null` 을 돌려주면(값을 보관하지 않는 수단) 그 회원의 항목은 잠기지 않는다.
- 코어는 받은 값을 회원 항목 값으로만 쓰고 요청 행이나 로그에 다시 저장하지 않는다.
- 필터 `core.identity.filter_verified_claims` 로 값을 고치거나, 계약을 구현하지 않은 수단의 값을 대신 줄 수 있다.

화면에서의 동작과 응답 모양은 [본인인증 API](../backend/api/identity.md)와 [훅 시스템](hooks.md#기존-회원-입력-요구와-본인인증-값-훅-720)을 본다.

## 훅

| 훅 | 종류 | 인자 | 쓰임 |
| --- | --- | --- | --- |
| `core.field.filter_sets` | 필터 | `array $sets` (집합 식별자 => `FieldSet`) | 집합을 동적으로 더하거나 뺀다 |
| `core.field.filter_types` | 필터 | `array $types` (유형 키 => `FieldTypeInterface`) | 유형을 동적으로 더하거나 뺀다 |
| `core.field.filter_definitions` | 필터 | `Collection $definitions, string $set, ?string $scopeKey, array $context` | 요청 시점에 항목 목록을 조정한다. 선택지 채움, 항목 제외, `ui.readonly` 와 `ui.locked_reason` 채움 |
| `core.field.filter_rules` | 필터 | `array $rules, string $set, string $surface, array $context` | 팩토리가 만든 규칙을 조정한다 |
| `core.fields.config` | 필터 | `array $fields` | 코어 회원 항목 선언(`config/core.php` 의 `fields`)을 동기화 전에 조정한다. 언어팩이 이름에 로케일 키를 더할 때 쓴다 |
| `core.field_definition.after_create` | 액션 | `FieldDefinition $definition` | 운영자가 항목을 만든 뒤 |
| `core.field_definition.after_update` | 액션 | `FieldDefinition $definition, array $attributes` | 운영자가 항목을 고친 뒤. `$attributes` 는 저장에 쓰인 속성 |
| `core.field_definition.after_delete` | 액션 | `FieldDefinition $snapshot` | 운영자가 항목을 지운 뒤. 인자는 지우기 전의 사본 |
| `core.field_definition.filter_usages` | 필터 | `array $usages, FieldDefinition $definition` | 삭제 전 사용처를 모은다. 사용처가 하나라도 있으면 삭제가 409 로 막힌다 |
| `core.user.fields.after_update` | 액션 | `User $user, array $changes, string $surface` | 회원 수정에서 컬럼이 아닌 항목 값이 바뀐 뒤. `$changes` 는 항목 키 => `{old, new}` |
| `core.required_fields.filter_exempt_routes` | 필터 | `array $routeNames, Request $request` | 미입력 회원에게도 열어 둘 라우트 이름을 보탠다 |
| `core.identity.filter_verified_claims` | 필터 | `?VerifiedClaims $claims, IdentityVerificationLog $log, ?User $user` | 인증 값을 고치거나 대신 준다 |

`core.field.filter_definitions` 에 넘어오는 항목은 사본이다. 속성을 바꿔도 저장된 행과 다른 호출에 영향을 주지 않는다. 목록에 새 `FieldDefinition` 을 더할 때는 `FieldDefinitionSyncHelper::attributesFrom($sourceType, $sourceIdentifier, $declaration)` 으로 선언을 저장 속성으로 바꾸면 동기화된 행과 기본값이 같아진다.

전체 목록과 구독 방법은 [훅 시스템](hooks.md)과 [훅 카탈로그](hook-catalog.md)를 본다.

## 번들 확장의 예

### 게시판: 알림 수신 설정 4칸

회원 집합에 `notify_post_complete`, `notify_post_reply`, `notify_comment`, `notify_reply_comment` 를 선언한다. 값은 게시판 모듈의 회원별 설정 행에 두고 핸들러 하나가 네 칸을 맡는다. 가입 화면 표시는 기본이 꺼짐이다.

```php
$fields[] = [
    'field_set' => 'core.user',
    'key' => $key,
    'type' => 'boolean',
    'storage' => 'handler',
    'storage_target' => UserNotificationFieldHandler::class,
    'name' => $this->fieldTexts("user.{$key}.name"),
    'description' => $this->fieldTexts("user.{$key}.description"),
    'enabled' => true,
    'required' => false,
    'show_on_signup' => false,
    'edit_scope' => 'self',
    'ui' => ['group' => 'notifications'],
    'sort_order' => 2000 + ($index * 10),
];
```

네 항목이 한 행을 나눠 쓰므로 `write()` 는 넘어온 칸 하나만 고치고, `purge()` 는 첫 호출이 행을 지운 뒤의 호출에서도 안전하다.

### 이커머스: 결제 통화와 배송 국가

회원 집합에 `ecommerce_preferred_currency`, `ecommerce_preferred_shipping_country` 를 선언한다.

```php
[
    'field_set' => 'core.user',
    'key' => 'ecommerce_preferred_currency',
    'type' => 'choice',
    'storage' => 'handler',
    'storage_target' => UserPreferenceFieldHandler::class,
    'name' => $this->fieldTexts('user.ecommerce_preferred_currency.name'),
    'constraints' => ['display' => 'select'],
    'show_on_signup' => true,
    'edit_scope' => 'self',
    'legacy_keys' => ['preferred_currency'],
    'ui' => ['group' => 'shop', 'surfaces' => ['signup', 'self_update', 'admin_update', 'required_info']],
    'sort_order' => 2100,
],
```

- 선택지는 선언에 두지 않는다. 사이트 설정에 따라 달라지므로 `core.field.filter_definitions` 구독자가 요청 시점에 채운다. 같은 구독자가 권한 없는 관리자에게 `ui.readonly` 와 `ui.locked_reason` 을 채운다.
- `ui.surfaces` 에서 `admin_create` 를 뺐다. 관리자 회원 추가에서는 받지 않는다.
- 가입 요청이 예전에 쓰던 키는 `legacy_keys` 로 받는다.
- 관리자가 다른 회원의 값을 바꿀 때의 권한 검사는 핸들러의 `extraRules()` 가 한다.

이커머스는 주문서 집합 `sirsoft-ecommerce.order_contact` 도 선언한다. 주문자 이름처럼 끌 수 없는 항목은 `column` 저장과 `locks` 로 선언해 표에 잠긴 행으로 보인다.

### 마케팅: 동의 항목

회원 집합에 전체 동의, 수신 채널, 법적 동의를 `boolean` 항목으로 선언한다. 구획은 `agreements` 라 가입 화면의 약관 동의 줄 자리에 그려진다.

```php
[
    'field_set' => 'core.user',
    'key' => $key,
    'type' => 'boolean',
    'storage' => 'handler',
    'storage_target' => MarketingConsentFieldHandler::class,
    'name' => $name,
    'constraints' => ['widget' => 'checkbox'],
    'show_on_signup' => true,
    'edit_scope' => 'self',
    'locks' => ['enabled' => 'sirsoft-marketing::fields.locks.enabled'],
    'legacy_keys' => ['agree_'.$key],
    'ui' => ['group' => 'agreements', 'role' => 'channel', 'parent' => 'marketing_consent'],
    'sort_order' => $sortOrder,
],
```

- 채널은 운영자가 플러그인 설정에서 더하고 빼므로 선언이 호출 시점의 설정으로 만들어진다. 설정을 저장하면 플러그인이 `FieldDefinitionSyncHelper::sync()` 를 직접 불러 다시 동기화한다.
- 쓸지 말지는 플러그인 설정이 정하므로 `enabled` 를 잠갔다. 꺼 둔 항목은 `core.field.filter_definitions` 구독자가 목록에서 뺀다. 행을 지우지 않으므로 다시 켜면 운영자가 바꿔 둔 값이 그대로 돌아온다.
- 약관 「보기」 링크(`ui.link`)는 요청 언어와 약관 페이지에 따라 달라져 선언에 싣지 않고 같은 구독자가 붙인다.

## 관련 문서

- [항목 집합 API](../backend/api/field-sets.md)
- [표시 이름](display-name.md)
- [사용자 템플릿 계약](user-template-contract.md)
- [관리자 템플릿 계약](admin-template-contract.md)
- [훅 시스템](hooks.md)
- [업그레이드 스텝 작성 가이드](upgrade-step-guide.md)
- [사용자 수정 보존](../backend/user-overrides.md)
