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
6. .js/.css/.json/.map 로 끝나는 라우트 = dualSuffix/dualSuffixSegment/dualAsset 매크로 필수
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
| 모듈 공개 API | `/api/modules/[vendor-module]/[기능명]` | `/api/modules/sirsoft-ecommerce/products` |
| 플러그인 공개 API | `/api/plugins/[vendor-plugin]/[기능명]` | `/api/plugins/sirsoft-gdpr/consent` |

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

### 정적 확장자로 끝나는 동적 엔드포인트

`.js` / `.css` / `.json` / `.map` 으로 끝나는 라우트는 `Route::get()` 으로 단일 등록하지 않는다.
`Route::dualSuffix()` / `dualSuffixSegment()` / `dualAsset()` 매크로로 **확장자 형태와 확장자 없는 형태를
동시에** 등록한다. 코어 라우트와 확장 라우트 파일 모두에 적용된다.

nginx/Apache 의 표준적 정적 최적화 블록은 URL 마지막 확장자로 분기하며, nginx 에서 정규식 location 은
프리픽스 location 보다 먼저 매칭된다. 그래서 `try_files ... /index.php` 폴백이 실행될 기회 없이 nginx 가
직접 파일시스템을 열려 시도해 404 가 된다. aaPanel / CyberPanel / Plesk 기본 템플릿에 들어있는 블록이라
드물지 않으며, 그런 서버에서는 해당 엔드포인트가 통째로 죽는다.

```nginx
location ~* \.(js|css|json)$ { expires max; access_log off; }
```

```php
// 잘못된 등록 — 확장자 없는 형태가 생기지 않는다
Route::get('{identifier}/routes.json', [Ctrl::class, 'getRoutes'])->name('...');

// 접미사 제거형 — routes.json + routes
Route::dualSuffix('{identifier}/routes', 'json', [Ctrl::class, 'getRoutes'])
    ->name('api.public.templates.routes');

// 세그먼트 강등형 — 접미사가 종류를 구분해 제거 불가 (bundle.js + bundle/js)
Route::dualSuffixSegment('bundle', 'js', [Ctrl::class, 'serveBundleJs'])
    ->name('api.public.modules.bundle.js');

// 쿼리 이동형 — 경로가 곧 파일명 (assets/{id}/js/a.js + assets/{id}?file=js/a.js)
Route::dualAsset('assets/{identifier}', [Ctrl::class, 'serveAsset'])
    ->name('api.public.templates.assets');
```

매크로가 반환하는 프록시는 `name()` 을 양쪽에 적용하며(확장자 없는 쪽은 `.extensionless` 접미사),
`middleware()` 등 나머지 호출도 두 라우트에 함께 전달한다. 한쪽에만 걸리는 가드가 생기지 않는다.

URL 을 만드는 쪽도 문자열로 직접 조립하지 않는다. 서버는 `App\Support\AssetUrl`, 프론트엔드는
`resources/js/core/support/assetUrl.ts` 를 경유해야 현재 모드에 맞는 형태가 나온다. 두 구현은 동일 규칙을
공유하므로 한쪽만 바꾸면 서버가 만든 URL 과 클라이언트가 만든 URL 이 어긋나 그 자산만 404 가 된다.

자동 차단: 정적 검사 대상 (위반 시 차단). 면제는 인라인 주석
`// audit:allow dynamic-route-static-extension reason: ...`.

두 형태는 모두 영구 유지한다 — 확장자 형태를 제거하면 URL 을 하드코딩한 서드파티 확장이 깨진다.
엔드포인트별 변환 규칙 표와 프로브 판정 절차: [API 레퍼런스 진입점](./api/README.md) "자산 URL 이중 모드".

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
// modules/_bundled/sirsoft-ecommerce/src/routes/api.php

use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Controllers\Api\Admin\ProductController;

// ModuleRouteServiceProvider 가 URL prefix('api/modules/sirsoft-ecommerce')와
// name prefix('api.modules.sirsoft-ecommerce.')를 자동 적용한다.
// 라우트 파일 내부 group 에는 관리자 세그먼트('admin')만 두고, 접두는 중복 입력하지 않는다.
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 상품 관리 (권한 체크 포함) → 최종 URL: /api/modules/sirsoft-ecommerce/admin/products
    Route::get('/products', [ProductController::class, 'index'])
        ->middleware('permission:sirsoft-ecommerce.products.view')
        ->name('products.index'); // 최종 name: api.modules.sirsoft-ecommerce.products.index

    Route::post('/products', [ProductController::class, 'store'])
        ->middleware('permission:sirsoft-ecommerce.products.create')
        ->name('products.store');

    Route::get('/products/{id}', [ProductController::class, 'show'])
        ->middleware('permission:sirsoft-ecommerce.products.view')
        ->name('products.show');

    Route::put('/products/{id}', [ProductController::class, 'update'])
        ->middleware('permission:sirsoft-ecommerce.products.edit')
        ->name('products.update');

    Route::delete('/products/{id}', [ProductController::class, 'destroy'])
        ->middleware('permission:sirsoft-ecommerce.products.delete')
        ->name('products.destroy');
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

### 사용자 컨텍스트 라우트 (permission:user)

사용자(프론트엔드) 라우트는 `permission:user,...` 미들웨어와 **user 타입 권한 식별자**를 함께 사용합니다.

```php
// routes/api.php — 사용자 알림 라우트 예시

Route::middleware('auth:sanctum')->prefix('user')->group(function () {
    Route::prefix('notifications')->group(function () {
        Route::get('/', [UserNotificationController::class, 'index'])
            ->middleware('permission:user,core.user-notifications.read')
            ->name('api.user.notifications.index');

        Route::patch('{notification}/read', [UserNotificationController::class, 'markAsRead'])
            ->middleware('permission:user,core.user-notifications.update')
            ->name('api.user.notifications.read');

        Route::delete('{notification}', [UserNotificationController::class, 'destroy'])
            ->middleware('permission:user,core.user-notifications.delete')
            ->name('api.user.notifications.destroy');
    });
});
```

```text
⚠️ CRITICAL: 사용자 라우트에 admin 타입 권한 식별자를 사용하면 항상 403 응답
✅ 사용자 컨텍스트 권한은 별도 식별자(예: core.user-notifications.*)로 정의 + permission:user 미들웨어 사용
```

`permissions.identifier` 단일 unique 제약 때문에 같은 식별자로 admin/user 권한 두 행을 생성할 수 없습니다. 같은 도메인이라도 컨텍스트가 다르면 식별자를 분리하세요. 상세 규칙: [extension/permissions.md](../extension/permissions.md#권한-타입-permission-type)

### 공개 API 라우트

```php
// modules/_bundled/sirsoft-ecommerce/src/routes/api.php

use Modules\Sirsoft\Ecommerce\Controllers\Api\Public\ProductController;

// ModuleRouteServiceProvider 가 URL prefix('api/modules/sirsoft-ecommerce')와
// name prefix('api.modules.sirsoft-ecommerce.')를 자동 적용한다.
// 라우트 파일에서 prefix/name 접두를 중복 입력하지 않는다.
Route::prefix('products')->group(function () {
    // 공개 상품 API (인증 불필요) → 최종 URL: /api/modules/sirsoft-ecommerce/products
    Route::get('/', [ProductController::class, 'index'])
        ->name('public.products.index'); // 최종 name: api.modules.sirsoft-ecommerce.public.products.index

    Route::get('/{id}', [ProductController::class, 'show'])
        ->name('public.products.show');
});
```

---

## 라우트 캐시

`php artisan route:cache` 를 적용한 사이트는 캐시 파일에 직렬화된 라우트만 서빙한다.
따라서 **라우트 정의를 바꾸는 모든 지점은 캐시를 함께 갱신해야 한다.**

라우트 캐시는 훅 캐시와 성질이 다르다. 훅 캐시는 항목이 없으면 스캔으로 폴백해 낡아도
안전하지만, **라우트 캐시에는 폴백이 없다** — 캐시에 없는 라우트는 그대로 404 다.
예외도 경고도 남지 않고 그 엔드포인트만 조용히 사라지므로, 확장을 만든 쪽에서는
"내 코드에는 분명히 있는데 라우트가 없다" 는 형태로만 관측된다.

### 갱신은 헬퍼 하나로만 한다

각 지점에 `route:clear` 를 흩어 놓으면 누락이 생기고, 반대로 비우기만 하면 한 번 비워진
캐시가 재생성되지 않아 성능 이점이 영구히 사라진다. `App\Support\RouteCacheHelper` 가
단일 해석 지점이다 (`ConfigCacheHelper` 와 동일한 정책).

| 메서드 | 동작 |
|--------|------|
| `RouteCacheHelper::rebuild()` | 비운 뒤 즉시 재생성. 테스트 환경·설치 미완료는 비우기까지만 |
| `RouteCacheHelper::clear()` | 재생성 없이 비우기만 — 재생성이 부적절한 흐름 중간용 |

`rebuild()` 는 `route:cache` 를 호출하며, 이 커맨드는 **새 애플리케이션을 부팅해** 라우트를
수집한다. 방금 설치·활성화한 확장의 라우트가 함께 잡히려면 그 새 부팅이 바뀐 상태를 읽어야
하므로, 굽기 전에 상태 캐시를 비워야 한다(아래 절). 재생성이 실패하면(직렬화
불가한 클로저 라우트 등) 비운 상태로 둔다 — 비어 있으면 느릴 뿐 정확하지만, 낡은 캐시는
방금 설치한 확장을 통째로 없는 것으로 만든다.

### 갱신이 필요한 지점

| 지점 | 이유 |
|------|------|
| 확장 설치 / 활성화 / 비활성화 / 삭제 / 업데이트 | 확장 라우트 등록이 활성 목록에 게이트되어 있다 |
| 코어 업데이트 · 업그레이드 스텝 | 코어 라우트 파일과 vendor 가 교체된다 |

코어 업데이트처럼 파일 교체가 진행 중인 흐름에서는 **중간에 비우고 끝에서 재생성**한다.
교체 중 상태를 캐시에 구우면 안 되기 때문이며, config 캐시가 같은 형태로 처리된다.

템플릿 설치·활성화는 갱신 대상이 아니다 — 템플릿의 `routes.json` 은 프론트엔드 라우팅이라
서버 라우트에 영향을 주지 않는다. 모듈 설정의 경로 값도 마찬가지다(서버 라우트 접두사는
`api/modules/{identifier}` 로 식별자에 고정).

### 확장 라우트는 활성 상태로 게이트된다

모듈·플러그인 라우트는 **활성 상태인 확장의 것만** 등록한다. 비활성화하면 화면·메뉴·프론트엔드
에셋은 사라지지만, 라우트 등록을 게이트하지 않으면 그 확장의 API 는 계속 호출 가능한 상태로 남는다.
컨트롤러 파일이 그대로 있으므로 요청은 정상 처리되고 오류도 로그도 남지 않아, 화면만 보고는
꺼진 기능이 여전히 동작한다는 사실을 알 수 없다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 라우트 프로바이더가 디렉토리에 있는 확장 전부를 등록 | 활성 식별자 목록으로 걸러 등록 |
| 한쪽(모듈)만 게이트하고 다른 쪽(플러그인)은 무게이트 | 두 경로가 같은 기준을 공유 |
| 게이트를 개별 컨트롤러·미들웨어에 흩어 놓기 | 라우트 등록 지점 한 곳에서 판정 |

활성 목록은 상태 캐시를 공유하므로, 상태를 바꾼 쪽이 그 캐시를 비워야 다음 부팅이 새 상태를
읽는다(아래 절).

### 굽기는 상태 캐시 무효화 뒤에 온다

`route:cache` 가 부팅하는 새 애플리케이션에서 확장 라우트 프로바이더는 DB 가 아니라
**캐시된 활성 확장 목록**을 읽는다(TTL 기본 1일). 그래서 확장의 상태를 DB 에 쓴 뒤
그 상태 캐시를 비우기 **전에** 구우면, 새 부팅이 낡은 목록을 읽어 방금 바뀐 상태가
반영되지 않은 라우트가 박제된다.

```text
DB 상태 쓰기 → 상태 캐시 무효화 → 굽기(rebuild / 훅 캐시 재생성)
```

순서가 뒤집혔을 때의 결과는 방향마다 다르고, 어느 쪽도 스스로 회복되지 않는다.

| 수명주기 | 낡은 목록의 내용 | 결과 |
|---------|----------------|------|
| 활성화 | 그 확장이 없음 | 방금 켠 확장의 API 전량 404 |
| 비활성화 | 그 확장이 남아 있음 | 끈 확장의 API 가 계속 호출 가능 |
| 업데이트 | `Updating`(=비활성)으로 판정 | 업데이트 후 API 404 + 훅 리스너 누락 |

상태 캐시 무효화는 **DB 상태 쓰기 직후**에 둔다. 굽기 직전으로 옮기는 것으로는 부족하다 —
같은 목록을 읽는 굽기가 라우트 캐시 말고도 있기 때문이다(훅 매핑 캐시가 오토로드 갱신
안에서 구워진다).

업데이트 경로만 예외가 하나 있다. `Updating` 전이 직후에는 비우지 않는다 — 비우면 그 창
안에서 도는 오토로드 갱신이 DB 를 재조회해 그 확장을 비활성으로 판정하고 훅 캐시에서
리스너를 떨군다. 대신 **상태 복원 직후**에 비우고, 그 뒤 훅 캐시를 다시 굽는다. 훅 캐시
폴백은 파일 부재·손상에만 작동하므로 내용이 낡은 경우는 조용히 통과하기 때문이다.

이 순서는 정적 검사로 강제된다 — `rebuild()` 를 호출하는 수명주기 메서드를 리플렉션으로
도출해(개별 열거 금지) 각각에서 무효화가 앞서는지 확인한다.

### 캐시 안전한 라우트 작성

캐시가 걸리면 `RouteServiceProvider::boot()` 이 캐시 파일 로드로 분기해 **라우트 파일 자체가
실행되지 않는다.** 라우트 핸들러 클로저는 직렬화된 형태로 복원되지만, 파일이 실행되어야만
존재하는 심볼은 어디에도 없다.

깨지는 것은 클로저가 아니라 **오토로드되지 않는 심볼 참조**다. 클로저 자체는 캐시에서 정상
동작한다 — `routes/web.php` 의 SPA catch-all 이 그 증거다. 클래스는 오토로더가 찾아주므로
FQCN 정적 호출도 안전하다. 문제가 되는 것은 오토로드 경로가 없는 것들뿐이다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 라우트 파일에 전역 함수를 선언하고 핸들러가 호출 | 로직을 클래스(`app/Support/…` 등)로 옮기고 핸들러는 위임만 |
| 파일 스코프 변수를 핸들러가 `use` 없이 참조 | 클래스 상수 또는 `use ($var)` 로 클로저에 캡처 |
| 벤더/서비스 프로바이더가 조건부로 등록하는 라우트에 의존 | 그 URI 를 G7 라우트 파일이 직접 소유 |

전역 함수 위반의 증상은 특히 추적하기 어렵다. `Call to undefined function` 500 이 나는데
예외의 `file` 이 `laravel-serializable-closure://` 라 **원인 파일이 스택에 드러나지 않는다.**
정적 검사가 라우트 파일의 함수 선언을 차단한다.

프로바이더의 `boot()` 에서 등록한 라우트가 폐기되는 이유는 별개다. `Router::setCompiledRoutes()`
가 라우트 컬렉션을 **통째로 교체**하고 이 교체가 `booted` 콜백에서 일어나므로, 그보다 앞서
등록된 것은 조건 충족 여부와 무관하게 사라진다. 프레임워크 자신의 `BroadcastManager::routes()`
는 캐시 여부를 확인하는 가드를 갖고 있지만, 모든 패키지가 그렇지는 않다. 그런 라우트에
기능이 의존한다면 해당 URI 를 G7 라우트 파일에서 등록해 캐시에 함께 구워지게 한다.

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
