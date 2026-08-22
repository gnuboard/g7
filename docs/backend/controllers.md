# 컨트롤러 계층 구조

> **관련 문서**: [index.md](index.md) | [service-repository.md](service-repository.md) | [validation.md](validation.md)

---

## TL;DR (5초 요약)

```text
1. AdminBaseController / AuthBaseController / PublicBaseController 중 상속
2. Service 주입 필수 (Repository 직접 주입 금지)
3. FormRequest로 검증 (컨트롤러에 검증 로직 금지)
4. ResponseHelper 메서드 사용 (success, error, successWithResource)
5. 네이밍: [Entity][Action]Controller (예: UserListController)
6. array_filter 시 null만 제거 (빈 문자열은 사용자 의도로 보존)
```

---

## 목차

1. [Base 컨트롤러 계층](#base-컨트롤러-계층)
2. [각 컨트롤러 역할](#각-컨트롤러-역할)
3. [컨트롤러 네이밍 규칙](#컨트롤러-네이밍-규칙)
4. [응답 메서드 사용](#응답-메서드-사용)
5. [Controller 규칙](#controller-규칙)
6. [패턴 예시](#패턴-예시)
7. [데이터 필터링 패턴 (array_filter)](#데이터-필터링-패턴-array_filter)
8. [체크리스트](#체크리스트)

---

## Base 컨트롤러 계층

G7은 역할 기반의 컨트롤러 계층 구조를 사용합니다.

```text
BaseApiController (최상위)
├── AdminBaseController (관리자 전용)
├── AuthBaseController (인증된 사용자)
└── PublicBaseController (공개 API)
```

---

## 각 컨트롤러 역할

### BaseApiController

**경로**: `app/Http/Controllers/Api/Base/BaseApiController.php`

- 모든 API 컨트롤러의 최상위 부모 클래스
- 공통 응답 메서드 제공: `success()`, `error()`, `successWithResource()`, `notFound()`, `unauthorized()`, `forbidden()`, `validationError()`
- 인증 사용자 조회: `getCurrentUser()`

### AdminBaseController

**경로**: `app/Http/Controllers/Api/Base/AdminBaseController.php`

- BaseApiController 상속
- 관리자 전용 기능
- 미들웨어: `['auth:sanctum', 'admin']`
- 관리자 활동 로깅: `logAdminActivity()`

### AuthBaseController

**경로**: `app/Http/Controllers/Api/Base/AuthBaseController.php`

- BaseApiController 상속
- 인증된 사용자 전용 기능
- 미들웨어: `['auth:sanctum']`
- 리소스 소유권 확인: `isOwner()`, `canAccessResource()`, `checkOwnership()`
- 사용자 활동 로깅: `logUserActivity()`

### PublicBaseController

**경로**: `app/Http/Controllers/Api/Base/PublicBaseController.php`

- BaseApiController 상속
- 인증 불필요한 공개 API
- 미들웨어: `['throttle:60,1']` (속도 제한)
- 캐싱: `cached()`
- API 사용량 추적: `logApiUsage()`, `getClientInfo()`

---

## 컨트롤러 네이밍 규칙

### 코어

| 역할 | 경로 |
|------|------|
| 관리자 | `app/Http/Controllers/Api/Admin/[기능명]Controller.php` |
| 인증 사용자 | `app/Http/Controllers/Api/Auth/[기능명]Controller.php` |
| 공개 API | `app/Http/Controllers/Api/Public/[기능명]Controller.php` |

### 모듈

| 역할 | 경로 |
|------|------|
| 관리자 | `modules/[vendor-module]/src/Controllers/Api/Admin/[기능명]Controller.php` |
| 인증 사용자 | `modules/[vendor-module]/src/Controllers/Api/Auth/[기능명]Controller.php` |
| 공개 API | `modules/[vendor-module]/src/Controllers/Api/[기능명]Controller.php` |

---

## 응답 메서드 사용

모든 컨트롤러는 Base 클래스의 공통 메서드를 사용합니다:

```php
// ✅ DO: 공통 메서드 사용
return $this->success('messages.created', $data, 201);
return $this->error('messages.failed', 400);
return $this->successWithResource('messages.success', new UserResource($user));
return $this->notFound('messages.not_found');
return $this->unauthorized('messages.unauthorized');
return $this->forbidden('messages.forbidden');
return $this->validationError($errors, 'messages.validation_failed');

// ❌ DON'T: 역할별 래퍼 메서드 사용 (Deprecated)
// adminSuccess(), userSuccess(), publicSuccess() 등은 사용하지 않음
```

---

## Controller 규칙

### 원칙

- Service 클래스를 생성자 주입으로 받음
- 비즈니스 로직은 Service에 위임
- ResponseHelper 사용
- 인라인 검증 금지 (FormRequest 사용)

> **참고**: FormRequest 상세 규칙은 [validation.md](validation.md) 참조

---

## 패턴 예시

```php
<?php

namespace Modules\Sirsoft\Ecommerce\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Helpers\ResponseHelper;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Requests\StoreProductRequest;
use Modules\Sirsoft\Ecommerce\Requests\UpdateProductRequest;
use Modules\Sirsoft\Ecommerce\Resources\ProductResource;
use Illuminate\Http\JsonResponse;

class ProductController extends AdminBaseController
{
    public function __construct(
        private ProductService $productService
    ) {}

    /**
     * 상품 목록 조회
     */
    public function index(): JsonResponse
    {
        $products = $this->productService->getAllProducts();

        return ResponseHelper::success(
            ProductResource::collection($products),
            '상품 목록을 조회했습니다.'
        );
    }

    /**
     * 상품 생성
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->createProduct($request->validated());

        return ResponseHelper::success(
            new ProductResource($product),
            '상품이 생성되었습니다.',
            201
        );
    }

    /**
     * 상품 수정
     */
    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        $product = $this->productService->updateProduct($id, $request->validated());

        return ResponseHelper::success(
            new ProductResource($product),
            '상품이 수정되었습니다.'
        );
    }
}
```

---

## 데이터 필터링 패턴 (array_filter)

컨트롤러에서 요청 데이터를 Service에 전달하기 전에 `array_filter`로 불필요한 값을 제거하는 경우, **빈 문자열(`''`)과 `null`의 의미 차이**에 주의해야 합니다.

### null vs 빈 문자열의 의미

| 값 | 의미 | 예시 |
|------|------|------|
| `null` | 필드가 전송되지 않음 (미포함) | JSON body에 키 자체가 없음 |
| `''` (빈 문자열) | 사용자가 의도적으로 값을 비움 | 기존 값을 지우려는 의도 |

### 잘못된 예시 (빈 문자열 제거)

```php
// ❌ DON'T: 빈 문자열도 제거 → 사용자의 "값 비우기" 의도 무시
$data = collect($validated)
    ->filter(fn ($v) => $v !== null && $v !== '')
    ->toArray();
```

### 올바른 예시 (null만 제거)

```php
// ✅ DO: null만 제거 → 빈 문자열은 사용자 의도로 보존
$data = collect($validated)
    ->filter(fn ($v) => $v !== null)
    ->toArray();
```

### 적용 시점

| 상황 | 권장 패턴 |
|------|----------|
| 설정 Override (기본값 위에 덮어쓰기) | `null`만 제거 (빈 문자열 = 기본값으로 초기화) |
| 일반 CRUD 저장 | `validated()` 그대로 사용 (필터링 불필요) |
| 선택적 필드 업데이트 (PATCH) | `null`만 제거 또는 `filled()` 활용 |

---

## 표준 예외 처리 패턴

컨트롤러에서 Service 호출 시 다음 표준 예외 처리 패턴을 사용합니다:

```php
public function store(StoreProductRequest $request): JsonResponse
{
    try {
        $product = $this->productService->createProduct($request->validated());

        return $this->success('product.create_success', new ProductResource($product), 201);
    } catch (ValidationException $e) {
        return $this->validationError($e->errors(), 'product.create_failed');
    } catch (\Exception $e) {
        return $this->error('product.create_failed', 500);
    }
}
```

### 예외 유형별 처리

| 예외 유형 | 응답 메서드 | 상태 코드 | 용도 |
| ---------- | ----------- | ---------- | ------ |
| `ValidationException` | `validationError()` | 422 | Service에서 발생한 검증 예외 |
| `AccessDeniedHttpException` | `error()` | 403 | 스코프 접근 거부 |
| 커스텀 비즈니스 예외 | `error()` | 400~409 | 비즈니스 규칙 위반 |
| `\Exception` (범용) | `error()` | 500 | 예상치 못한 오류 |

### 스코프 접근 거부 처리 패턴

```php
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

public function show(int $id): JsonResponse
{
    try {
        $item = $this->service->getItem($id);
        return $this->success('item.fetch_success', new ItemResource($item));
    } catch (AccessDeniedHttpException $e) {
        return $this->error('auth.scope_denied', 403);
    } catch (\Exception $e) {
        return $this->error('item.fetch_failed', 500);
    }
}
```

### 주의사항

- `ValidationException`은 `\Exception`보다 **먼저** catch해야 함 (하위 클래스 우선)
- 에러 메시지는 반드시 `__()` 다국어 키 사용 (하드코딩 금지)
- `$e->getMessage()`는 **로깅 목적**으로만 사용 (사용자에게 직접 노출 금지)

---

## 체크리스트

### 컨트롤러 개발 체크리스트

- [ ] 적절한 Base 컨트롤러를 상속했는가?
  - 관리자 기능 → `AdminBaseController`
  - 인증 사용자 기능 → `AuthBaseController`
  - 공개 API → `PublicBaseController`
- [ ] Service 클래스를 생성자 주입으로 받았는가?
- [ ] 비즈니스 로직을 Service에 위임했는가?
- [ ] ResponseHelper를 사용해 응답했는가?
- [ ] FormRequest를 사용해 검증했는가? (인라인 검증 금지)
- [ ] 네이밍 규칙을 준수했는가?

---

## 관련 문서

- [index.md](index.md) - 백엔드 가이드 인덱스
- [service-repository.md](service-repository.md) - Service-Repository 패턴
- [validation.md](validation.md) - 검증 로직 구현 원칙
- [response-helper.md](response-helper.md) - API 응답 규칙
