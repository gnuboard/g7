# API 응답 규칙 (ResponseHelper)

> **관련 문서**: [index.md](index.md) | [controllers.md](controllers.md) | [api-resources.md](api-resources.md)

---

## TL;DR (5초 요약)

```text
1. 모든 API 응답은 ResponseHelper 사용
2. 인수 순서: success($messageKey, $data) - 메시지가 먼저!
3. 데이터 소스 API: dataSource() 메서드 사용
4. 페이지네이션: paginated() 또는 LengthAwarePaginator
5. 에러: error($messageKey, $statusCode)
```

---

## 목차

1. [ResponseHelper 사용](#responsehelper-사용)
2. [데이터 소스용 API](#데이터-소스용-api-)
3. [응답 구조](#응답-구조)
4. [프론트엔드 바인딩](#프론트엔드-바인딩)
5. [구현 예시](#구현-예시)
6. [인증 처리](#인증-처리)
7. [성능 최적화](#성능-최적화)
8. [에러 처리](#에러-처리)
9. [페이지네이션 처리](#페이지네이션-처리-)
10. [라우트/쿼리 파라미터 처리](#라우트쿼리-파라미터-처리)
11. [체크리스트](#체크리스트)
12. [안티 패턴 vs 모범 사례](#안티-패턴-vs-모범-사례)
13. [HTTP 캐시 (ETag, 304 Not Modified)](#http-캐시-etag-304-not-modified)

---

## ResponseHelper 사용

### 규칙

- 모든 API 응답은 ResponseHelper 사용
- 일관된 응답 구조 유지

### 메서드 시그니처

```
주의: 인수 순서 확인!
success($messageKey, $data) - 메시지가 먼저!
❌ success($data, $messageKey) - 잘못된 순서
```

```php
// 성공 응답
ResponseHelper::success(
    string $messageKey = 'messages.success',  // 첫 번째: 메시지 키
    mixed $data = null,                       // 두 번째: 데이터
    int $statusCode = 200,
    array $messageParams = [],
    string $domain = 'core'
);

// 오류 응답
ResponseHelper::error(
    string $messageKey = 'messages.failed',   // 첫 번째: 메시지 키
    int $statusCode = 400,
    mixed $errors = null,
    array $messageParams = [],
    string $domain = 'core'
);

// 검증 오류
ResponseHelper::validationError($errors, $messageKey = 'messages.validation_failed');

// 404 응답
ResponseHelper::notFound($messageKey = 'messages.not_found');

// 401 응답
ResponseHelper::unauthorized($messageKey = 'messages.unauthorized');

// 403 응답
ResponseHelper::forbidden($messageKey = 'messages.forbidden');

// 500 응답
ResponseHelper::serverError($messageKey = 'messages.error_occurred', $error = null);
```

### 사용 예시

```php
// ✅ 올바른 사용
return ResponseHelper::success('messages.success', $data);
return ResponseHelper::success('plugins.settings.updated', $settings);

// ❌ 잘못된 사용 - TypeError 발생!
return ResponseHelper::success($data, 'messages.success');  // 인수 순서 틀림
```

---

## 데이터 소스용 API ⭐

**정의**: 템플릿 레이아웃 JSON의 `data_sources`에서 호출되는 API 엔드포인트

**목적**:
- 프론트엔드 레이아웃 렌더링 시 필요한 데이터 제공
- DataSourceManager를 통한 자동 fetch 지원
- Progressive loading을 통한 빠른 초기 렌더링

### 필수 요구사항

```
필수: 데이터 소스용 API는 아래 규칙 준수
필수: ResponseHelper 사용
✅ 필수: 인증 필요 시 auth_required: true 설정
✅ 권장: 응답 시간 200ms 이내
```

---

## 응답 구조

데이터 소스용 API는 반드시 `ResponseHelper::success()`를 사용하여 다음 형태로 응답해야 합니다:

```php
// 표준 응답 구조
return ResponseHelper::success('messages.success', $data);

// 실제 응답 JSON
{
  "success": true,
  "data": {
    // 실제 데이터 (이 부분이 프론트엔드에서 {{dataSourceId.data}}로 바인딩됨)
  },
  "message": null,
  "error": null
}
```

---

## 프론트엔드 바인딩

```json
{
  "data_sources": [
    {
      "id": "admin_menu",
      "endpoint": "/api/admin/menus"
    }
  ],
  "components": [
    {
      "props": {
        "menu": "{{admin_menu.data}}"
      }
    }
  ]
}
```

위 예시에서 `{{admin_menu.data}}`는 API 응답의 `data` 필드를 참조합니다.

---

## 구현 예시

### 기본 컨트롤러 구현

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;

class MenuController extends AdminBaseController
{
    public function __construct(
        private MenuService $menuService
    ) {}

    /**
     * 관리자 메뉴 목록 조회 (데이터 소스용)
     */
    public function index(): JsonResponse
    {
        // 1. 서비스에서 데이터 조회
        $menus = $this->menuService->getAdminMenus();

        // 2. ResponseHelper로 응답 (필수)
        return ResponseHelper::success('messages.success', $menus);
    }
}
```

### API 리소스 사용 예시

```php
<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Helpers\ResponseHelper;
use App\Http\Resources\Admin\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AuthController extends AdminBaseController
{
    /**
     * 현재 인증된 사용자 정보 조회 (데이터 소스용)
     */
    public function user(): JsonResponse
    {
        $user = Auth::user();

        // API 리소스로 변환 후 ResponseHelper 사용
        return ResponseHelper::success('messages.success', new UserResource($user));
    }
}
```

---

## 인증 처리

데이터 소스에서 `auth_required: true`로 설정된 경우:

```json
{
  "id": "current_user",
  "type": "api",
  "endpoint": "/api/admin/auth/user",
  "auth_required": true
}
```

백엔드에서는 라우트에 인증 미들웨어를 반드시 적용해야 합니다:

```php
// routes/api.php
Route::middleware(['auth:sanctum', 'type:admin'])->group(function () {
    Route::get('/admin/auth/user', [AuthController::class, 'user']);
    Route::get('/admin/menus', [MenuController::class, 'index']);
    Route::get('/admin/notifications', [NotificationController::class, 'index']);
});
```

---

## 성능 최적화

데이터 소스 API는 레이아웃 로딩 시 자동으로 호출되므로 성능이 중요합니다:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class MenuService
{
    /**
     * 관리자 메뉴 조회 (캐싱 적용)
     */
    public function getAdminMenus(): array
    {
        // 1. 캐시 사용 — CacheInterface DI (드라이버가 `g7:core:` 접두사 자동 적용)
        return $this->cache->remember('admin_menus', function () {
            return $this->repository->getActiveMenus();
        }, 3600);
    }

    /**
     * N+1 쿼리 방지
     */
    public function getMenusWithPermissions(): array
    {
        // 2. Eager Loading 사용
        return $this->repository
            ->with(['permissions', 'children'])
            ->where('active', true)
            ->get()
            ->toArray();
    }
}
```

---

## 에러 처리

데이터 소스 API에서 에러 발생 시:

```php
public function index(): JsonResponse
{
    try {
        $data = $this->service->getData();
        return ResponseHelper::success('messages.success', $data);
    } catch (\Exception $e) {
        // 에러 로그 기록
        Log::error('Failed to fetch data source', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        // 클라이언트에 에러 응답
        return ResponseHelper::error(
            __('messages.failed_to_load_data'),
            500
        );
    }
}
```

프론트엔드에서는:
- `loading_strategy: "progressive"`: 에러 발생 시에도 렌더링 계속 (빈 데이터)
- `loading_strategy: "blocking"`: 에러 발생 시 빈 데이터로 렌더링
- `DataSourceManagerOptions.onError` 콜백으로 에러 핸들링

---

## 페이지네이션 처리 ⭐

목록 API에서 페이지네이션 사용 시, 반드시 `pagination` 객체로 분리하여 반환:

```php
public function index(Request $request): JsonResponse
{
    $perPage = $request->input('per_page', 15);
    $page = $request->input('page', 1);

    $users = $this->userService->getPaginatedUsers($perPage, $page);

    // ✅ 표준 페이지네이션 응답 구조
    return ResponseHelper::success('messages.success', [
        'data' => $users->items(),
        'pagination' => [
            'total' => $users->total(),
            'from' => $users->firstItem() ?? 0,
            'to' => $users->lastItem() ?? 0,
            'per_page' => $users->perPage(),
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
        ],
    ]);
}
```

### 프론트엔드 바인딩 경로

```json
{
  "text": "$t:admin.users.pagination_info|total={{users?.data?.pagination?.total ?? 0}}|from={{users?.data?.pagination?.from ?? 0}}|to={{users?.data?.pagination?.to ?? 0}}"
}
```

> **주의**: `data.pagination.total`이 올바른 경로입니다. `data.data.total`이 아님!

---

## 라우트/쿼리 파라미터 처리

데이터 소스에서 파라미터 바인딩:

```json
{
  "id": "product_detail",
  "endpoint": "/api/admin/products/{{route.id}}",
  "params": {
    "include": "{{query.include}}"
  }
}
```

백엔드 컨트롤러:

```php
public function show(Request $request, int $id): JsonResponse
{
    $include = $request->input('include', []);

    $product = $this->productService->getProductById($id, $include);

    if (!$product) {
        return ResponseHelper::notFound('messages.product_not_found');
    }

    return ResponseHelper::success('messages.success', $product);
}
```

---

## 체크리스트

데이터 소스용 API 구현 시 반드시 확인:

```
□ ResponseHelper::success() 사용
□ auth_required: true 시 인증 미들웨어 적용
□ 응답 시간 200ms 이내 (캐싱, Eager Loading 등)
□ N+1 쿼리 방지
□ API 리소스로 데이터 변환 (일관성)
□ 에러 핸들링 (try-catch)
□ 페이지네이션 표준 구조
□ 로깅 (에러 발생 시)
```

---

## 안티 패턴 vs 모범 사례

### 안티 패턴

```php
// ❌ DON'T: ResponseHelper 미사용
public function index(): JsonResponse
{
    $data = $this->service->getData();
    return response()->json(['data' => $data]); // 구조 불일치
}

// ❌ DON'T: 직접 배열 반환
public function index(): array
{
    return $this->service->getData(); // JsonResponse 타입 위반
}

// ❌ DON'T: 인증 미들웨어 누락
// auth_required: true인데 라우트에 인증 미들웨어 없음
Route::get('/admin/menus', [MenuController::class, 'index']); // 취약점

// ❌ DON'T: 느린 응답 (500ms+)
public function index(): JsonResponse
{
    $data = $this->repository->all(); // N+1 쿼리 발생
    return ResponseHelper::success('messages.success', $data);
}
```

### 모범 사례

```php
// ✅ DO: 완전한 구현 예시
<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Resources\Admin\MenuResource;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class MenuController extends AdminBaseController
{
    public function __construct(
        private MenuService $menuService
    ) {}

    /**
     * 관리자 메뉴 목록 조회
     *
     * 데이터 소스에서 사용:
     * - endpoint: /api/admin/menus
     * - auth_required: true
     * - loading_strategy: progressive
     */
    public function index(): JsonResponse
    {
        try {
            // 1. 서비스에서 캐시된 데이터 조회
            $menus = $this->menuService->getAdminMenus();

            // 2. API 리소스로 변환 (일관성)
            $resource = MenuResource::collection($menus);

            // 3. ResponseHelper로 응답 (필수)
            return ResponseHelper::success('messages.success', $resource);

        } catch (\Exception $e) {
            // 4. 에러 로그 기록
            Log::error('Failed to load admin menus', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 5. 에러 응답 (프론트엔드에서 처리)
            return ResponseHelper::error(
                __('messages.failed_to_load_menus'),
                500
            );
        }
    }
}
```

---

## HTTP 캐시 (ETag, 304 Not Modified)

정적 데이터나 변경 빈도가 낮은 API에는 HTTP 캐시를 적용하여 전송 효율성을 높일 수 있습니다.

### successWithCache 사용

`BaseApiController`에서 제공하는 `successWithCache()` 메서드를 사용합니다:

```php
// 캐시 헤더와 함께 성공 응답 반환
return $this->successWithCache(
    'messages.success',  // 메시지 키
    $data,               // 응답 데이터
    3600                 // 캐시 TTL (초, 기본: 1시간)
);
```

### 동작 방식

1. **첫 요청**: ETag 생성 → 200 OK + `ETag`, `Cache-Control` 헤더
2. **재요청**: 클라이언트가 `If-None-Match` 헤더로 ETag 전송
3. **ETag 일치**: 304 Not Modified (본문 없음, 헤더만)
4. **ETag 불일치**: 새 데이터와 함께 200 OK

### 응답 헤더

```http
HTTP/1.1 200 OK
Cache-Control: max-age=3600, public
ETag: "7d74afa3f6f8c8a3992772a87b3475b1"
Vary: Accept-Encoding, Accept-Language
```

### 적용 대상

| 적합             | 부적합             |
| ---------------- | ------------------ |
| 레이아웃 JSON    | 실시간 데이터      |
| 메뉴 목록        | 사용자별 데이터    |
| 설정/환경값      | 자주 변경되는 목록 |
| 번역 데이터      | 인증 정보          |

### PublicLayoutController 예시

```php
<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;

class PublicLayoutController extends PublicBaseController
{
    private const CACHE_TTL = 3600;

    public function serve(string $templateId, string $layoutName): JsonResponse|Response
    {
        // 서버 측 캐싱
        $mergedLayout = $this->cached(
            "layout.{$templateId}.{$layoutName}",
            fn () => $this->layoutService->loadAndMergeLayout($templateId, $layoutName),
            self::CACHE_TTL
        );

        // HTTP 캐시 헤더 (ETag, Cache-Control) 포함 응답
        return $this->successWithCache(
            'messages.layout_served',
            $mergedLayout,
            self::CACHE_TTL
        );
    }
}
```

### 관련 메서드 (BaseApiController)

| 메서드                                         | 설명                             |
| ---------------------------------------------- | -------------------------------- |
| `generateETag($data)`                          | 데이터 기반 ETag 생성            |
| `isNotModified($etag)`                         | 클라이언트 캐시 유효성 확인      |
| `notModifiedResponse($etag, $maxAge)`          | 304 응답 반환                    |
| `successWithCache($message, $data, $maxAge)`   | ETag + Cache-Control 포함 응답   |

---

## 관련 문서

- [index.md](index.md) - 백엔드 가이드 인덱스
- [controllers.md](controllers.md) - 컨트롤러 계층 구조
- [api-resources.md](api-resources.md) - API 리소스 규칙
- [routing.md](routing.md) - 라우트 네이밍 및 경로
