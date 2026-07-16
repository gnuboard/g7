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
```

---

## 목차

- [개요](#개요)
- [Repository 인터페이스](#repository-인터페이스)
- [Service 클래스](#service-클래스)
- [트랜잭션 및 관계 삭제 패턴](#트랜잭션-및-관계-삭제-패턴)
- [상태 변경 자동 처리](#상태-변경-자동-처리)
- [Repository 클래스](#repository-클래스)
- [다중 검색 필터 Trait](#다중-검색-필터-trait-hasmultiplesearchfilters)
- [모듈에서 Repository 인터페이스 바인딩](#모듈에서-repository-인터페이스-바인딩)
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

### 서비스 내부 조건부 권한 체크

미들웨어가 아닌 **서비스 내부**에서 추가적인 권한 체크가 필요한 경우 `PermissionHelper`를 사용합니다.
대표적인 예: 역할(role) 변경처럼 데이터의 일부 필드만 별도 권한이 필요한 경우.

```php
use App\Helpers\PermissionHelper;
use Illuminate\Support\Facades\Auth;

public function updateUser(User $user, array $data): User
{
    $roleIds = $data['role_ids'] ?? null;
    unset($data['role_ids'], $data['roles']);

    // 훅 실행 (생략)...

    $user = $this->userRepository->update($user->id, $data);

    // 역할 변경은 별도 권한으로 보호
    if ($roleIds !== null) {
        $authUser = Auth::user();

        // 자기 자신의 역할은 항상 변경 불가 (보안)
        if ($authUser && $authUser->id === $user->id) {
            $roleIds = null;
        }

        // core.permissions.update 권한 없으면 역할 변경 무시
        if ($roleIds !== null && ! PermissionHelper::check('core.permissions.update', $authUser)) {
            $roleIds = null;
        }

        if ($roleIds !== null) {
            $user->roles()->sync($roleIds);
        }
    }

    return $user;
}
```

```
필수: 역할/권한 변경은 미들웨어 권한과 별개로 서비스에서 명시적 체크 필수
필수: 자기 자신의 역할 변경은 항상 불가 (관리자 실수 방지)
패턴: 민감 필드 분리 → 별도 권한 체크 → 권한 없으면 해당 필드 무시 (403이 아닌 무시)
```

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

## 관련 문서

- [컨트롤러 계층 구조](controllers.md) - Controller에서 Service 사용
- [검증 로직 구현](validation.md) - FormRequest 검증 규칙
- [DTO 사용 규칙](dto.md) - Service 가 반환하는 DTO 의 두 패턴 (Value Object / Data Carrier)
- [index.md](index.md) - 백엔드 가이드 전체 목차