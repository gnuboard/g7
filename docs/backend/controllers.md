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

### FormRequest 검증 실패의 응답 형태 — `api/*` 는 언제나 JSON

컨트롤러에 닿기 전 FormRequest 에서 검증이 실패하면 프레임워크가 응답을 만든다. 그 기본값은 **「JSON 을 기대하지 않는 요청이면 302 + 이전 주소로 되돌리기」** 인데, `api/*` 에서는 그 기본값이 위험하다.

- 자산 요청은 `Accept: image/*`(또는 `text/css`)로 오므로 「JSON 을 기대하지 않는 요청」으로 분류된다.
- 되돌릴 주소는 `Referer` 다 — 즉 잘못된 경로 하나로 **인증 없는 오픈 리다이렉트**가 성립한다.
- 같은 파일을 내주는 정적 서빙은 404 를 주므로, 응답 형태가 배치에 따라 갈린다.

그래서 `api/*` 는 `Accept` 헤더와 무관하게 **언제나 JSON**(기본 422)을 돌려준다. 고정되는 것은 **형태(JSON)** 이지 상태 코드가 아니다 — 상태는 `ValidationException::$status`(기본 422)를 그대로 따르므로, 예외가 다른 상태를 실었으면 그 값으로 나간다. 이 분기는 `bootstrap/app.php` 의 예외 렌더러 한 곳이 소유하므로, 개별 컨트롤러·FormRequest 가 `expectsJson()` 으로 응답 형태를 다시 나누지 않는다.

```php
// bootstrap/app.php — api/* 전 경로 공통
$exceptions->render(function (ValidationException $e, $request) {
    if ($request->is('api/*') && ! $request->expectsJson()) {
        return response()->json([...], $e->status); // 형태는 JSON 고정, 상태는 예외가 정한다(기본 422)
    }
});
```

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

## 공개 파일 서빙 컨트롤러의 응답 계약

파일을 내보내는 공개 컨트롤러(`BaseApiController::fileResponse()` / `streamedFileResponse()`
경유)는 **정적 서빙과 동등한 응답**을 내야 한다. `public/storage` 심볼릭 링크를 만들 수 없는
호스팅에서는 같은 파일이 이 통로로 나가므로, 두 통로의 성질이 갈리면 같은 사이트가 링크
유무에 따라 다르게 동작하고 그 차이는 오류가 아니라 조용한 동작 차이로만 나타난다.

### 압축 제외 선언

무인증·무상한으로 열려 있고 **크기가 유계가 아닌** 통로(사용자가 올린 파일)는 압축 경로에
태우지 않는다. 압축 미들웨어는 파일을 통째로 메모리에 읽으므로 동시 요청 수만큼 증폭된다.
판정 축은 「크기가 유계인가」이지 「공개인가」가 아니다 — 상한 안에서 크기가 유계인 배포
자산(코어·확장 번들·템플릿 자산)은 압축 대상이다.

`HEAD` 와 부분 응답(`206` 또는 `Content-Range` 보유)도 제외한다. 파일 응답을 일반 응답으로
치환하면 `HEAD` 에 본문이 실리고, 범위 응답은 `Content-Range` 와 본문이 어긋난다. 두 신호는
**각각** 독립적으로 제외 사유다.

### 조건부 요청(304)의 헤더 집합

`304` 는 `200` 이 실었을 캐싱 헤더를 함께 싣는다 — `Cache-Control`·`Expires`·`Vary`
(RFC 9110 §15.4.5). `Vary` 가 빠지면 공유 캐시가 어떤 축으로 갈린 표현인지 모른 채 항목을
갱신한다. 반대로 `200` 이 싣지 않는 축을 `304` 에만 붙이지 않는다.

`If-None-Match` 가 있으면 그것이 우선이고, 없을 때만 `If-Modified-Since` 를 본다
(RFC 9110 §13.1.2). `Last-Modified` 를 내보내면 `If-Modified-Since` 도 반드시 처리한다 —
내보내기만 하고 무시하면 `ETag` 를 쓰지 않는 프록시가 매번 전문을 받아 간다.

`ETag` 는 큰따옴표로 감싼다(§8.8.3). 압축본은 표현이 다르므로 `-gzip` 접미사가 붙고,
재검증 비교는 그 접미사·다중값·`W/` 약한 검증자를 **한 지점이** 흡수한다. 비교하는 쪽이
접미사를 벗기지 않으면 그 URL 의 `304` 가 통째로 사라지는데, 오류도 경고도 없이 매 재검증이
전문 `200` 이 되는 형태다. 회귀 테스트는 반드시 `Accept-Encoding: gzip` 을 실은 **2왕복**이어야
한다 — 그 헤더가 없으면 압축이 일어나지 않아 접미사도 붙지 않는다.

### 스트리밍

행이 가리키는 디스크가 원격일 수 있는 통로(첨부 등)는 로컬 절대 경로를 전제하는
`fileResponse()` 를 쓰지 않는다. 그 경로는 `filemtime`/`filesize` 를 부르므로 원격 디스크
행에서 성립하지 않는다. 스트림 헬퍼를 쓰고, 검증자(`ETag`·`Last-Modified`)는 파일 stat 이
아니라 **행 메타**(디스크·경로·수정시각·크기)에서 만든다 — 그래야 원격에서도 결정적이다.

스트림 헬퍼도 형제와 같은 인자를 갖는다(`immutable`·`lastModifiedAt`). 한쪽에만 없으면 그
경로의 소비처는 위 계약을 지킬 수단이 없다.

**크기도 행 메타로 선지정한다.** `Content-Length` 를 넘기지 않으면 어댑터가 원격 메타를 한
번 더 조회한다. 대신 그 값이 실제로 흘려보내는 바이트와 어긋나면 응답이 **잘린다** — 파일을
제자리에서 바꾸는 경로(이미지 축소 등)가 있다면 크기를 읽기 전에 stat 캐시를 비운다.

**파일 이름 선언은 스토리지 단일 지점이 만든다.** `Content-Disposition` 을 호출부가 직접
넘기면 어댑터가 자기 정제 지점을 통째로 건너뛴다 — 이름에 따옴표가 든 업로드에서 헤더 값이
첫 따옴표에서 끊기고, 같은 파일이 통로마다 다른 이름으로 저장된다.

**304 는 클라이언트가 보유한 표현의 검증자를 돌려준다.** 매치 판정이 `-gzip` 접미사를
흡수한다면 응답도 그 접미사를 실은 값을 돌려줘야 한다 — 흡수 전 값을 돌려주면 공유 캐시가
압축 표현의 항목을 identity 검증자로 갱신한다.

### 판정과 서빙 사이

파일이 있다고 판정한 뒤 실제로 내보내기까지 그 파일이 사라질 수 있다. `filemtime`/`filesize`
를 가드 없이 부르면 경고가 예외로 승격되어 **무인증 표면이 500** 이 된다. 정적 서빙은 같은
경합에서 404 를 주므로 그쪽에 맞춘다.

그때의 `abort(404)`/`abort(403)` 은 `api/*` 에서 `Accept` 헤더와 무관하게 **JSON** 이다. 자산
요청은 `<img src>` 라 `Accept: image/*` 로 오는데, 프레임워크 기본값은 그 요청에 HTML 오류
페이지를 돌려준다 — 같은 라우트가 `Accept` 에 따라 두 종류의 응답을 내게 된다. 형태 고정은
`bootstrap/app.php` 의 렌더러 한 곳이 소유하며, 예외가 실은 헤더(`Retry-After`)는 승계된다.

### MIME 스니핑 차단

파일 서빙 응답은 **전부** `X-Content-Type-Options: nosniff` 를 싣는다 — 200 과 304 모두.
사용자가 올린 SVG·HTML 형태 파일이 앱 오리진에서 문서로 실행되는 축이라, 정적 서빙 설정이
붙이는 헤더를 이 통로만 빠뜨리면 링크 유무로 위험이 갈린다. 붙이는 자리는 헬퍼 단일 지점
(`fileResponse()`·`streamedFileResponse()`·문자열 자산은 `inlineContentResponse()`)이다 —
호출부로 내리면 열 통로 중 하나가 반드시 빠진다.

유형을 **알 수 없으면 내려받기**다. 두 경우가 있고 둘 다 `application/octet-stream` 이다.

- 확장자 표에 없는 파일(공개 스토리지) — 내용을 읽어 추정하지 않는다. 스니핑은 HTML 형태
  업로드물을 `text/html` 로 승격시킨다. 정적 서빙도 미지 확장자를 내려받기로 준다.
- 행 `mime_type` 이 비어 있고 스트림 응답에도 `Content-Type` 이 없는 첨부(구형 업로드·외부
  이관) — 예외를 던지지 않는다. 그 예외는 개발자에게 「인자를 넘겨라」를 말할 뿐 방문자에게는
  무인증 표면의 500 이고, 빈 `mime_type` 은 코드 결함이 아니라 데이터 상태다. 빈 문자열·공백은
  `null` 과 같은 「모름」이며, 인자 → 응답 헤더 → 미지 유형 순으로 판정한다. 200/304 의 `Vary`
  는 그 확정값을 같은 근거로 쓴다.

### 서빙 메타는 한 곳이 검증한다

스트림 통로는 서비스가 「스트림 응답 + 검증자 메타」 배열을 돌려주고 컨트롤러가 그것을
헬퍼에 넘긴다. 그 배열의 키(`response`·`etag_source`·`mime_type`·`last_modified`, 통로가
만료 기간을 서비스에 맡기면 `max_age`) 검증은 `streamedFileResponseFromMeta()` 한 곳이 한다 —
컨트롤러마다 `array_key_exists` 가드를 복제하지 않는다. 서비스는 확장에 열려 있어 「지금
구현이 채운다」는 방어가 아니고, 가드 없는 통로는 키가 빠지면 500(`etag_source`) 또는 조용한
축 소실(`last_modified` → `?? null`)이 된다. 키가 빠지면 **명시 실패**다 — 로그에 누락 키와
호출부 문맥(`hash`·`slug`)을 남기고 500 을 돌려준다.

`immutable`·`skipCompression` 은 그 헬퍼에 기본값이 없다 — 통로의 성질(행 주소인가·사용자
업로드물인가)은 호출부만 알고, 생략은 오류가 아니라 잘못된 캐싱으로만 나타난다. 원시 헬퍼
(`streamedFileResponse()`)를 직접 부르는 통로는 `immutable:`·`mimeType:`·`skipCompression:`
셋을 이름 인자로 명시한다.

> 응답 헤더별 상세와 엔드포인트 레퍼런스: [api/system.md](api/system.md)

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
