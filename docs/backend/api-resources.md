# API 리소스

> **목적**: API 응답 데이터 변환을 위한 리소스 클래스 규칙

---

## TL;DR (5초 요약)

```text
1. Resource: BaseApiResource 상속 필수 / Collection: BaseApiCollection 상속 필수
2. 민감 정보 제외: created_by, updated_by, deleted_at
3. 관계 데이터: whenLoaded() 사용
4. when() 주의: 커스텀 메서드에서는 삼항 연산자 사용 (MissingValue 누출 방지)
5. 권한 메타 (Resource): ...$this->resourceMeta($request) 스프레드로 is_owner + abilities(can_*) 표준화
6. 권한 메타 (Collection): abilityMap() 오버라이드 + resolveCollectionAbilities($request) — 페이지 버튼 제어
```

---

## 목차

1. [기본 규칙](#기본-규칙)
2. [권한 메타 표준화 (is_owner + abilities)](#권한-메타-표준화-is_owner--abilities)
3. [$this->when() 사용 주의사항](#thiswhen-사용-주의사항)
4. [패턴 예시](#패턴-예시)

---

## 기본 규칙

### BaseApiResource / BaseApiCollection 상속 필수

모든 API 리소스는 `BaseApiResource`를, 모든 API 컬렉션은 `BaseApiCollection`을 상속해야 합니다.

```php
// 단건 리소스
use App\Http\Resources\BaseApiResource;

class ProductResource extends BaseApiResource
{
    // ...
}

// 목록 컬렉션
use App\Http\Resources\BaseApiCollection;

class ProductCollection extends BaseApiCollection
{
    // ...
}
```

`BaseApiCollection`은 `HasRowNumber` trait과 `abilityMap()` + `resolveCollectionAbilities()` 메서드를 제공합니다. 컬렉션 레벨 abilities가 필요하면 `abilityMap()`을 오버라이드합니다.

### 민감한 정보 제외

보안을 위해 다음 필드는 API 응답에서 제외합니다:

- `created_by` - 생성자 ID
- `updated_by` - 수정자 ID
- `deleted_at` - 삭제 시간
- 비밀번호, 토큰 등 인증 정보

### 관계 데이터 처리

관계 데이터는 `whenLoaded`를 사용하여 조건부로 포함합니다:

```php
public function toArray($request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        // ✅ 관계가 로드된 경우에만 포함
        'category' => new CategoryResource($this->whenLoaded('category')),
        'images' => ProductImageResource::collection($this->whenLoaded('images')),
    ];
}
```

---

## 권한 메타 표준화 (is_owner + abilities)

모든 API 리소스 응답에 `is_owner`와 `abilities` (can_*) 필드를 표준화합니다.

### 기본 사용법

`toArray()` 마지막에 `...$this->resourceMeta($request)`를 스프레드합니다:

```php
public function toArray($request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        // ... 기타 필드

        // ✅ 표준 권한 메타 (is_owner + abilities)
        ...$this->resourceMeta($request),
    ];
}
```

### 응답 형식

```json
{
    "id": 1,
    "name": "상품명",
    "is_owner": true,
    "abilities": {
        "can_update": true,
        "can_delete": false
    }
}
```

- `is_owner`: 현재 요청 사용자가 리소스 소유자인지 여부 (`ownerField()` 미정의 시 생략)
- `abilities`: `can_*` 키로 통합된 권한 플래그 (`abilityMap()` 미정의 시 생략)

### ownerField() — 소유자 판단

리소스의 소유자 필드명을 반환합니다:

```php
// 기본값: null (is_owner 생략)
protected function ownerField(): ?string
{
    return 'user_id';  // 또는 'created_by' 등
}
```

### abilityMap() — 정적 권한 매핑

권한 식별자를 `can_*` 키로 매핑합니다. 대부분의 리소스에서 사용합니다:

```php
protected function abilityMap(): array
{
    return [
        'can_create' => 'sirsoft-ecommerce.products.create',
        'can_update' => 'sirsoft-ecommerce.products.update',
        'can_delete' => 'sirsoft-ecommerce.products.delete',
    ];
}
```

### resolveAbilities() — 동적 권한 (고급)

slug 기반 동적 식별자가 필요한 경우 `resolveAbilities()`를 오버라이드합니다:

```php
// Board 모듈 PostResource 패턴
protected function resolveAbilities(Request $request): array
{
    $slug = $this->getSlug($request);
    if (! $slug) {
        return [];
    }

    $abilityMap = $this->isAdminRequest($request)
        ? [
            'can_read' => "sirsoft-board.{$slug}.admin.posts.read",
            'can_write' => "sirsoft-board.{$slug}.admin.posts.write",
        ]
        : [
            'can_read' => "sirsoft-board.{$slug}.posts.read",
            'can_write' => "sirsoft-board.{$slug}.posts.write",
        ];

    return $this->resolveAbilitiesFromMap($abilityMap, $request->user());
}
```

### 컬렉션 레벨 abilities (BaseApiCollection)

페이지 레벨 버튼 제어용 abilities는 `BaseApiCollection`의 `abilityMap()`을 오버라이드합니다. Controller가 아닌 Collection에서 담당합니다:

```php
use App\Http\Resources\BaseApiCollection;

class BrandCollection extends BaseApiCollection
{
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-ecommerce.brands.create',
            'can_delete' => 'sirsoft-ecommerce.brands.delete',
        ];
    }

    public function toArray(Request $request): array
    {
        $abilities = $this->resolveCollectionAbilities($request);

        return [
            'data' => $this->mapWithRowNumber(function ($brand) {
                return (new BrandResource($brand))->toArray(request());
            }),
            'pagination' => [ /* ... */ ],
            ...($abilities ? ['abilities' => $abilities] : []),
        ];
    }
}
```

abilities가 불필요한 컬렉션은 `abilityMap()`을 오버라이드하지 않습니다 (기본값 빈 배열).

### 커스텀 메서드에서의 사용

`toListArray()` 등 커스텀 메서드에서도 동일하게 스프레드합니다:

```php
public function toListArray(): array
{
    return [
        'id' => $this->getValue('id'),
        'name' => $this->getValue('name'),

        // ✅ 커스텀 메서드에서도 동일 패턴
        ...$this->resourceMeta(request()),
    ];
}
```

### 권한 체크 및 scope 기반 abilities

`resolveAbilitiesFromMap()`은 각 ability에 대해 다음 순서로 확인합니다:

1. 권한 체크 (`PermissionHelper::check()`)
2. scope 기반 접근 체크 (`PermissionHelper::checkScopeAccess()`) — 리소스 모델이 있는 경우

scope_type에 따라 리소스 소유자 기반으로 ability가 자동 제한됩니다. 별도의 바이패스 맵 정의가 불필요합니다.

> 상세: [permissions.md](../extension/permissions.md) scope_type 시스템 참조

---

## $this->when() 사용 주의사항

```text
필수: 커스텀 메서드에서는 삼항 연산자 사용 ($this->when() 금지)
필수: 커스텀 메서드에서는 삼항 연산자 사용
```

### 문제 원인

Laravel의 `$this->when()` 메서드는 조건이 false일 때 `MissingValue` 객체를 반환합니다. 이 객체는 **Laravel이 `toArray()`를 처리할 때만 자동으로 필터링**됩니다. `toListArray()`, `withAdminInfo()` 등 커스텀 메서드에서는 필터링되지 않아 빈 객체 `{}`가 JSON 응답에 포함됩니다.

### 발생하는 문제

- React Error #31: "Objects are not valid as a React child"
- 프론트엔드에서 빈 화면 표시
- 데이터 바인딩 실패

### 잘못된 예시 (❌ DON'T)

```php
// ❌ 커스텀 메서드에서 $this->when() 사용 - MissingValue 객체 누출
public function toListArray(): array
{
    return [
        'id' => $this->getValue('id'),
        'name' => $this->getValue('name'),
        // ❌ email_verified_at이 null이면 빈 객체 {} 반환
        'email_verified_at' => $this->when(
            $this->getValue('email_verified_at'),
            fn () => $this->formatDateTimeStringForUser($this->getValue('email_verified_at'))
        ),
    ];
}
```

### 올바른 예시 (✅ DO)

```php
// ✅ 커스텀 메서드에서는 삼항 연산자 사용
public function toListArray(): array
{
    return [
        'id' => $this->getValue('id'),
        'name' => $this->getValue('name'),
        // ✅ null 반환으로 안전하게 처리
        'email_verified_at' => $this->getValue('email_verified_at')
            ? $this->formatDateTimeStringForUser($this->getValue('email_verified_at'))
            : null,
    ];
}
```

### 사용 가능/금지 장소

| 장소 | $this->when() 사용 |
|------|-------------------|
| `toArray()` 메서드 내부 | ✅ 가능 (Laravel이 MissingValue 자동 필터링) |
| `toListArray()` 등 커스텀 메서드 | ❌ 금지 |
| `toProfileArray()` 등 커스텀 메서드 | ❌ 금지 |
| `withAdminInfo()` 등 추가 정보 메서드 | ❌ 금지 |
| `toArray()` 외부에서 수동 호출되는 모든 메서드 | ❌ 금지 |

### PHPDoc 작성 권장

커스텀 메서드임을 명시하여 주의사항을 알립니다:

```php
/**
 * 목록용 간단한 형태의 배열을 반환합니다.
 *
 * 주의: 이 메서드는 toArray() 외부에서 수동 호출되므로
 * $this->when() 대신 삼항 연산자를 사용해야 합니다.
 */
public function toListArray(): array
```

---

## 목록 응답의 하위 컬렉션

목록은 화면이 그 행에서 **실제로 그리는 것**만 싣는다. 행마다 하위 컬렉션을 통째로 직렬화하면 한 페이지를 여는 것만으로 수백~수천 행이 응답에 실린다.

### 가짜 가드

```php
// ❌ 가드처럼 보이지만 가드가 아니다
'options' => $this->relationLoaded('options')
    ? ProductOptionResource::collection($this->options)
    : $this->whenLoaded('activeOptions'),
```

Repository 가 목록 쿼리에서 `options` 를 무조건 eager load 하면 이 조건은 **항상 참**이 되어, 방어하는 것처럼 보이는 코드가 실제로는 아무것도 막지 못한다.

```php
// ✅ whenLoaded 하나만 — 로드 여부는 Repository 가 결정한다
'options' => $this->whenLoaded('options', fn () => ProductOptionResource::collection($this->options)),
```

### 개수·합계는 DB 집계로

배열을 실어 보내고 화면에서 세지 않는다. PHP 컬렉션 연산(`$this->options->where(...)->sum(...)`)도 같은 문제다 — 세려면 이미 전부 메모리에 올라와 있어야 한다.

```php
// ✅ Repository 의 withCount / withSum 결과를 그대로 노출
'options_count' => $this->whenHas('options_count', fn () => (int) $this->options_count),
```

집계 별칭의 존재 여부는 값 검사(`!== null`)로 판정할 수 없다. `SUM` 은 대상 0건에서 `NULL` 을 돌려주므로 "집계하지 않았다" 와 "집계했더니 비어 있다" 가 구분되지 않는다. 속성 키의 존재 여부(`array_key_exists`)만이 두 상황을 가른다.

### 대표 1건이 필요할 때

목록이 하위 컬렉션의 **첫 1건만** 그린다면 관계 자체를 1건으로 좁힌다. eager load 의 `limit(1)` 은 부모별이 아니라 배치 쿼리 전체에 걸리므로 첫 행만 값을 받고 나머지는 빈 값이 된다.

```php
// 모델 — 관계를 "가장 오래된 1건" 으로 정의
public function firstOption(): HasOne
{
    return $this->hasOne(OrderOption::class, 'order_id')->oldestOfMany();
}
```

### 목록 표현은 명시 호출

`toListArray()` 를 정의해 두고 컬렉션이 `toArray()` 를 호출하면 경량 표현은 쓰이지 않는다. 컨트롤러나 컬렉션이 목록 표현을 **명시적으로** 부르는지 확인한다.

```php
// ✅ 컬렉션이 목록 표현을 명시 호출
'data' => $this->collection->map(fn ($row) => (new SampleResource($row))->toListArray($request))->all(),
```

이 형태는 Laravel 의 `MissingValue` 제거 단계를 거치지 않으므로, 조건부 필드를 직접 걸러내야 한다(위 "커스텀 메서드" 절 참조). 걸러내지 않으면 미충족 필드가 `{}` 로 응답에 남는다.

### 목록 Resource 안에서 관계를 재쿼리하지 않는다

```php
// ❌ eager load 된 컬렉션을 무시하고 행마다 쿼리
'thumbnail' => $this->images()->first()?->url,

// ✅ 로드된 컬렉션에서 고른다
'thumbnail' => $this->relationLoaded('images')
    ? $this->images->firstWhere('is_thumbnail', true)?->url
    : null,
```

### 뺀 값에는 대체 경로가 있어야 한다

목록에서 제거한 값은 (a) 집계로 대체되거나 (b) 단건 조회에 그대로 남아 있어야 한다. 어느 쪽도 아니면 그것은 성능 개선이 아니라 기능 삭제다.

착수 전 **소비처를 실측한다.** 화면이 그 값을 실제로 순회·렌더하면 제거는 기능 축소다 — 계획에 "안 쓴다" 고 적혀 있어도 레이아웃 JSON 을 열어 확인한다.

단건 조회가 대체 경로라면, 단건이 목록용 조회를 **재사용하지 않는지** 확인한다. 목록 조회는 컬럼을 좁히고 건수 상한을 두므로, 단건이 그것을 재사용하면 목록 최적화가 그대로 단건의 기능 삭제가 된다.

---

## 패턴 예시

### 기본 리소스 클래스

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Resources;

use App\Http\Resources\BaseApiResource;

class ProductResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'is_active' => $this->is_active,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            // created_by, updated_by는 제외 (보안)
        ];
    }
}
```

### 커스텀 메서드가 있는 리소스

```php
<?php

namespace App\Http\Resources;

class UserResource extends BaseApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // ✅ toArray() 내부에서는 when() 사용 가능
            'role' => $this->when($this->role, fn () => $this->role->name),
        ];
    }

    /**
     * 목록용 간단한 배열 반환
     *
     * 주의: $this->when() 대신 삼항 연산자 사용
     */
    public function toListArray(): array
    {
        return [
            'id' => $this->getValue('id'),
            'name' => $this->getValue('name'),
            // ✅ 삼항 연산자 사용
            'email_verified' => $this->getValue('email_verified_at') ? true : false,
        ];
    }
}
```

---

## ResourceCollection 커스텀 메서드

ResourceCollection에 커스텀 메서드를 추가하여 다양한 응답 형식을 지원할 수 있습니다.

### 패턴

```php
class UserCollection extends ResourceCollection
{
    /**
     * 통계 정보를 추가하여 반환
     *
     * @param array $statistics 통계 데이터
     * @return array
     */
    public function withStatistics(array $statistics): array
    {
        return [
            'data' => $this->collection,
            'statistics' => $statistics,
        ];
    }

    /**
     * 관리자용 추가 정보를 포함하여 반환
     *
     * @return array
     */
    public function withAdminInfo(): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'total' => $this->total(),
                'admin_info' => true,
            ],
        ];
    }
}
```

### 컨트롤러에서 사용

```php
public function index(UserListRequest $request): JsonResponse
{
    $users = $this->userService->getPaginatedUsers($request->validated());
    $statistics = $this->userService->getStatistics();
    $collection = new UserCollection($users);

    return $this->success(
        'user.fetch_success',
        $collection->withStatistics($statistics)
    );
}
```

### 주요 커스텀 메서드 유형

| 메서드 | 용도 | 반환 데이터 |
| ------ | ------ | ------ |
| `withStatistics()` | 통계 데이터 추가 | data + statistics |
| `withAdminInfo()` | 관리자 전용 메타 추가 | data + admin meta |
| `toListArray()` | 목록 전용 간소화 응답 | 축소된 필드 |
| `toSimpleArray()` | 최소 필드 응답 | id + name만 |

### Resource에서 다중 응답 형식

Resource 클래스에서도 메서드별로 다른 응답 형식을 제공할 수 있습니다:

```php
class UserResource extends BaseApiResource
{
    /**
     * 목록용 간소화 응답
     */
    public function toListArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
        ];
    }

    /**
     * 프로필용 응답
     */
    public function toProfileArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar_url,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
```

---

## API 리소스 개발 체크리스트

```text
□ BaseApiResource를 상속했는가?
□ 민감한 정보(created_by, updated_by, deleted_at)를 제외했는가?
□ 관계 데이터에 whenLoaded()를 사용했는가?
□ 커스텀 메서드에서 $this->when() 사용을 피했는가?
□ 커스텀 메서드에서 삼항 연산자를 사용했는가?
□ 날짜 필드에 toISOString()을 사용했는가?
□ ...$this->resourceMeta($request) 스프레드를 추가했는가?
□ ownerField()를 정의했는가? (소유자 판단 필요 시)
□ abilityMap()을 정의했는가? (권한 플래그 필요 시)
```

---

## 관련 문서

- [index.md](./index.md) - 백엔드 가이드 인덱스
- [controllers.md](./controllers.md) - 컨트롤러에서 리소스 사용
- [response-helper.md](./response-helper.md) - API 응답 규칙
- [service-repository.md](./service-repository.md) - Eager Loading으로 N+1 방지
- [dto.md](./dto.md) - Eloquent 모델이 아닌 값 묶음 (DTO) — ApiResource 와의 경계
