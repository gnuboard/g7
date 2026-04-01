# 라우트 네이밍 및 경로

> **상위 문서**: [백엔드 가이드 인덱스](./index.md)

---

## TL;DR (5초 요약)

```text
1. 모든 라우트는 name() 필수: ->name('api.users.index')
2. 접두사: api.*, web.*, vendor-module.*
3. URL: /api/admin/*, /api/auth/*, /api/public/*
4. 권한: permission: 또는 Middleware에서 체크
5. REST 패턴: index, store, show, update, destroy
```

---

## 목차

1. [라우트 이름 규칙](#라우트-이름-규칙)
2. [URL 경로 규칙](#url-경로-규칙)
3. [권한 체크](#권한-체크)
4. [라우트 정의 예시](#라우트-정의-예시)
5. [개발 체크리스트](#개발-체크리스트)

---

## 라우트 이름 규칙

### 필수 원칙

- **모든 라우트는 `name` 필수**
- 일관된 네이밍 컨벤션 준수

### 접두사 규칙

| 타입 | 접두사 | 예시 |
|------|--------|------|
| API | `api.` | `api.users.index` |
| WEB | `web.` | `web.dashboard` |
| 모듈 | `[vendor-module].` | `sirsoft-ecommerce.products.index` |
| 플러그인 | `[vendor-plugin].` | `sirsoft-payment.settings` |

### 조합 규칙

```php
// 코어 API
->name('api.users.index')
->name('api.users.store')

// 모듈 API
->name('api.sirsoft-ecommerce.products.index')
->name('api.sirsoft-ecommerce.products.store')

// 플러그인 API
->name('api.sirsoft-payment.transactions.index')
```

---

## URL 경로 규칙

### 경로 패턴

| 타입 | 패턴 | 예시 |
|------|------|------|
| 코어 | `/admin/[기능명]` | `/admin/users` |
| 모듈 | `/admin/[vendor-module]/[기능명]` | `/admin/sirsoft-ecommerce/products` |
| 플러그인 | `/admin/[vendor-plugin]/[기능명]` | `/admin/sirsoft-payment/settings` |
| 공개 API | `/api/[vendor-module]/[기능명]` | `/api/sirsoft-ecommerce/products` |

### 리소스 URL 규칙

```text
# 목록 조회
GET /admin/sirsoft-ecommerce/products

# 단일 조회
GET /admin/sirsoft-ecommerce/products/{id}

# 생성
POST /admin/sirsoft-ecommerce/products

# 수정
PUT /admin/sirsoft-ecommerce/products/{id}

# 삭제
DELETE /admin/sirsoft-ecommerce/products/{id}
```

### 라이선스 API 라우트

코어 및 확장의 LICENSE 파일 내용을 반환하는 API 엔드포인트입니다.

```text
GET /api/admin/license                          # 코어 LICENSE 반환
GET /api/admin/modules/{identifier}/license     # 모듈 LICENSE 반환
GET /api/admin/plugins/{identifier}/license     # 플러그인 LICENSE 반환
GET /api/admin/templates/{identifier}/license   # 템플릿 LICENSE 반환
```

---

## 권한 체크

### 권한 체크 방식

```text
주의: FormRequest의 authorize() 메서드 사용 금지
필수: 라우트에 permission 미들웨어 체인
```

### 권한 미들웨어 사용

```php
// ✅ DO: 라우트에 permission 미들웨어 사용
Route::get('/products', [ProductController::class, 'index'])
    ->middleware('permission:sirsoft-ecommerce.products.view')
    ->name('api.sirsoft-ecommerce.products.index');

// ❌ DON'T: FormRequest에서 권한 체크
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 이 방식 사용 금지
        return $this->user()->can('view-products');
    }
}
```

### 권한 네이밍 규칙

```text
[vendor-module].[resource].[action]

예시:
sirsoft-ecommerce.products.view
sirsoft-ecommerce.products.create
sirsoft-ecommerce.products.edit
sirsoft-ecommerce.products.delete
```

---

## 라우트 정의 예시

### 모듈 라우트 파일

```php
// modules/sirsoft-ecommerce/src/routes/api.php

use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Controllers\Api\Admin\ProductController;

Route::prefix('admin/sirsoft-ecommerce')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 상품 관리 (권한 체크 포함)
    Route::get('/products', [ProductController::class, 'index'])
        ->middleware('permission:sirsoft-ecommerce.products.view')
        ->name('api.sirsoft-ecommerce.products.index');

    Route::post('/products', [ProductController::class, 'store'])
        ->middleware('permission:sirsoft-ecommerce.products.create')
        ->name('api.sirsoft-ecommerce.products.store');

    Route::get('/products/{id}', [ProductController::class, 'show'])
        ->middleware('permission:sirsoft-ecommerce.products.view')
        ->name('api.sirsoft-ecommerce.products.show');

    Route::put('/products/{id}', [ProductController::class, 'update'])
        ->middleware('permission:sirsoft-ecommerce.products.edit')
        ->name('api.sirsoft-ecommerce.products.update');

    Route::delete('/products/{id}', [ProductController::class, 'destroy'])
        ->middleware('permission:sirsoft-ecommerce.products.delete')
        ->name('api.sirsoft-ecommerce.products.destroy');
});
```

### 코어 라우트 파일

```php
// routes/api.php

use App\Http\Controllers\Api\Admin\UserController;

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 사용자 관리
    Route::get('/users', [UserController::class, 'index'])
        ->middleware('permission:users.view')
        ->name('api.users.index');

    Route::post('/users', [UserController::class, 'store'])
        ->middleware('permission:users.create')
        ->name('api.users.store');
});
```

### 권한 바이패스 라우트 (except 옵션)

자기 자신 또는 소유자에 대해 권한 체크를 바이패스하는 라우트:

```php
// routes/api.php

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 사용자 수정: 자기 자신은 core.users.update 권한 없이 수정 가능
    Route::put('/users/{user}', [UserController::class, 'update'])
        ->middleware('permission:admin,core.users.update,except:self:user')
        ->name('api.admin.users.update');

    // 메뉴 수정: 소유자는 core.menus.update 권한 없이 수정 가능 (향후 적용 예시)
    Route::put('/menus/{menu}', [MenuController::class, 'update'])
        ->middleware('permission:admin,core.menus.update,except:owner:menu')
        ->name('api.admin.menus.update');
});
```

> 상세 문법: [middleware.md](middleware.md) "permission 미들웨어 except 옵션" 참조

### 공개 API 라우트

```php
// modules/sirsoft-ecommerce/src/routes/api.php

use Modules\Sirsoft\Ecommerce\Controllers\Api\Public\ProductController;

Route::prefix('api/sirsoft-ecommerce')->group(function () {
    // 공개 상품 API (인증 불필요)
    Route::get('/products', [ProductController::class, 'index'])
        ->name('api.sirsoft-ecommerce.public.products.index');

    Route::get('/products/{id}', [ProductController::class, 'show'])
        ->name('api.sirsoft-ecommerce.public.products.show');
});
```

---

## 개발 체크리스트

### 라우트 정의 시 확인사항

- [ ] 라우트에 `name()` 메서드로 이름 지정
- [ ] 적절한 접두사 사용 (api., web., vendor-module.)
- [ ] URL 경로 규칙 준수 (/admin/[vendor-module]/[기능명])
- [ ] 권한이 필요한 라우트에 `permission` 미들웨어 적용
- [ ] 인증이 필요한 라우트에 `auth:sanctum` 미들웨어 적용
- [ ] 관리자 라우트에 `admin` 미들웨어 적용
- [ ] FormRequest의 `authorize()` 메서드에서 권한 체크하지 않음

### 라우트 테스트 확인사항

- [ ] 인증 없이 접근 시 401 반환
- [ ] 권한 없이 접근 시 403 반환
- [ ] 올바른 권한으로 접근 시 성공

---

## 관련 문서

- [컨트롤러 계층 구조](./controllers.md) - 컨트롤러 네이밍 규칙
- [미들웨어 등록 규칙](./middleware.md) - 미들웨어 실행 순서
- [검증 로직 구현](./validation.md) - FormRequest 사용 규칙

### SEO 라우트

| URL | 메서드 | 라우트명 | 컨트롤러 | 비고 |
|-----|--------|---------|---------|------|
| /sitemap.xml | GET | web.sitemap | SitemapController@index | catch-all보다 위에 정의 |
| /api/admin/seo/stats | GET | api.admin.seo.stats | SeoCacheController | 관리자 전용 |
| /api/admin/seo/clear-cache | POST | api.admin.seo.clear-cache | SeoCacheController | 관리자 전용 |
| /api/admin/seo/warmup | POST | api.admin.seo.warmup | SeoCacheController | 관리자 전용 |
| /api/admin/seo/cached-urls | GET | api.admin.seo.cached-urls | SeoCacheController | 관리자 전용 |

> 상세: [seo-system.md](seo-system.md)
