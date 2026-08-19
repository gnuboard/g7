# Service-Repository 패턴

> **백엔드 가이드** | [목차로 돌아가기](index.md)

---

## TL;DR (5초 요약)

```text
1. RepositoryInterface 주입 필수 (구체 클래스 직접 주입 금지)
2. CoreServiceProvider에서 Interface-구현체 바인딩
3. Service에서 훅 실행: before_create → applyFilters → create → after_create
4. 검증 로직은 FormRequest에서 (Service에 검증 금지)
5. 다중 검색은 HasMultipleSearchFilters Trait 사용
6. Service 에서 Model 직접 인스턴스화 금지 — Repository 의 build/factory 메서드 위임 (가상 모델 합성 포함)
7. 목록 조회는 컬럼 목록 명시 + 깊은 OFFSET 이 가능한 목록은 PaginatesWithDeferredJoin Trait 사용
```

---

## 목차

- [개요](#개요)
- [Repository 인터페이스](#repository-인터페이스)
- [Service 클래스](#service-클래스)
- [트랜잭션 및 관계 삭제 패턴](#트랜잭션-및-관계-삭제-패턴)
- [상태 변경 자동 처리](#상태-변경-자동-처리)
- [Repository 클래스](#repository-클래스)
- [목록 조회 컬럼 프루닝과 지연 조인](#목록-조회-컬럼-프루닝과-지연-조인)
- [페이지네이션 정렬의 전순서 보장](#페이지네이션-정렬의-전순서-보장)
- [다중 검색 필터 Trait](#다중-검색-필터-trait-hasmultiplesearchfilters)
- [모듈에서 Repository 인터페이스 바인딩](#모듈에서-repository-인터페이스-바인딩)
- [중첩 리소스 스코프](#중첩-리소스-스코프)
- [설정 기반 한계값](#설정-기반-한계값)
- [관련 문서](#관련-문서)

---

## 개요

Service-Repository 패턴은 비즈니스 로직과 데이터 액세스 로직을 분리하는 아키텍처 패턴입니다.

```
Controller → Request → Service → RepositoryInterface → Repository → Model
```

| 계층 | 역할 |
|------|------|
| **Service** | 비즈니스 로직, 훅 실행, 트랜잭션 관리 |
| **RepositoryInterface** | Repository 추상화 계약 정의 |
| **Repository** | 데이터 액세스 구현, 쿼리 로직 캡슐화 |

---

## Repository 인터페이스

### 핵심 원칙

```
필수: Repository 인터페이스를 통한 DI (구체 클래스 직접 타입힌트 금지)
필수: Repository 인터페이스를 통한 DI
✅ 필수: CoreServiceProvider에서 인터페이스-구현체 바인딩
```

### 인터페이스 위치

```
app/Contracts/Repositories/
├── LayoutRepositoryInterface.php
├── ModuleRepositoryInterface.php
├── MenuRepositoryInterface.php
├── RoleRepositoryInterface.php
├── TemplateRepositoryInterface.php
├── PluginRepositoryInterface.php
├── UserRepositoryInterface.php
├── PermissionRepositoryInterface.php
├── LayoutVersionRepositoryInterface.php
└── SystemConfigRepositoryInterface.php
```

### 인터페이스 정의 패턴

```php
<?php

namespace App\Contracts\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface UserRepositoryInterface
{
    /**
     * ID로 사용자 조회
     *
     * @param int $id
     * @return User|null
     */
    public function findById(int $id): ?User;

    /**
     * 사용자 목록 페이지네이션 조회
     *
     * @param array $filters 검색 조건
     * @return LengthAwarePaginator
     */
    public function getPaginated(array $filters = []): LengthAwarePaginator;

    /**
     * 사용자 생성
     *
     * @param array $data
     * @return User
     */
    public function create(array $data): User;
}
```

### Repository 구현체 패턴

```php
<?php

namespace App\Repositories;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;

class UserRepository implements UserRepositoryInterface
{
    /**
     * ID로 사용자 조회
     */
    public function findById(int $id): ?User
    {
        return User::find($id);
    }

    // ... 인터페이스 메서드 구현
}
```

### Service Provider 바인딩

`app/Providers/CoreServiceProvider.php`에서 인터페이스와 구현체를 바인딩합니다:

```php
<?php

namespace App\Providers;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Repositories\UserRepository;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerRepositoryBindings();
    }

    private function registerRepositoryBindings(): void
    {
        // bind() 사용 - Repository는 상태가 없으므로 매번 새 인스턴스
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(ModuleRepositoryInterface::class, ModuleRepository::class);
        // ... 나머지 Repository 바인딩
    }
}
```

### 의존성 주입 패턴

#### Service에서 사용 (권장)

```php
<?php

namespace App\Services;

use App\Contracts\Repositories\UserRepositoryInterface;

class UserService
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {}

    public function getUser(int $id): ?User
    {
        return $this->userRepository->findById($id);
    }
}
```

#### Controller에서 사용

```php
<?php

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\Repositories\UserRepositoryInterface;

class UserController extends AdminBaseController
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {
        parent::__construct();
    }
}
```

#### Console Command에서 사용

```php
<?php

namespace App\Console\Commands;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use Illuminate\Console\Command;

class ListModuleCommand extends Command
{
    public function __construct(
        private ModuleRepositoryInterface $moduleRepository
    ) {
        parent::__construct();
    }
}
```

### 인터페이스 장점

| 장점 | 설명 |
|------|------|
| **테스트 용이성** | Mock 객체로 쉽게 대체 가능 |
| **유연한 구현체 교체** | 바인딩만 변경하면 다른 구현체 사용 가능 |
| **명확한 계약** | 인터페이스가 Repository의 공개 API 명세 역할 |
| **의존성 역전** | 고수준 모듈이 저수준 모듈에 의존하지 않음 |

---

## Service 클래스

### 역할

- 비즈니스 로직 구현
- 훅 실행 (before/after)
- 트랜잭션 관리
- 여러 리포지토리 조율

### Model 인스턴스화는 Repository 책임

Service 에서 Model 을 직접 인스턴스화(`new EloquentModel`, `$model->forceFill`, `$model->setAttribute`) 하지 않는다. 모든 Model 인스턴스 생성은 Repository 의 build/factory 메서드에 위임한다. 영속성 있는 행(create/update) 뿐 아니라 가상 모델(미설치 가상 행, DTO-style 합성) 도 동일.

| ❌ 잘못된 패턴 (Service) | ✅ 올바른 패턴 (Service) |
|---|---|
| `$pack = new LanguagePack(); $pack->forceFill([...]); $pack->exists = false;` | `$pack = $this->repository->buildVirtualFromManifest($manifest, $id);` |
| Service 안에서 직접 `setAttribute('virtual_field', ...)` | Repository 의 build 메서드가 가상 속성도 함께 채움 |

이유:

- Service 가 Model 생성 세부(컬럼, 캐스트, 가상 속성 등)를 알게 되면 영속 계층 변경(컬럼 추가/제거, 모델 클래스 교체)이 Service 까지 파급된다.
- Repository 가 단일 진입점이 되어야 Resource/Test 가 의존하는 가상 행 합성 패턴이 일관 유지된다.
- 동일 합성을 여러 Service 메서드에서 반복할 때 DRY 위반을 방지한다.

Repository Interface 에 합성 메서드 시그니처를 선언:

```php
// app/Contracts/Repositories/LanguagePackRepositoryInterface.php
public function buildVirtualFromManifest(array $manifest, string $bundledIdentifier): LanguagePack;
```

Repository 구현체는 Model 인스턴스화 + 가상 속성 채움 + `exists=false` 설정 모두 책임:

```php
// app/Repositories/LanguagePackRepository.php
public function buildVirtualFromManifest(array $manifest, string $bundledIdentifier): LanguagePack
{
    $pack = new LanguagePack;
    $pack->identifier = (string) $manifest['identifier'];
    // ... 모든 필드 채움 ...
    $pack->setAttribute('bundled_identifier', $bundledIdentifier);
    $pack->exists = false;
    return $pack;
}
```

Service 는 호출만:

```php
// app/Services/LanguagePackService.php
public function findOrBundled(int|string $id): ?LanguagePack
{
    if (is_numeric($id)) return $this->find((int) $id);
    $manifest = json_decode(File::get(...), true);
    return $this->repository->buildVirtualFromManifest($manifest, (string) $id);
}
```

### 패턴

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Hooks\HookManager;
use Modules\Sirsoft\Ecommerce\Contracts\Repositories\ProductRepositoryInterface;

class ProductService
{
    public function __construct(
        private ProductRepositoryInterface $productRepository
    ) {}

    /**
     * 상품 생성
     *
     * @param array $data 상품 데이터
     * @return Product
     */
    public function createProduct(array $data): Product
    {
        // Before 훅 - 데이터 검증, 전처리
        HookManager::doAction('sirsoft-ecommerce.product.before_create', $data);

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.product.filter_create_data', $data);

        // 비즈니스 로직 실행
        $product = $this->productRepository->create($data);

        // After 훅 - 후처리, 알림, 캐시 등
        HookManager::doAction('sirsoft-ecommerce.product.after_create', $product, $data);

        return $product;
    }

    /**
     * 상품 수정
     *
     * @param int $id 상품 ID
     * @param array $data 수정할 데이터
     * @return Product
     */
    public function updateProduct(int $id, array $data): Product
    {
        HookManager::doAction('sirsoft-ecommerce.product.before_update', $id, $data);

        $data = HookManager::applyFilters('sirsoft-ecommerce.product.filter_update_data', $data, $id);

        $product = $this->productRepository->update($id, $data);

        HookManager::doAction('sirsoft-ecommerce.product.after_update', $product, $data);

        return $product;
    }

    /**
     * 상품 목록 조회
     *
     * @param array $filters 검색 필터
     * @return Collection
     */
    public function getProducts(array $filters = []): Collection
    {
        // Before 훅 - 검색 조건 전처리
        HookManager::doAction('sirsoft-ecommerce.product.before_list', $filters);

        // 필터 훅 - 검색 조건 변형
        $filters = HookManager::applyFilters('sirsoft-ecommerce.product.filter_list_query', $filters);

        $products = $this->productRepository->getAll($filters);

        // 필터 훅 - 결과 데이터 변형
        $products = HookManager::applyFilters('sirsoft-ecommerce.product.filter_list_result', $products, $filters);

        // After 훅 - 조회 후처리 (로깅, 캐싱 등)
        HookManager::doAction('sirsoft-ecommerce.product.after_list', $products, $filters);

        return $products;
    }

    /**
     * 상품 상세 조회
     *
     * @param int $id 상품 ID
     * @return Product|null
     */
    public function getProduct(int $id): ?Product
    {
        // Before 훅 - 조회 전처리
        HookManager::doAction('sirsoft-ecommerce.product.before_show', $id);

        $product = $this->productRepository->findById($id);

        if ($product) {
            // 필터 훅 - 조회 결과 변형 (조회수 증가, 관련 데이터 추가 등)
            $product = HookManager::applyFilters('sirsoft-ecommerce.product.filter_show_result', $product);

            // After 훅 - 조회 후처리
            HookManager::doAction('sirsoft-ecommerce.product.after_show', $product);
        }

        return $product;
    }
}
```

### 훅 실행 순서

```
1. Before Action Hook  →  사전 처리, 검증
2. Filter Hook         →  데이터 변형
3. Repository 호출     →  실제 데이터 작업
4. After Action Hook   →  후처리, 알림, 캐시
```

### 훅 네이밍 규칙

```
[vendor-module].[entity].[action]_[timing]

예시:
sirsoft-ecommerce.product.before_create
sirsoft-ecommerce.product.after_update
sirsoft-ecommerce.product.filter_create_data
```

### 서비스 내부 권한 상한(ceiling) 체크

데이터의 일부 필드가 **권한 상승 벡터**일 때(대표적으로 역할 부여 — 역할을 붙이면 그 역할의 전
권한을 넘기는 것과 같다), 서비스는 미들웨어 권한과 별개로 **상한 가드**를 적용한다.

핵심 원칙 세 가지:

1. **게이트는 그 엔드포인트의 라우트 권한(SSoT)과 같은 리소스여야 한다.** 역할 부여는 "사용자
   관리"(`core.users.update` — 이 경로는 라우트에서 이미 강제됨)의 일부이며, "역할 정의 수정"
   (`core.permissions.update` — 역할에 권한을 가감하는 **타 리소스** 권한)을 요구하지 않는다. 연관/타
   리소스 권한으로 원 리소스 조작을 게이팅하면, 원 리소스 권한을 가진 액터가 정당한 작업을 못 한다.
2. **권한 상승 방지는 상한 가드(`PermissionEscalationGuard`)가 담당한다.** 액터가 보유하지 않았거나
   자신의 범위보다 넓은 권한을 담은 역할은 부여할 수 없다.
3. **위반은 명시적으로 거부(403)한다 — 조용히 무시하지 않는다.** 상한 위반 시
   `PermissionEscalationException` 이 전파되어 컨트롤러가 403 으로 매핑한다.

```php
use App\Support\PermissionEscalationGuard;

public function __construct(
    private readonly PermissionEscalationGuard $escalationGuard,
    // ...
) {}

public function updateUser(User $user, array $data): User
{
    $roleIds = $data['role_ids'] ?? null;
    unset($data['role_ids'], $data['roles']);

    // 훅 실행 / 필드 업데이트 (생략)...

    if ($roleIds !== null) {
        $authUser = Auth::user();

        // 상한 검사 대상 = 이번 변경으로 붙거나 떨어지는 역할(추가·제거 대칭 차분).
        // 추가만 검사하면 하위 관리자가 상위 역할을 박탈하는 하향 조작이 상한 없이 뚫린다.
        // 기존 유지 역할은 변경이 아니므로 제외한다.
        $currentRoleIds = $user->roles->pluck('id')->all();
        $changedRoleIds = array_values(array_unique(array_merge(
            array_diff($roleIds, $currentRoleIds),   // 추가되는 역할
            array_diff($currentRoleIds, $roleIds),   // 제거되는 역할
        )));
        if (! empty($changedRoleIds)) {
            // 상한 위반 시 PermissionEscalationException throw → 컨트롤러가 403 매핑
            $this->escalationGuard->assertRoleAssignmentWithinActorCeiling($changedRoleIds);
        }

        // 자기잠금 방지: 마지막 admin 이 자기 admin 역할을 떼는 것만 별도 차단
        // (자기 자신 수정 자체를 막지는 않는다)

        $user->roles()->sync($roleIds);
    }

    return $user;
}
```

```
필수: 상승 벡터 필드의 게이트는 그 엔드포인트 라우트 권한(SSoT)과 같은 리소스 prefix 여야 함
      (연관/타 리소스 권한이 원 리소스 조작을 침범 금지)
필수: 권한 상승 방지는 foreign 권한 게이트가 아니라 escalation/rank-ceiling 가드로 처리
필수: 상한 위반은 명시적 거부(403) — 조용히 무시(silent no-op/soft-block)하지 않음
금지: `PermissionHelper::check('core.permissions.update')` 로 role_ids 를 drop 하는 silent-drop 패턴
      (원 권한 보유자가 정당한 역할 부여를 못 하고, 200 성공을 반환하면서도 아무 변화가 없어 발견이 늦다)
```

> 상세: [validation.md](validation.md) "계층 리소스"·"보안 게이트 대칭성"

---

## 트랜잭션 및 관계 삭제 패턴

### CASCADE 방지 — 명시적 관계 삭제

```
필수: Service에서 명시적 삭제 (DB CASCADE 의존 금지)
필수: Service에서 모든 관계를 명시적으로 삭제 (훅/파일/로깅 보장)
```

엔티티 삭제 시 관련된 모든 관계를 **Service에서 명시적으로** 제거합니다:

```php
public function deleteUser(User $user): bool
{
    // Before 훅
    HookManager::doAction('core.user.before_delete', $user);

    // 원본 데이터 보관 (after 훅에서 사용)
    $userData = $user->toArray();

    // 관계 명시적 해제 (CASCADE 의존 금지)
    $user->roles()->detach();       // 다대다 관계 해제
    $user->consents()->delete();    // 일대다 관계 삭제
    $user->tokens()->delete();      // 인증 토큰 삭제

    // Attachment 삭제 (비즈니스 로직 통한 삭제)
    if ($user->avatarAttachment) {
        $this->attachmentService->delete($user->avatarAttachment->id);
    }

    $result = $this->userRepository->delete($user);

    // After 훅 (원본 데이터 전달)
    HookManager::doAction('core.user.after_delete', $userData);

    return $result;
}
```

### 일괄 업데이트 트랜잭션 패턴

```php
public function bulkUpdateStatus(array $ids, string $status): int
{
    $statusEnum = UserStatus::from($status);

    $updatedCount = DB::transaction(function () use ($ids, $statusEnum) {
        $count = User::whereIn('id', $ids)->update([
            'status' => $statusEnum->value,
        ]);

        // 트랜잭션 내에서 관계형 데이터 명시적 삭제
        if ($statusEnum !== UserStatus::Active) {
            PersonalAccessToken::where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $ids)
                ->delete();
        }

        return $count;
    });

    // 트랜잭션 외부에서 훅 실행 (실패 시 롤백 방지)
    HookManager::doAction('core.user.after_bulk_update', $ids, $status, $updatedCount);

    return $updatedCount;
}
```

### 핵심 규칙

| 원칙 | 설명 |
| ------ | ------ |
| **CASCADE 금지** | DB 외래키 CASCADE 대신 Service에서 명시적 삭제 |
| **원본 데이터 보관** | 삭제 전 `toArray()`로 캡처 → after 훅에서 사용 |
| **관계 유형별 처리** | `detach()` (다대다), `delete()` (일대다), Service 호출 (복합) |
| **트랜잭션 내 훅 금지** | 훅은 트랜잭션 **외부**에서 실행 (롤백 시 훅 부작용 방지) |
| **AttachmentService 사용** | 파일 삭제는 직접 DB 삭제 대신 AttachmentService 통해 처리 |

---

## 상태 변경 자동 처리

상태(status) 변경 시 관련 타임스탬프와 토큰을 자동으로 처리하는 패턴입니다.

### 상태 변경 타임스탬프 자동 설정

상태 변경 시 관련 타임스탬프를 `match` 표현식으로 자동 설정합니다:

```php
// 상태 변경 감지 및 타임스탬프 자동 설정
$oldStatus = $user->status;
$newStatus = $data['status'] ?? null;

if ($newStatus && $newStatus !== $oldStatus) {
    $newStatusEnum = UserStatus::from($newStatus);
    $data = match ($newStatusEnum) {
        UserStatus::Blocked => array_merge($data, ['blocked_at' => now()]),
        UserStatus::Withdrawn => array_merge($data, ['withdrawn_at' => now()]),
        UserStatus::Active => array_merge($data, ['blocked_at' => null, 'withdrawn_at' => null]),
        UserStatus::Inactive => $data,  // 타임스탬프 변경 없음
    };
}

$this->userRepository->update($user, $data);
```

### 상태 변경 시 토큰 자동 삭제

Active 외 상태로 변경 시 해당 사용자의 토큰을 삭제하여 즉시 로그아웃시킵니다:

```php
// 단일 사용자: 상태가 Active 외로 변경되었으면 토큰 삭제
if ($newStatus && $newStatus !== $oldStatus && $newStatus !== UserStatus::Active->value) {
    $user->tokens()->delete();
}

// 일괄 업데이트: PersonalAccessToken 직접 삭제
if ($statusEnum !== UserStatus::Active) {
    PersonalAccessToken::where('tokenable_type', User::class)
        ->whereIn('tokenable_id', $ids)
        ->delete();
}
```

### 패턴 요약

| 원칙 | 설명 |
| ---------- | ---------- |
| **변경 감지** | `$oldStatus !== $newStatus` 비교 후 처리 (불필요한 업데이트 방지) |
| **match 표현식** | Enum case별 타임스탬프 자동 매핑 (`array_merge`로 기존 데이터 보존) |
| **Active 복귀 시 초기화** | `blocked_at`, `withdrawn_at` 등 관련 타임스탬프를 `null`로 리셋 |
| **토큰 자동 삭제** | 비활성 상태 전환 시 즉시 로그아웃 (단일: `tokens()->delete()`, 일괄: `PersonalAccessToken` 직접 삭제) |

---

## Repository 클래스

### 역할

- 데이터 액세스 추상화
- 쿼리 로직 캡슐화
- N+1 문제 방지 (Eager Loading)

### 데이터베이스 독립성 원칙

```
필수: 표준 SQL만 사용 (MySQL 전용 함수 금지)
필수: Laravel 쿼리빌더 또는 Eloquent 문법 사용
✅ 필수: 데이터베이스 추상화 계층을 통한 쿼리 작성
```

특정 데이터베이스에 의존하는 Raw 쿼리를 사용하면 다른 데이터베이스로 마이그레이션이 어려워집니다.

#### JSON 컬럼 처리

```php
// ❌ DON'T: MySQL 전용 함수 사용
$query->whereRaw("JSON_SEARCH(name, 'one', ?) IS NOT NULL", ["%{$keyword}%"]);
$query->orderByRaw("JSON_EXTRACT(name, '$.\"$locale\"') $sortOrder");

// ✅ DO: Laravel JSON 문법 사용
$locales = config('app.translatable_locales', ['ko', 'en']);
foreach ($locales as $locale) {
    $query->orWhere("name->{$locale}", 'like', "%{$keyword}%");
}
$query->orderBy("name->{$locale}", $sortOrder);
```

#### 다국어 필드 fallback chain

Model 의 `getLocalizedX()` / Resource 의 다국어 컬럼 출력 / Service 의 다국어 매핑 등에서 현재 locale 값을 반환할 때는 `config('app.fallback_locale', 'ko')` 기반 fallback chain 을 사용합니다.

```php
// ✅ DO: app.fallback_locale config 기반
public function getLocalizedName(?string $locale = null): string
{
    $locale = $locale ?? app()->getLocale();
    if (! is_array($this->name)) {
        return (string) $this->name;
    }
    return $this->name[$locale]
        ?? $this->name[config('app.fallback_locale', 'ko')]
        ?? (! empty($this->name) ? array_values($this->name)[0] : '')
        ?? '';
}

// ❌ DON'T: ko / en 하드코딩
return $this->name[$locale]
    ?? $this->name['ko']
    ?? $this->name['en']
    ?? '';
```

운영자가 `APP_FALLBACK_LOCALE` 환경변수로 폴백 locale 을 변경할 수 있도록 보장하는 정합성 정책입니다.

#### 다국어 라벨이 들어간 비즈니스 로직 (copy 접미사 등)

Repository / Service 가 데이터를 복제하면서 이름에 locale 별 접미사를 붙이는 경우, **lang key 를 사용** 하고 `locale === 'ko'` / `locale === 'en'` 같은 분기는 사용하지 않습니다.

```php
// ✅ DO: locale 별 lang key (모듈 자체 lang/{locale}/messages.php + 활성 언어팩 ja 가 보완)
$previousLocale = app()->getLocale();
try {
    foreach ($name as $locale => $value) {
        app()->setLocale($locale);
        $suffix = trans('vendor-module::messages.copy_suffix');
        if ($suffix === 'vendor-module::messages.copy_suffix') {
            $suffix = ' (Copy)'; // 미정의 시 영어 폴백
        }
        $name[$locale] = $value.$suffix;
    }
} finally {
    app()->setLocale($previousLocale);
}

// ❌ DON'T: ko/en 분기 — ja 등 추가 locale 이 영어 폴백으로 떨어짐
$suffix = $locale === 'ko' ? ' (복사)' : ' (Copy)';
```

#### 허용되는 Raw 쿼리

복잡한 집계나 Laravel이 지원하지 않는 기능에 한해 Raw 쿼리를 허용하되, 가능한 표준 SQL을 사용합니다:

```php
// ✅ 표준 SQL 집계 (대부분의 DB에서 호환)
$query->selectRaw('sales_status, COUNT(*) as count');
$query->whereColumn('stock_quantity', '<=', 'safe_stock_quantity');
```

##### Raw 안에 테이블명·별칭을 쓰지 않는다

Raw 를 쓰더라도 **테이블명·별칭·프리픽스를 문자열로 조립하지 않는다.** 조립한 순간 프리픽스 처리를 사람이 떠안게 되고, 빌더가 만든 SQL 과 어긋나 조용히 깨진다.

Laravel 은 별칭에도 프리픽스를 붙인다(`Grammar::wrapAliasedTable`). `join('board_comments as uc', ...)` 는 `g7_board_comments as g7_uc` 가 되므로 `where('uc.content', ...)` 도 `g7_uc.content` 로 맞아떨어진다. 별칭을 `DB::raw` 로 직접 만들면 그 별칭만 프리픽스가 빠져, 같은 별칭을 참조하는 빌더 조건이 전부 어긋난다.

```php
// ❌ DON'T: 별칭을 raw 로 만들고 프리픽스를 손으로 처리 — where('uc.content') 가 g7_uc 를 찾아 실패한다
$prefix = DB::getTablePrefix();
$query->join(DB::raw("{$prefix}board_comments AS uc"), function ($join) {
    $join->on(DB::raw("{$prefix}board_posts.id"), '=', DB::raw('uc.post_id'));
});

// ✅ DO: 빌더가 별칭과 프리픽스를 함께 처리하게 둔다
$query->join((new Comment)->getTable().' as uc', function ($join) use ($postsTable) {
    $join->on("{$postsTable}.id", '=', 'uc.post_id');
});
```

부득이 raw 안에 테이블명이 필요하면(예: `LEFT(...)`, `SUBSTRING(...)`) **모델에서 얻은 이름**과 **연결 설정에서 읽은 프리픽스**를 쓴다.

```php
// ❌ DON'T
DB::raw("LEFT(`{$prefix}board_posts`.`content`, 300) as excerpt")

// ✅ DO
$postsTable = (new Post)->getTable();
DB::raw('LEFT(`'.DB::getTablePrefix().$postsTable.'`.`content`, 300) as excerpt')
```

##### 빌더로 대체 가능한 raw 관용구

| Raw | 빌더 대체 | 비고 |
|---|---|---|
| `whereRaw('1 = 0')` / `whereRaw('0 = 1')` | `whereIn($model->getKeyName(), [])` | 빈 `whereIn` 이 정확히 `0 = 1` 로 컴파일된다 |
| `where($col, DB::raw("({$sub->toSql()})"))` + `mergeBindings` | `where($col, '=', $sub)` | 빌더를 값으로 넘기면 바인딩이 자동 병합된다 |
| `addSelect(DB::raw("({$subSql}) as alias"))` | `addSelect(['alias' => $sub])` | 서브쿼리 select 는 빌더를 그대로 받는다 |
| `whereRaw("... CASE ... IN (?,?)")` | 갈래별 `orWhere(fn ($q) => $q->where(...)->whereIn(...))` | 대상 종류에 따라 갈리는 조건은 갈래별 빌더 조건으로 |
| 상관 서브쿼리 문자열 | `whereColumn()` 으로 외부 컬럼 참조 | 값 비교는 `where()` 로 바인딩 |
| 조인 + `groupBy` 로 존재 판정 | `whereHas()` | 행 증폭이 없어 inner 스캔이 가벼워지고 그룹 COUNT 도 불필요 |

집계 함수(`COUNT`/`SUM`/`COALESCE`)와 DB 고유 함수(`MATCH ... AGAINST`, `JSON_CONTAINS`, `LEFT`, `SUBSTRING`)는 빌더에 대응 표현이 없으므로 raw 로 남긴다. 이때도 **값은 바인딩**하고 테이블명은 넣지 않는다.

### 패턴

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use Modules\Sirsoft\Ecommerce\Models\Product;
use Illuminate\Database\Eloquent\Collection;

class ProductRepository
{
    /**
     * 모든 상품 조회
     *
     * @return Collection
     */
    public function getAll(): Collection
    {
        return Product::with(['category', 'images'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * ID로 상품 조회
     *
     * @param int $id
     * @return Product|null
     */
    public function findById(int $id): ?Product
    {
        return Product::with(['category', 'images'])->find($id);
    }

    /**
     * 상품 생성
     *
     * @param array $data
     * @return Product
     */
    public function create(array $data): Product
    {
        return Product::create($data);
    }

    /**
     * 상품 수정
     *
     * @param int $id
     * @param array $data
     * @return Product
     */
    public function update(int $id, array $data): Product
    {
        $product = $this->findById($id);
        $product->update($data);
        return $product->fresh();
    }
}
```

### Eager Loading으로 N+1 방지

```php
// ❌ DON'T: N+1 문제 발생
public function getAll(): Collection
{
    return Product::all();  // 관계 로딩 없음
}

// ✅ DO: Eager Loading 사용
public function getAll(): Collection
{
    return Product::with(['category', 'images'])->get();
}
```

### 평면 맵은 Eloquent, 중첩 맵은 Support

`Illuminate\Database\Eloquent\Collection` 의 계약은 "**모델의** 컬렉션" 이다 — `load()` / `modelKeys()` / `fresh()` 가 전부 항목이 `Model` 임을 전제한다. 그래서 `map()` / `mapWithKeys()` / `pluck()` / `flatMap()` / `countBy()` / `partition()` 은 결과 항목 중 하나라도 Model 이 아니면 `toBase()` 로 **Support 컬렉션으로 강등**한다. 이것은 버그가 아니라 그 불변식을 지키는 프레임워크의 의도된 방어다.

| ❌ 금지 | ✅ 올바른 사용 |
| ------- | -------------- |
| 중첩 맵(`id => Collection`)을 만들면서 반환 선언은 `Eloquent\Collection` | 선언을 `Illuminate\Support\Collection` 으로 정정 |
| 빈 입력 early return 만 `collect()` (선언은 Eloquent) | `new Collection` / `$this->model->newCollection()` — 분기마다 타입이 갈리지 않게 |
| `new EloquentCollection($grouped->all())` 로 강제 캐스팅해 선언을 지키기 | 항목이 Model 이 아닌 Eloquent 컬렉션이 만들어져 `->load()` 즉시 fatal — 타입 선언을 지키려고 타입 계약을 깨는 자기모순 |
| `return $q->get()->groupBy('col');` 을 `Eloquent\Collection` 선언으로 두기 | 선언을 Support 로 정정 — `groupBy()` 는 강등하지 않아 **TypeError 조차 나지 않는다** |

강등은 **결과가 비면 일어나지 않는다.** `groupBy()` 결과가 비면 `map()` 이 항목을 하나도 보지 않아 Eloquent 인 채로 통과한다. 그래서 이 결함은 "일부 데이터에서만 500" 이라는 형태로 나타나고, 픽스처가 빈 테스트는 green 이 된다 — 실제로 추가옵션 선택지가 있는 상품만 장바구니 담기가 죽었다.

**즉사하지 않는 변종이 더 위험하다.** `Eloquent\Collection` 은 `groupBy()` 를 오버라이드하지 않으므로 Support 구현의 `new static` 이 그대로 쓰인다 — 외곽은 Eloquent 인데 항목은 컬렉션인 값이 반환 타입 검사를 **통과**한다. 위 표의 강제 캐스팅과 결과물이 같은 형태이며, 터지는 곳은 반환 지점이 아니라 그 값을 받아 `->load()` / `->modelKeys()` 를 부르는 훨씬 나중의 호출부다. `map()` 말단이 즉시 TypeError 로 드러나는 것과 달리, 이쪽은 소비자가 생길 때까지 조용히 산다.

판정 기준은 단순하다. **꺼내는 값이 Model 이면 Eloquent, 컬렉션·배열이면 Support.** `*Keyed` 류 메서드 대부분은 평면 `id => Model` 맵이라 Eloquent 선언이 정당하고 실제로 지켜진다. 중첩 맵을 만들면서 옆 메서드의 평면 맵 선언을 복사하는 것이 전형적인 실수다.

정적 검사가 이 불일치를 차단하지만, 정규식 기반이라 강등 여부를 의미적으로 판정하지는 못한다. 권위 있는 가드는 **저장소 단위 테스트**다 — 반환 타입을 바꾸거나 중첩 맵을 새로 만들 때는 `assertInstanceOf` / `assertNotInstanceOf` 로 외곽과 내부 항목의 타입을 함께 고정한다.

### 목록의 eager load 는 목록이 직렬화하는 관계만

eager load 는 N+1 을 막지만, **목록에 필요 없는 관계까지 로드하면** 이번에는 페이로드가 커진다. 한 페이지를 여는 것만으로 그 페이지 전 행의 하위 컬렉션이 통째로 메모리에 올라오고 응답에 실린다.

```php
// ❌ Resource 는 whenLoaded 로 방어하는데 Repository 가 목록에서 무조건 로드
//    → 가드가 항상 참이 되어 무력화된다
$listRelations = ['categories', 'images', 'brand', 'options'];

// ✅ 목록이 실제로 직렬화하는 관계만. 개수·합계는 집계로
$listRelations = ['categories', 'images', 'brand'];
// withCount: ['options as options_count'], outerUsing: fn ($q) => $q->withSum(...)
```

로드 여부를 정하는 곳은 Repository 다. Resource 의 `whenLoaded` 는 그 결정을 **따르는** 장치이지, 그 자체가 페이로드를 줄이지는 않는다.

목록에서 뺀 관계는 단건 조회가 공급해야 한다. 이때 **단건이 목록용 조회 메서드를 재사용하지 않도록** 한다 — 목록 조회는 컬럼을 좁히고 건수 상한을 두므로, 재사용하면 단건이 그 제약을 그대로 물려받아 값이 비거나 상한 밖 행을 찾지 못한다.

```php
// ❌ 단건이 목록용 조회를 재사용 → content 소실 + 상한 밖 행은 못 찾음
$version = $this->repository->getVersions($id)->firstWhere('version', $version);

// ✅ 단건 전용 조회 (전 컬럼 + 키로 직접 조회)
$version = $this->repository->findVersionByNumber($id, $version);
```

### 대표 1건만 필요하면 관계를 1건으로 좁힌다

```php
// ❌ eager load 의 limit 은 부모별이 아니라 배치 전체에 걸린다
//    → 첫 주문만 옵션을 받고 나머지는 빈 값
->with(['options' => fn ($q) => $q->limit(1)])

// ✅ 관계 자체를 "가장 오래된 1건" 으로 정의해 부모별 1건 보장
->with('firstOption')   // hasOne(...)->oldestOfMany()
```

---

## 목록 조회 컬럼 프루닝과 지연 조인

### 규칙

- 목록 조회는 `paginate($perPage)` 처럼 컬럼 인자를 생략하지 않는다. 화면이 실제로 쓰는 컬럼만 명시한다 (인자를 생략하면 `select *` 로 폴백해 넓은 컬럼까지 전부 읽는다).
- 행이 계속 늘어나는 테이블(게시글·주문·활동 로그·알림 발송 이력 등)의 목록은 `PaginatesWithDeferredJoin` Trait 을 사용한다.

### 배경

`OFFSET n` 은 건너뛸 n 건을 읽지 않고 넘기는 것이 아니라 **실제로 읽은 뒤 버린다.** 목록 SELECT 에 `text`/`longText`/JSON 컬럼이 포함돼 있으면 버릴 n 건의 넓은 컬럼까지 함께 읽힌다. 게다가 InnoDB 는 행에 다 담기지 않는 값을 오버플로 페이지에 따로 저장하므로, 앞부분만 필요해도 그 페이지를 읽는다.

게시글 테이블 20만 행 기준 실측 (각 3회 중앙값, 첫 회 버림):

```bash
php artisan g7:bench --profile=sirsoft-board/board_posts --seed=200000 --offsets=0,20000,50000,199980 --runs=3 --explain
```

| OFFSET | 목록 컬럼 | ID 만 조회 | 배수 |
|---:|---:|---:|---:|
| 0 | 4.1ms | 3.9ms | 1.0× |
| 20,000 | 1,682.6ms | 139.8ms | 12.0× |
| 50,000 | 1,623.8ms | 90.3ms | 18.0× |
| 199,980 | 1,811.2ms | 93.9ms | 19.3× |

같은 계측의 실행 계획을 보면 원인이 하나 더 드러난다. OFFSET 0 은 `(board_id, created_at)` 인덱스를 역방향으로 훑어 정렬 없이 끝나지만, OFFSET 이 커지면 옵티마이저가 소프트 삭제 단독 인덱스(`deleted_at`)로 갈아타면서 `filesort` 가 붙는다. 넓은 컬럼을 걷어내는 것과 정렬을 인덱스로 덮는 것은 함께 가야 한다.

`SUBSTRING(content, 1, N)` 을 목록 컬럼에 두는 것은 프루닝이 아니다. 앞 N 바이트를 얻기 위해 오버플로 페이지를 그대로 읽기 때문이다. 잘라내기는 지연 조인의 outer 로 옮긴다.

계측 대상 프로파일 선언 방법과 나머지 3축(화면 응답·쓰기·배치)은 [benchmark.md](benchmark.md) 를 참조한다.

### 사용

```php
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Repositories\Concerns\ResolvesSortSpec;

class OrderRepository implements OrderRepositoryInterface
{
    use PaginatesWithDeferredJoin;
    use ResolvesSortSpec;

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->model->newQuery();
        $this->applyFilters($query, $filters);   // where 만 적용

        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['id', 'order_number', 'status', 'total_amount', 'ordered_at'],
            sort: $this->resolveSortSpec($filters, ['id', 'ordered_at', 'total_amount'], 'ordered_at'),
            perPage: $perPage,
            relations: ['user', 'items'],
        );
    }
}
```

### 계약

| 항목 | 규칙 |
| ------ | ------ |
| `$query` | 필터/where 만 적용한다. `orderBy`/`with`/`select` 는 Trait 이 담당한다 |
| `whereHas` | 결과 집합을 결정하는 조건이므로 그대로 둔다. Trait 이 지우는 것은 `with`(eager load) 뿐이다 |
| 관계 | **반드시 `relations:` 인자로 넘긴다.** Trait 은 inner 뿐 아니라 **outer 에서도** `setEagerLoads([])` 로 eager load 를 지우므로, `$query->with()` 만 하고 인자를 생략하면 관계가 로드되지 않는다 |
| 정렬 | 닫힌 집합이어야 한다. 인덱스 없는 임의 컬럼 정렬은 inner 도 전체 스캔하므로 개선 폭이 보장되지 않는다 |
| 키 컬럼 | 정렬 스펙 끝에 자동으로 덧붙는다. 동률 행의 순서가 흔들려 페이지 경계에서 행이 중복·누락되는 것을 막는다 |

관계 계약을 어겨도 예외나 쿼리 오류는 나지 않는다. 응답에서 관계 필드만 사라지므로, 그 필드를 단언하지 않는 테스트는 전부 통과한다. 실제로 스케줄 실행 이력의 실행자(`triggered_by`)가 이 형태로 유실됐다. `DeferredJoinCallSiteContractTest` 가 호출처를 스캔해 이 조합을 차단한다.

`paginate` / `simplePaginate` / 캐시 total 주입은 인자로 구분한다 — inner 쿼리는 동일하고 COUNT 수행 여부와 반환 클래스만 달라진다.

| 호출자 상황 | `$simple` | `$total` | `$resultCap` | 반환 |
| ------ | ------ | ------ | ------ | ------ |
| 일반 목록 | `false` | `null` | `null` | `LengthAwarePaginator` (COUNT 1회) |
| COUNT 를 피하고 다음 페이지 유무만 필요 | `true` | — | — | `Paginator` |
| 총 건수를 이미 캐시해 둠 | `false` | 캐시값 | `null` | `LengthAwarePaginator` (COUNT 없음) |
| 계속 쌓이기만 하는 목록 (로그·회원 등) | `false` | `null` | 상한 | `BoundedPage` (상한 COUNT + `per_page + 1` 실측) |

상한을 지정하면 총 건수는 그 값까지만 세고, 다음 페이지 판정은 `per_page + 1` 실측으로 따로 한다. 계산이 불가능해지는 것은 마지막 페이지 번호 하나뿐이다. 상세는 [pagination.md](pagination.md).

```php
return $this->paginateWithDeferredJoin(
    query: $query,
    columns: ['*'],
    sort: $sort,
    perPage: $perPage,
    relations: ['user:id,uuid,name,email'],
    resultCap: PaginationLimits::resultCap('admin.activity_logs'),
);
```

정렬 순서 복원은 `whereIn` + 같은 정렬 스펙 재적용으로 한다. 키 컬럼이 정렬에 포함돼 전순서가 성립하므로 결과가 inner 순서와 동일하다. MySQL 전용 `FIELD()` 는 쓰지 않는다. outer 에 존재하지 않는 표현식(집계·조인 서브쿼리)으로 정렬해 재현이 불가능한 경우에만 `$preserveIdOrder = true` 로 표준 SQL `CASE WHEN` 경로를 쓴다.

### 정렬 컬럼 화이트리스트

요청 값을 그대로 `orderBy()` 에 넘기지 않는다. Laravel 이 컬럼명을 백틱으로 감싸므로 SQL 인젝션은 성립하지 않지만, (a) 없는 컬럼으로 인한 SQL 오류가 스키마 정보를 노출하고 (b) 인덱스 없는 넓은 컬럼 정렬을 강제해 DoS 표면이 된다.

정렬 선택지가 4개 이하로 고정된 곳은 `match` 표현식으로 이미 닫혀 있으므로 그대로 두고, 요청 값이 동적 컬럼으로 흘러 들어가는 곳만 `ResolvesSortSpec` 을 쓴다.

허용 컬럼 집합은 대응하는 FormRequest 의 `Rule::in(...)` 과 같은 값으로 맞추고, 그 사실을 상수 주석에 남긴다. 요청이 아닌 경로(콘솔 커맨드·내부 호출)로도 같은 Repository 메서드가 불릴 수 있으므로, 화이트리스트는 FormRequest 하나에만 두지 않고 쿼리를 만드는 자리에도 둔다.

**게이트보다 좁게 두지 않는다.** 허용 값의 SSoT 는 요청을 실제로 거절하는 FormRequest 이고, Repository 상수는 그 아래에 깔리는 안전망이다. 상수가 게이트의 부분집합이면 **검증을 통과한 정렬이 조용히 기본값으로 되돌아간다** — 422 도 로그도 남지 않아 "정렬 버튼이 안 먹는다" 로만 관측된다. FormRequest 가 `HookManager::applyFilters` 로 확장에 열려 있는 목록은 확장이 정렬 컬럼을 늘릴 수 있으므로, 상수를 게이트보다 넓게 두는 편이 안전하다. 상수를 새로 만들거나 고칠 때는 대응 FormRequest 의 `in:` 값을 눈으로 대조하고, 컬럼명이 실재하는지 마이그레이션에서 확인한다.

**방향 검증은 컬럼 검증이 아니다.** 다음은 정렬 방향만 닫아 두고 컬럼은 그대로 흘려보낸 형태이며, 사람이 읽어도 `in_array` 가 있어 안전해 보이지만 실제로는 무방비다.

```php
// ❌ $sortOrder 만 검사 — $sortBy 는 그대로 orderBy 로 들어간다
$sortBy = $filters['sort_by'] ?? 'created_at';
$sortOrder = strtolower($filters['sort_order'] ?? 'desc');

if (! in_array($sortOrder, ['asc', 'desc'])) {
    $sortOrder = 'desc';
}

$query->orderBy($sortBy, $sortOrder);
```

```php
// ✅ 컬럼·방향을 한 번에 해석 (방향 정규화는 Trait 이 담당)
foreach ($this->resolveSortSpec($filters, self::SORTABLE_COLUMNS, 'created_at') as $sort) {
    $query->orderBy($sort['column'], $sort['direction']);
}
```

`repository-sort-column-whitelist` 룰은 **정렬 컬럼 변수 자체를 인자로 받는** `in_array`/`match`/`array_key_exists` 또는 `resolveSortSpec` 만 화이트리스트로 인정한다.

### 화면 정렬 옵션은 게이트의 부분집합이어야 한다

앞 절의 불변식(게이트 ⊆ 저장소)은 백엔드 두 계층 사이의 관계다. 그 위에 하나가 더 있다.

```text
화면 정렬 옵션 ⊆ FormRequest 게이트 ⊆ Repository 화이트리스트
```

레이아웃의 정렬 셀렉트가 게이트에 없는 컬럼을 제공하면 그 옵션을 고르는 순간 **422 가 나고 목록은 직전 결과 그대로 남는다.** 셀렉트 라벨만 새 값으로 바뀌므로 운영자는 정렬이 적용됐다고 읽는다. 토스트도 뜨지 않는다(목록 데이터소스의 오류 경로라 화면에 노출되지 않는다).

정렬 옵션을 추가할 때는 세 곳을 함께 본다.

1. 레이아웃의 `options[].value` (`{col}_{asc|desc}` 형태로 `sort_by` 를 만든다)
2. FormRequest 의 `sort_by` `in:` / `Rule::in`
3. Repository 의 `SORTABLE_COLUMNS` (원 테이블 컬럼) 또는 `RELATED_SORTABLE_COLUMNS` (관계 테이블 컬럼)

정렬 기준 컬럼이 **원 테이블에 없으면** 다음 절의 관계 정렬을 쓴다. 화면에서 옵션을 지우는 것은 마지막 수단이다 — 운영자가 쓰던 기능이 사라진다.

`layout-sort-option-gate-parity` 룰이 레이아웃 정렬 옵션과 게이트를 정적으로 대조한다.

### 분류값 필터의 허용 어휘도 같은 불변식을 따른다

정렬에서 성립하는 포함관계는 **분류값 필터**(출처·유형·상태 등)에서도 그대로 성립한다. 다만 어긋나는 방향이 반대다 — 정렬은 화면이 게이트보다 넓어서 422 가 나고, 필터는 화면이 **좁아서** 아무 오류 없이 일부 행이 사라진다.

```text
화면 필터 옵션 = 라벨 키 = 실제 기록 어휘   (부분집합이 아니라 일치여야 한다)
```

빠진 값으로 기록된 행에는 두 가지가 동시에 일어난다.

| 어긋난 지점 | 증상 | 화면에 오류가 나는가 |
|---|---|---|
| 화면 필터 옵션에 그 값이 없음 | 그 값의 행은 **어떤 필터 조합으로도 도달 불가** — "전체" 로만 스쳐 지나간다 | 아니다 |
| 라벨 키가 없음 | 목록 셀에 `vendor-plugin.admin.x.source.그값` 원시 키 문자열이 노출 | 아니다 |

그래서 어휘는 **Enum 하나에서 파생**시킨다. FormRequest 의 `in:` 문자열, 리스너·서비스의 기록 리터럴, 레이아웃 체크박스, lang 파일에 같은 목록을 네 번 적으면 한 곳만 늘어난 순간 위 두 증상이 조용히 생긴다.

```php
enum ConsentSource: string
{
    case Banner = 'banner';
    case Register = 'register';           // 리스너가 기록하는 값도 반드시 여기에
    case MypageRenewAll = 'mypage_renew_all';  // 서버 자체 기록 경로도 마찬가지

    /** 공개 요청으로 지정 가능한 값 — 서버 자체 기록 경로는 제외 */
    public static function requestSelectableValues(): array { /* ... */ }
}
```

라벨은 **백엔드와 프론트 두 표면 모두**에 정의한다. 관리자 화면의 `$t:` 는 `resources/lang/{locale}.json` 에서 해석되므로 `lang/{locale}/messages.php` 만 채우면 화면에는 여전히 원시 키가 남는다. 치환 문법도 다르다 — 백엔드는 `:name`, 프론트 엔진(`TranslationEngine.replaceParams`)은 `{name}` / `{{name}}` 만 치환한다.

어느 enum 이 어느 화면의 어휘인지는 의미 추론이 필요해 정적 검출이 어렵다(`coverage.json` 의 `filter-vocabulary-enum-parity` = `manual-only`). 확장별로 정합 테스트를 둔다 — 예: `ConsentSourceVocabularyParityTest` (기록 리터럴 ⊆ enum / 화면 옵션 = enum / ko·en × 백엔드·프론트 라벨 전수).

### 목록에 `last_page > 1` 이면 화면에도 페이저가 있어야 한다

응답이 페이지네이션인데 화면이 1페이지만 렌더하고 페이지 이동 컨트롤을 두지 않으면, 나머지 페이지는 **어떤 조작으로도 도달할 수 없다.** 총건수 표시까지 없으면 잘렸다는 사실 자체가 화면에 나타나지 않아 "이력이 그것뿐" 으로 읽힌다(감사 이력·정책 버전처럼 입증 책임이 있는 목록에서는 특히 위험하다).

데이터가 얕은 개발 환경에서는 `last_page` 가 늘 1 이라 이 결함이 드러나지 않는다. 페이지네이션 응답을 소비하는 화면을 만들 때는 `per_page` 를 줄이거나 행을 늘려 **2페이지 이상인 상태를 한 번은 눈으로 확인**한다.

### 관계 테이블 컬럼 기준 정렬 (`SortsByRelatedColumn`)

정렬 기준이 원 테이블이 아니라 관계 테이블에 있는 경우가 있다. 주문 목록의 "발송일" 이 그렇다 — `shipped_at` 은 주문이 아니라 배송 테이블에 있고, 한 주문에 배송 행이 여러 건일 수 있다.

이때 **조인으로 해결하지 않는다.** 두 가지가 함께 깨진다.

| 방식 | 무엇이 깨지는가 |
|---|---|
| `join` + `groupBy` | 1:N 조인이 원 행을 부풀린다 → 총 건수가 늘고 페이지 경계가 어긋난다 |
| `leftJoin` 만 | 자식이 여러 건인 부모가 목록에 중복 노출된다 |
| `join` (INNER) | 자식이 없는 부모(미발송 주문)가 목록에서 사라진다 |
| inner 쿼리에 집계 | 건너뛸 행 전체에 집계가 돌아 OFFSET 이 깊어질수록 비용이 누적된다 — 지연 조인을 넣은 이유가 사라진다 |

**상관 서브쿼리 정렬**을 쓴다. 원 행 수를 바꾸지 않으므로 총 건수·페이지 경계가 그대로 유지되고, 자식이 없는 행도 남는다(값이 `NULL` 로 정렬될 뿐이다).

```php
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Repositories\Concerns\ResolvesSortSpec;
use App\Repositories\Concerns\SortsByRelatedColumn;

class OrderRepository implements OrderRepositoryInterface
{
    use PaginatesWithDeferredJoin;
    use ResolvesSortSpec;
    use SortsByRelatedColumn;

    /** 원 테이블 컬럼 */
    private const SORTABLE_COLUMNS = ['ordered_at', 'paid_at', 'total_amount'];

    /** 관계 테이블 컬럼 — `(외래키, 정렬컬럼)` 복합 인덱스가 전제 */
    private const RELATED_SORTABLE_COLUMNS = [
        'shipped_at' => [
            'model' => OrderShipping::class,
            'foreign_key' => 'order_id',
            'column' => 'shipped_at',
        ],
    ];

    public function getListWithFilters(array $filters, int $perPage): LengthAwarePaginator
    {
        // ... 필터/where 적용 ...

        $sort = $this->resolveSortSpecWithRelated(
            $filters,
            self::SORTABLE_COLUMNS,
            self::RELATED_SORTABLE_COLUMNS,
            $this->model,
            'ordered_at',
        );

        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: self::LIST_COLUMNS,
            sort: $sort,
            perPage: $perPage,
        );
    }
}
```

**집계 함수를 인자로 받지 않는 이유.** 서브쿼리를 정렬 방향과 같은 방향으로 정렬해 한 건만 취하면, `desc` 는 자연히 최댓값(가장 늦은 발송), `asc` 는 최솟값(가장 이른 발송)이 된다. 운영자가 "최근 발송순" 을 골랐을 때 기대하는 값과 일치하고, `MAX()`/`MIN()` 을 따로 지정하다 방향과 어긋나는 실수가 생기지 않는다.

**복합 인덱스는 선택이 아니다.** 서브쿼리는 목록 행마다 한 번씩 실행되므로 `(외래키, 정렬컬럼)` 인덱스가 없으면 행 수만큼 관계 테이블을 스캔한다. 외래키 단일 인덱스만 있으면 정렬 컬럼이 인덱스에 없어 매번 행을 읽어 정렬한다. 관계 정렬을 추가하는 변경에는 **인덱스 마이그레이션을 함께 넣는다.**

```php
$table->index(['order_id', 'shipped_at'], 'ecommerce_order_shippings_order_id_shipped_at_index');
```

정렬 스펙의 `column` 은 문자열 외에 `Builder`/`Expression` 도 올 수 있다. `PaginatesWithDeferredJoin` 이 이를 그대로 받아 inner/outer 양쪽에 적용한다 — 서브쿼리가 원 행의 키에 상관되므로 어느 쪽에 적용해도 같은 순서가 나오고, outer 에서는 이번 페이지 건수만큼만 실행된다. 키 컬럼은 전순서 보장을 위해 항상 마지막에 덧붙는다.

**게이트에도 함께 올린다.** `RELATED_SORTABLE_COLUMNS` 의 키는 FormRequest 의 `sort_by` 허용 목록에 들어가야 한다. `SortWhitelistGateParityTest` 가 두 상수를 합쳐 게이트 ⊆ 저장소 불변식을 검사한다.

### 예외

설정/정의성 테이블처럼 행 수가 고정이고 넓은 컬럼이 없으면 컬럼 목록 없이 페이지네이션해도 된다. 다만 그 판단 근거를 코드 옆에 남긴다.

```php
// audit:allow repository-paginate-column-pruning reason: 역할 정의 테이블 — 행 수 고정(<100), 넓은 컬럼 없음
return $query->paginate($perPage);
```

면제 주석은 **위반 줄에 인접**해야 인식된다. 메서드 첫 줄에 두면 체인 중간의 `->paginate(...)` 줄과 사이에 코드가 끼어 인식되지 않는다.

```php
// ✅ 체인 안에서는 ->paginate 바로 위에 둔다
return $this->model->newQuery()
    ->where('user_id', $userId)
    ->orderByDesc('created_at')
    // audit:allow repository-paginate-column-pruning reason: 사용자 1명에 종속된 목록 — OFFSET 이 깊어질 수 없다
    ->paginate($perPage);
```

면제 사유로 인정되는 판단 근거는 다음 세 가지다. 어느 것도 아니면 지연 조인으로 전환한다.

| 근거 | 성립 조건 | 예 |
|---|---|---|
| 정의/템플릿 테이블 | 행 수가 운영자가 등록한 수에 묶이고 넓은 컬럼이 없다 | 게시판 정의, 배송정책, 상품 안내 템플릿 |
| 부모 1건에 종속 | `where(부모FK)` 로 좁혀져 OFFSET 이 깊어질 수 없다 | 상품 1건의 문의, 사용자 1명의 찜 |
| ID 집합으로 선한정 | `whereIn('id', $matchedIds)` 로 스캔 대상이 이미 묶였다 | FULLTEXT 매칭 후 조회하는 통합검색 |

"부모 1건에 종속" 은 그 부모가 가질 수 있는 자식 수를 실제로 따져 본 뒤에만 쓴다. 대량 발급 쿠폰 1건의 발급 이력처럼 부모가 하나여도 수십만 행이 되는 경우는 이 근거가 성립하지 않는다.

컨트롤러가 Service 에 목록 조회를 위임하는 형태(`$this->service->paginate(...)`)는 컬럼 선택 책임이 위임받은 Repository 에 있으므로 룰이 검사하지 않는다.

### outer 의 `columns: ['*']` 는 위반이 아니다

지연 조인의 목적인 **OFFSET 구간의 넓은 컬럼 읽기**는 inner 가 키 컬럼만 읽는 것으로 이미 사라진다. outer 가 읽는 행 수는 페이지 크기로 고정되므로 `columns: ['*']` 여도 뒤쪽 페이지의 비용 증가는 없다. 넓은 컬럼을 가진 테이블에서 목록 컬럼을 좁히는 것은 그 위에 얹는 추가 최적화이고, 화면이 실제로 쓰는 컬럼이 분명할 때 적용한다.

다만 `columns:` 자체를 생략하면 outer 가 무엇을 읽는지 호출처에서 드러나지 않으므로 항상 명시한다. `DeferredJoinCallSiteContractTest` 가 호출처를 스캔해 `columns:`/`sort:` 명시 여부를 전수 검사한다.

### 페이지네이션 정렬의 전순서 보장

페이지네이션 목록의 정렬은 **고유 키로 닫는다**. 정렬이 비고유 컬럼만으로 끝나면 동률 구간의
행 순서가 페이지마다 달라질 수 있고(SQL 표준은 동률의 순서를 보장하지 않는다), 그 결과
**인접 페이지가 같은 행을 중복 노출하면서 다른 행은 어느 페이지에도 나오지 않는다.**

```php
// ❌ created_at 동률 구간에서 페이지 경계가 흔들린다
$query->orderBy('created_at', 'desc')->paginate($perPage);

// ✅ 정렬 마지막에 기본키를 덧붙여 전순서를 만든다
$query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate($perPage);
```

`ResolvesSortSpec` / `PaginatesWithDeferredJoin` 를 경유하는 목록은 트레이트가 키 컬럼을 자동으로
덧붙이므로 이 규율을 이미 만족한다. 손으로 `orderBy` 를 조립한 뒤 `paginate()` 하는 지점만
위험하다.

동률은 드문 일이 아니다 — 시더·일괄 등록·대량 마이그레이션은 여러 행에 같은 `created_at` 을
남긴다(#492 실측: `roles.created_at` 한 타임스탬프에 최대 10행, 110건 목록에서 고유 키 109개).

판정은 눈으로 하지 않는다. 전 페이지를 순회해 키를 모으고 **합집합 크기 = 총건수**, 인접 페이지
교집합 = ∅ 를 확인한다.

### 필요한 색인은 선언에서 도출한다

지연 조인은 inner 가 키 컬럼만 읽게 만들지만, 그 inner 가 **인덱스 순서 그대로** 끝나야 깊은 OFFSET 이 싸진다. 정렬을 덮는 색인이 없으면 inner 도 filesort 로 전체를 훑으므로 개선 폭이 사라진다. 즉 지연 조인과 색인은 함께 가야 하며, 한쪽만 해두면 화면에서는 "여전히 뒤로 갈수록 느리다" 로만 드러난다.

어떤 색인이 필요한지는 계측 프로파일 선언에 이미 들어 있다. `filters` · `order` · `soft_delete` 를 이으면 그대로 레시피가 된다:

```text
(등치 필터 컬럼들 → soft_delete 면 deleted_at → 정렬 컬럼들 → 기본키)
```

`App\Benchmark\ListIndexAdvisor` 가 이 도출을 담당하고, `ListIndexCoverageTest` 가 전 목록을 검사한다. **새 목록이 프로파일을 선언하면 필요한 색인이 자동으로 검사 대상이 되므로**, 색인 설계를 테이블마다 손으로 반복하지 않아도 된다.

| 판정 | 뜻 | 처방 |
| ------ | ------ | ------ |
| `satisfied` | 도출한 선행 컬럼을 그대로 덮는 색인이 있다 | — |
| `tiebreak_missing` | 색인이 정렬 컬럼에서 끝나 동률 구간에 filesort 가 남는다 | 기존 색인 끝에 기본키를 덧붙여 **교체** (좌측 프리픽스라 남기면 쓰기 비용만 늘어난다) |
| `missing` | 정렬을 덮는 색인이 없다 | 도출한 순서대로 색인 신설 |

주의할 점 둘.

- **등치가 아닌 필터는 선행 컬럼이 될 수 없다.** `in` / `not in` / 범위 조건은 그 지점에서 등치 사슬을 끊으므로 뒤따르는 정렬 컬럼을 인덱스 순서로 쓸 수 없다. 이 판정을 틀리면 "색인이 있는데도 filesort" 라는, 눈으로는 구분되지 않는 상태를 정상으로 보고하게 된다.
- **UNIQUE 색인은 기본키를 덧붙일 필요가 없다.** 값이 중복되지 않아 그 컬럼만으로 이미 전순서다.

색인이 불필요한 목록(행 수가 고정이거나 상한이 작은 정의성 데이터)은 프로파일에 사유와 함께 면제를 선언한다. 사유 없는 면제를 받지 않는 이유는, 면제가 쌓이면 검사 자체가 무의미해지는데 사유가 없으면 그 판단이 여전히 옳은지 나중에 확인할 방법이 없기 때문이다.

```php
'index_exempt' => '페이지는 사이트 구조를 구성하는 정의성 데이터라 ... 수천을 넘기 시작하면 (created_at, id) 색인을 추가할 것.',
```

모듈/플러그인 프로파일은 **그 확장의 테스트 스위트에서** 검사한다. 확장 테이블은 그 스위트에서만 마이그레이션되므로 코어 스위트에 몰면 테이블이 없어 건너뛰고 초록인 채 미검사가 된다. 같은 이유로 "테이블이 없으면 skip" 도 하지 않고 실패로 보고한다.

### 정적 검사 대상

| 검출 대상 | 처리 |
|---|---|
| `->paginate($perPage)` / `->paginate($perPage, ['*'])` — 컬럼 목록 미지정 또는 전체 컬럼 | 차단 |
| 페이지네이션 정렬이 비고유 컬럼으로만 끝남 (기본키 타이브레이크 부재) | 차단 |
| 그룹 쿼리의 총 건수를 `count()` 로 계산 (`getCountForPagination()` 필요) | 차단 |
| 요청 값에서 온 정렬 컬럼을 닫힌 집합 검증 없이 `orderBy` 에 전달 | 경고 |

검사 범위는 `app/Repositories/**`, `modules|plugins/_bundled/**/Repositories/**` 와 컨트롤러 3계열이다. 미수정 파일까지 확인하려면 변경분이 아니라 저장소 전체를 대상으로 검사한다 — 변경 파일만 보는 방식으로는 이미 저장소에 있던 위반이 드러나지 않는다.

호출 계약 중 `relations:` 항목은 정적 룰이 아니라 테스트(`DeferredJoinCallSiteContractTest`)가 검사한다. 쿼리에 `with()` 를 붙이고 `relations:` 를 넘기지 않으면 관계가 조용히 사라지는데, 이 실패는 예외도 쿼리 오류도 내지 않아 관계 필드를 단언하지 않는 HTTP 테스트는 전부 통과한다.

검사 범위는 `app/Repositories/**`, `modules|plugins/_bundled/**/Repositories/**` 와 컨트롤러 3계열이다. 미수정 파일까지 확인하려면 변경분이 아니라 저장소 전체를 대상으로 검사한다 — 변경 파일만 보는 방식으로는 이미 저장소에 있던 위반이 드러나지 않는다.

---

## 다중 검색 필터 Trait (HasMultipleSearchFilters)

목록 API에서 다중 검색 조건을 지원해야 할 때 `HasMultipleSearchFilters` Trait을 사용합니다.

### 파일 위치

`app/Repositories/Concerns/HasMultipleSearchFilters.php`

### 지원 연산자

| 연산자 | 설명 | SQL 변환 |
|--------|------|----------|
| `like` (기본) | 부분 일치 | `LIKE %value%` |
| `eq` | 정확히 일치 | `= value` |
| `starts_with` | 시작 일치 | `LIKE value%` |
| `ends_with` | 끝 일치 | `LIKE %value` |

### Trait 제공 메서드

| 메서드 | 설명 |
|--------|------|
| `applyMultipleSearchFilters()` | 다중 검색 조건을 AND로 적용 |
| `applySearchFilter()` | 개별 검색 필터 적용 (연산자 처리) |
| `applyOrSearchAcrossFields()` | 단일 검색어로 여러 필드 OR 검색 |

### Repository에서 사용

```php
<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Concerns\HasMultipleSearchFilters;
use Illuminate\Database\Eloquent\Builder;

class UserRepository
{
    use HasMultipleSearchFilters;

    /**
     * 검색 가능한 필드 목록 (보안을 위해 허용 필드 명시)
     */
    private const SEARCHABLE_FIELDS = ['name', 'email', 'username'];

    /**
     * 필터링 및 페이지네이션 적용
     */
    public function getPaginatedUsers(array $filters = []): LengthAwarePaginator
    {
        $query = User::query();
        $this->applyFilters($query, $filters);
        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * 쿼리에 필터 조건 적용
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // 다중 검색 조건 적용
        if (! empty($filters['filters']) && is_array($filters['filters'])) {
            $this->applyMultipleSearchFilters($query, $filters['filters'], self::SEARCHABLE_FIELDS);
        }
    }
}
```

### FormRequest 검증 규칙

```php
<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserListRequest extends FormRequest
{
    /**
     * 검색 가능한 필드 목록 (보안을 위해 허용 필드 명시)
     */
    public const SEARCHABLE_FIELDS = ['name', 'email', 'username'];

    public function rules(): array
    {
        // 'all'은 전체 필드 검색을 의미하는 특수 값
        $searchableFields = implode(',', array_merge(['all'], self::SEARCHABLE_FIELDS));

        return [
            // 다중 검색 조건
            'filters' => 'nullable|array|max:10',
            'filters.*.field' => "required_with:filters|string|in:{$searchableFields}",
            'filters.*.value' => 'required_with:filters|string|max:255',
            'filters.*.operator' => 'nullable|string|in:like,eq,starts_with,ends_with',
        ];
    }

    /**
     * 검증 전 데이터 전처리
     *
     * 프론트엔드에서 빈 필터가 전송되는 경우를 처리합니다.
     */
    protected function prepareForValidation(): void
    {
        // value가 비어있는 filters 자동 제거
        $filters = $this->filters;
        if (is_array($filters)) {
            $filters = array_filter($filters, function ($filter) {
                return ! empty($filter['value']);
            });
            // 인덱스 재정렬
            $filters = array_values($filters);
            $this->merge(['filters' => $filters ?: null]);
        }
    }
}
```

### 빈 필터 자동 제거 패턴

프론트엔드(DataSourceManager)에서 파라미터 치환 시 빈 값이 전송될 수 있습니다. 이를 처리하기 위해 `prepareForValidation()`에서 빈 필터를 자동 제거합니다:

| 상황 | 처리 방식 |
|------|----------|
| `filters[0][value]`가 빈 문자열 | 해당 filter 항목 제거 |
| 모든 filters가 제거됨 | `filters`를 `null`로 설정 |
| 유효한 필터만 존재 | 인덱스 재정렬 후 검증 진행 |

### 'all' 필드 검색

`field`가 `'all'`인 경우 모든 SEARCHABLE_FIELDS를 OR 조건으로 검색합니다. HasMultipleSearchFilters Trait에서 이를 처리합니다.

### API 사용 예시

```bash
# 단일 필터
GET /api/admin/users?filters[0][field]=name&filters[0][value]=홍

# 다중 필터 (AND 조건)
GET /api/admin/users?filters[0][field]=name&filters[0][value]=홍&filters[1][field]=email&filters[1][value]=example

# 연산자 지정
GET /api/admin/users?filters[0][field]=name&filters[0][value]=홍길동&filters[0][operator]=eq
```

### 다른 Repository에서 재사용

```php
class ProductRepository
{
    use HasMultipleSearchFilters;

    private const SEARCHABLE_FIELDS = ['name', 'sku', 'description'];

    // 동일한 패턴으로 검색 구현
}
```

---

## 모듈에서 Repository 인터페이스 바인딩

모듈 내에서 Repository를 사용할 때도 반드시 인터페이스를 통해 DI해야 합니다.

### 모듈 Repository 구조

```text
modules/sirsoft-ecommerce/src/
├── Contracts/
│   └── Repositories/
│       └── ProductRepositoryInterface.php
├── Repositories/
│   └── ProductRepository.php
├── Services/
│   └── ProductService.php
└── Providers/
    └── EcommerceServiceProvider.php
```

### 모듈 인터페이스 정의

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Repositories;

use Modules\Sirsoft\Ecommerce\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProductRepositoryInterface
{
    public function findById(int $id): ?Product;
    public function getPaginated(array $filters = []): LengthAwarePaginator;
    public function create(array $data): Product;
    public function update(int $id, array $data): Product;
    public function delete(int $id): bool;
}
```

### 모듈 ServiceProvider에서 바인딩

모듈의 ServiceProvider에서 인터페이스와 구현체를 바인딩합니다:

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Sirsoft\Ecommerce\Contracts\Repositories\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\ProductRepository;

class EcommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerRepositoryBindings();
    }

    private function registerRepositoryBindings(): void
    {
        $this->app->bind(ProductRepositoryInterface::class, ProductRepository::class);
        // 추가 Repository 바인딩...
    }
}
```

### 모듈 Service에서 인터페이스 사용

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\Contracts\Repositories\ProductRepositoryInterface;

class ProductService
{
    public function __construct(
        private ProductRepositoryInterface $productRepository  // ✅ 인터페이스 타입힌트
    ) {}
}
```

### 올바른 사용 vs 잘못된 사용

```php
// ❌ DON'T: 구체 클래스 직접 타입힌트
use Modules\Sirsoft\Ecommerce\Repositories\ProductRepository;

class ProductService
{
    public function __construct(
        private ProductRepository $productRepository  // 구체 클래스 직접 의존
    ) {}
}

// ✅ DO: 인터페이스 타입힌트
use Modules\Sirsoft\Ecommerce\Contracts\Repositories\ProductRepositoryInterface;

class ProductService
{
    public function __construct(
        private ProductRepositoryInterface $productRepository  // 인터페이스에 의존
    ) {}
}
```

### 핵심 원칙 요약

| 원칙 | 설명 |
|------|------|
| **인터페이스 의존** | Service/Controller는 Repository 인터페이스에 의존 |
| **ServiceProvider 바인딩** | 인터페이스-구현체 매핑은 ServiceProvider에서 수행 |
| **테스트 용이성** | Mock 객체로 쉽게 대체 가능 |
| **유연한 교체** | 바인딩만 변경하면 다른 구현체 사용 가능 |

---

## 중첩 리소스 스코프

라우트에 상위 리소스 ID 가 있는 중첩 엔드포인트(`/posts/{postId}/comments/{id}`,
`/products/{productId}/images/{imageId}` 등)에서는 **Repository 의 where 절이 스코프의
SSoT** 다. 컨트롤러에서 조회 후 `if ($comment->post_id !== $postId)` 로 비교하는 방식은
신규 호출처가 생기면 그대로 무력화된다.

```php
// ❌ 게시판 범위로만 조회 — 다른 게시글의 댓글도 잡힌다
public function findOrFail(string $slug, int $id): Comment

// ✅ 상위 스코프를 선택 파라미터로 받아 where 절에 반영
public function findOrFail(string $slug, int $id, ?int $postId = null): Comment
{
    return Comment::query()
        ->where('board_id', $board->id)
        ->when($postId !== null, fn ($q) => $q->where('post_id', $postId))
        ->findOrFail($id);
}
```

### 규율

| 항목 | 규칙 |
| --- | --- |
| 방어 위치 | Repository where 절 (SSoT) + Service 가 상위 ID 를 전달 |
| 파라미터 형태 | **선택 파라미터**(`?int $postId = null`) — 필수화는 Interface breaking 이라 이 확장에 의존하는 확장을 전부 흔든다 |
| 전달 누락 방지 | 컨트롤러가 라우트 파라미터를 실제로 소비하는지 정적 검사가 감시 (경고) |
| 응답 코드 | 조회 실패이므로 404. 요청 본문 배열 항목의 스코프 위반은 422 (validation.md "배열 항목의 상위 스코프") |
| 공통 추상화 | 도입하지 않는다 — 모듈마다 상위 키와 조회 경로가 다르고(`board_id`+`post_id` / `product_id` / `page_id` / `order_id`), 코어 표면이 늘면 확장 버전 제약 동기화가 연쇄된다 |

---

## 보안 게이트 대칭성 (KVE-2026-1914/1919)

접근 게이트와 권한 등급 상한은 데이터를 내보내는 **한 경로에만** 있으면 다른 경로가
조용한 우회로가 된다(예외·오류·로그 없이 원문만 새 나간다). 판정을 한 지점으로 모으고
(SSoT), 같은 데이터를 서빙하는 모든 소비 경로가 그 지점을 경유하게 한다.

### 비밀 부모 → 하위 리소스 게이트 재적용

비밀/비공개 부모(게시글)에 종속된 하위 리소스(댓글·첨부·문의)는 **각자의 독립
엔드포인트**를 가진다. 부모 상세 Resource(PostResource) 한 곳에만 마스킹을 두면, 하위
엔드포인트가 부모의 비밀 상태를 검사하지 않고 해시/ID 만으로 원문을 반환한다.

| 항목 | 규칙 |
| --- | --- |
| 판정 SSoT | 열람 판정은 단일 게이트(예: `SecretContentGate::canView($post)`)에 모은다 — 작성자 본인 / 비밀번호 검증 / `posts.read-secret` / manager 규칙을 한 곳에서 |
| 재적용 지점 | 하위 리소스를 서빙하는 **모든** 경로 — 목록 컨트롤러, 상세 Resource, 이커머스 연동 훅, 첨부 다운로드/미리보기 서비스 |
| 목록 차단 | 부모가 비밀이고 무권한이면 하위 목록은 **빈 컬렉션**을 반환해 하위 항목이 Resource 에 도달조차 하지 않게 한다(1차 방어) |
| fail-closed | 슬러그·부모를 해석할 수 없으면 안전하게 마스킹(false)으로 실패 — 첨부 서빙은 상세와 분리된 요청이라 `password_verified` 가 없으므로 해시만으로는 비밀 첨부를 못 가져간다 |

### hash 기반 file-serving 게이트

hash/ID 로 파일을 서빙하는 라우트(`preview`/`download`)는 **소유권·비밀·발행 상태**를
반드시 거친다. `preview` 가 공개 썸네일 정책상 permission 미들웨어 없이(`optional.sanctum`)
열려 있으면, 컨트롤러/서비스의 게이트만이 미인증 공격자(해시만 쥔 게스트)를 막는 유일한
방어선이다 — `preview` 와 `download` 가 **동일 게이트**를 공유해야 한 쪽이 우회로가 되지 않는다.

| 응답 | 상황 |
| --- | --- |
| 403 | 인증 사용자가 비밀/삭제 게이트에 걸림(`AccessDeniedHttpException`) |
| 401 | 게스트가 permission 미들웨어(`download`)에 걸림 |
| 정상 서빙 | 발행 + 비밀 아님(또는 열람 권한 보유) |

### User/Role 등급 상한 대칭 (rank ceiling)

삭제 경로에만 있던 슈퍼 관리자·보호 역할 보호를 **수정·상태변경·권한부여** 경로까지
대칭 적용한다. 판정은 `UserGradeGuard::mayModify($target, $actor)` 단일 게이트로 모은다
(대상이 슈퍼면 액터도 슈퍼여야 수정 가능). 정적 라우트인 일괄(bulk) 엔드포인트는 스코프
미들웨어가 우회되므로 **서비스 계층에서 강제**하며, 일괄 대상 목록은 `filterModifiable`
로 수정 불가 대상을 걸러낸다(액터 등급 기준 — 액터를 무시하고 대상만 보고 제외하면
슈퍼 actor 의 정상 수행이 조용히 막힌다).

> 저장측(FormRequest) 검증 강도 대칭과 레이아웃 표현식 검증 부착은 [validation.md "보안 게이트 대칭성"](validation.md) 참조.

---

## 설정 기반 한계값

설정값으로 정해지는 한계(최대 깊이, 최대 개수 등)의 **검증 책임은 검증 계층 단일**이다.
Service 에서 리터럴로 다시 클램프하지 않는다.

```php
// ❌ Service 재클램프 — 설정값이 10 이어도 5 를 넘지 못한다
$data['depth'] = min(($parent->depth ?? 0) + 1, 5);

// ✅ 계산만 하고, 상한 검증은 Rule 이 게시판 설정으로 판정
$data['depth'] = ($parent->depth ?? 0) + 1;
```

이중 클램프는 두 가지를 동시에 망가뜨린다. 저장값이 설정과 무관하게 고정되고(계층 표시 붕괴),
검증 계층의 `depth + 1 > max` 조건이 영원히 거짓이 되어 **깊이 제한 자체가 무력화**된다.
값이 상한을 넘으면 조용히 깎지 말고 422 로 거절해야 한다.

이 패턴은 리터럴과 설정 키를 잇는 의미 추론이 필요해 정적 검출이 어렵다
(`coverage.json` 의 `service-hardcoded-limit-vs-settings` = `not-applicable`).
코드 리뷰에서 리터럴 상한을 볼 때마다 "이 값을 정하는 설정이 따로 있는가" 를 확인한다.

---

## 관련 문서

- [컨트롤러 계층 구조](controllers.md) - Controller에서 Service 사용
- [검증 로직 구현](validation.md) - FormRequest 검증 규칙
- [DTO 사용 규칙](dto.md) - Service 가 반환하는 DTO 의 두 패턴 (Value Object / Data Carrier)
- [index.md](index.md) - 백엔드 가이드 전체 목차