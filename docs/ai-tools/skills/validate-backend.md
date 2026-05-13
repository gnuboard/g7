# 백엔드 코드 패턴 검증 (validate-backend)

백엔드 코드 및 **작업계획서 내 PHP 코드 블록**이 그누보드7 규정을 준수하는지 검증합니다.

> 이전 이름: `/validate-code` (2026-05-06 부로 `/validate-backend` 로 개명).
>
> **단일 영역 검증 금지**: 한 작업 단위에서 백엔드와 프론트가 함께 변경되었다면 본 스킬만 실행하고 검증 완료를 선언하지 않는다. `/validate-frontend` 도 함께 실행하거나 통합 메타 스킬 `/validate-branch` 를 사용한다.

## 0단계: 검증 대상 유형 판별 (CRITICAL)

```text
⚠️ CRITICAL: 검증 대상이 소스코드인지 작업계획서인지 먼저 판별
```

### 판별 기준

| 파일 확장자 | 유형             | 검증 방식                                |
| ----------- | ---------------- | ---------------------------------------- |
| `*.php`     | 소스코드         | 파일 전체를 PHP 코드로 검증              |
| `*.md`      | **작업계획서**   | 마크다운 내 PHP 코드 블록 추출 후 검증   |

### 작업계획서 검증 시 추가 단계

작업계획서(`.md`) 파일인 경우:

1. **코드 블록 추출**: 마크다운에서 \`\`\`php 코드 블록 추출
2. **컨텍스트 파악**: 코드 블록 앞뒤 텍스트로 해당 코드의 용도 파악 (Controller, Service, Repository 등)
3. **유형별 검증**: 파악된 용도에 맞는 규정 적용

```text
⚠️ 작업계획서 검증 시 주의사항:
- 코드 블록이 예시/설명 목적인지, 실제 구현 계획인지 구분
- "예시:", "Example:", "// 예시" 등이 포함된 코드는 참고용으로 처리
- 실제 구현 계획 코드만 엄격하게 검증
```

## 1단계: 규정 문서 읽기

다음 규정 문서를 읽어 최신 규칙을 확인합니다:

- `docs/backend/service-repository.md` - Service-Repository 패턴
- `docs/backend/validation.md` - FormRequest 검증 규칙
- `docs/backend/enum.md` - Enum 사용 규칙
- `docs/backend/controllers.md` - 컨트롤러 계층 규칙
- `docs/backend/api-resources.md` - API 리소스 규칙
- `docs/backend/exceptions.md` - Custom Exception 다국어 처리
- `docs/backend/middleware.md` - 미들웨어 등록 규칙
- `docs/backend/routing.md` - 라우트 네이밍 및 권한 체크
- `docs/backend/response-helper.md` - API 응답 규칙
- `docs/backend/service-provider.md` - 서비스 프로바이더 안전성
- `docs/extension/permissions.md` - 권한 시스템 (Policy 사용 금지)
- `docs/backend/notification-system.md` - BaseNotification 상속 규칙
- `docs/backend/broadcasting.md` - Broadcasting 규칙
- `docs/backend/core-config.md` - 코어 설정 SSoT
- `docs/extension/storage-driver.md` - StorageInterface 사용 규칙

## 2단계: 검증 대상 파일 읽기

$ARGUMENTS 경로의 파일을 읽습니다.

경로가 지정되지 않은 경우, 현재 작업 중인 PHP 파일들을 대상으로 합니다:

- `app/Services/**/*.php` - Service 클래스
- `app/Http/Requests/**/*.php` - FormRequest 클래스
- `app/Repositories/**/*.php` - Repository 클래스
- `app/Http/Controllers/**/*.php` - Controller 클래스
- `app/Http/Resources/**/*.php` - API Resource 클래스
- `**/Listeners/**/*.php` - Listener 클래스

## 3단계: 규정 기반 자동 검증 (CRITICAL)

```text
⚠️ CRITICAL: 1단계에서 읽은 규정 문서의 모든 규칙을 검증 항목으로 사용합니다.
스킬에 하드코딩된 검증 항목이 아닌, 규정 문서가 Single Source of Truth입니다.
```

### 3.1 규정에서 검증 패턴 추출

1단계에서 읽은 모든 규정 문서에서 다음 패턴을 추출합니다:

| 추출 대상 | 검증 유형 |
| --------- | --------- |
| `❌` 또는 `잘못된` 키워드가 포함된 코드 블록 | **금지 패턴** - 발견 시 에러 |
| `✅` 또는 `올바른` 키워드가 포함된 코드 블록 | **필수 패턴** - 누락 시 경고 |
| `⚠️ 절대 금지`, `CRITICAL` 등 강조된 규칙 | **필수 검증 항목** |
| `TL;DR` 섹션의 핵심 포인트 | **우선 검증 항목** |

### 3.2 검증 방법

**금지 패턴 검증**:

```bash
# 규정 문서의 ❌ 예시에서 추출한 패턴을 grep으로 검사
grep -rn "[금지 패턴]" [대상 파일/디렉토리]
```

발견 시: 에러로 보고하고 규정 문서의 해당 섹션 참조 안내

**필수 패턴 검증**:

- 해당 컨텍스트에서 필수 패턴이 사용되어야 하는 경우, 누락 여부 확인
- 누락 시: 경고로 보고하고 규정 문서의 올바른 예시 안내

### 3.3 파일 유형별 규정 매핑

검증 대상 파일의 경로에 따라 해당하는 규정 문서를 우선적으로 적용합니다:

| 파일 경로 패턴 | 우선 적용 규정 |
| -------------- | -------------- |
| `app/Services/**` | service-repository.md |
| `app/Http/Requests/**` | validation.md |
| `app/Repositories/**` | service-repository.md |
| `app/Http/Controllers/**` | controllers.md, routing.md |
| `app/Http/Resources/**` | api-resources.md |
| `**/Listeners/**` | hooks.md (extension) |
| `app/Providers/**` | service-provider.md |
| `app/Http/Middleware/**` | middleware.md |
| `app/Exceptions/**` | exceptions.md |

### 3.4 백엔드 핵심 원칙 검증

백엔드 핵심 원칙 및 빈번 실수 패턴을 검증합니다.

| # | 검증 항목 | 금지 패턴 | 올바른 패턴 |
| --- | -------- | -------- | ---------- |
| 1 | FormRequest authorize() | 인증/권한 로직 구현 | `return true` 고정 + permission 미들웨어 체인 |
| 2 | API 리소스 $this->when() | 커스텀 메서드에서 `$this->when()` | 삼항 연산자 사용 |
| 3 | ResponseHelper 인수 순서 | `ResponseHelper::success($data, $message)` | `ResponseHelper::success($messageKey, $data)` — 메시지 first |
| 4 | 미들웨어 전역 등록 | `append()` 로 인증 미들웨어 전역 등록 | `appendToGroup('api')` 사용 |
| 5 | 로그아웃 구현 | 단일 단계 로그아웃 | 토큰 삭제 → 세션 무효화 → Auth::logout() (3단계) |
| 6 | ServiceProvider DB 접근 | 검증 없이 DB 접근 | `.env` + 테이블 존재 확인 필수 |
| 7 | exists/unique 규칙 | `exists:table,col` 문자열 | `Rule::exists(Model::class, 'col')` |
| 8 | DB CASCADE 삭제 | `onDelete('cascade')` 의존 삭제 | Service에서 명시적 삭제 (훅/파일/로깅 보장) |
| 9 | Storage 직접 호출 | `Storage::disk()` 직접 호출 | `StorageInterface` 사용 필수 |
| 10 | 파사드 사용 | `\Log::info()`, `auth()->user()` | `use Facades\Log; Log::info()` |

### 3.5 PHPDoc 검증

규정 문서에서 PHPDoc 관련 규칙도 추출하여 검증합니다:

- `@param`, `@return` 타입 명시 여부
- 한국어 설명 포함 여부
- `@throws` 명시 (예외 발생 시)

## 4단계: 테스트 실행 검증

검증 대상 파일에 해당하는 테스트가 있는 경우, 테스트를 실행하여 통과 여부를 확인합니다.

```bash
php artisan test --filter=[TestClassName]
```

## 5단계: 결과 보고

검증 결과를 다음 형식으로 보고합니다:

```text
## 코드 패턴 검증 결과

### 검증 파일
- [파일 경로]

### [규정 문서명] - [섹션명] 검증 결과

#### 금지 패턴 검사
- ❌ 금지 패턴 발견: [패턴] (파일:라인)
  - 규정: [규정 문서명] > [섹션명]
  - 수정 방법: [올바른 패턴으로 변경]
- ✅ 금지 패턴 없음

#### 필수 패턴 검사
- ❌ 필수 패턴 누락: [컨텍스트]에서 [패턴] 필요
  - 규정: [규정 문서명] > [섹션명]
- ✅ 필수 패턴 준수

### 테스트 실행 결과 (해당되는 경우)
- ✅ 모든 테스트 통과 (X passed)
- ❌ 테스트 실패: [실패 메시지]
```

---

## 6단계: 작업계획서 전용 검증 (마크다운 파일인 경우)

검증 대상이 작업계획서(`.md`)인 경우, 다음 추가 검증을 수행합니다.

### 6.1 코드 블록 추출 및 분류

마크다운 파일에서 PHP 코드 블록을 추출하고 용도별로 분류합니다:

| 코드 블록 컨텍스트       | 적용 규정                    |
| ------------------------ | ---------------------------- |
| Controller 관련          | controllers.md, routing.md   |
| Service 관련             | service-repository.md        |
| Repository 관련          | service-repository.md        |
| FormRequest 관련         | validation.md                |
| API Resource 관련        | api-resources.md             |
| Exception 관련           | exceptions.md                |
| Listener/Hook 관련       | hooks.md                     |

### 6.2 Controller 코드 블록 검증

```php
// ✅ 올바른 패턴
class ProductController extends AdminBaseController
{
    public function __construct(
        private readonly ProductServiceInterface $productService
    ) {}
}

// ❌ 잘못된 패턴 - Repository 직접 주입
class ProductController extends AdminBaseController
{
    public function __construct(
        private readonly ProductRepository $productRepository  // ❌
    ) {}
}
```

**검증 항목**:

- ❌ Repository 직접 주입 (Service를 통해야 함)
- ❌ 컨트롤러 내 검증 로직 (FormRequest 사용 필수)
- ❌ 컨트롤러 내 비즈니스 로직 (Service로 위임 필수)
- ✅ ResponseHelper 사용

### 6.3 Service 코드 블록 검증

```php
// ✅ 올바른 패턴
class ProductService implements ProductServiceInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository
    ) {}

    public function create(array $data): Product
    {
        HookManager::doAction('sirsoft-ecommerce.product.before_create', $data);
        $data = HookManager::applyFilters('sirsoft-ecommerce.product.filter_create_data', $data);
        $result = $this->productRepository->create($data);
        HookManager::doAction('sirsoft-ecommerce.product.after_create', $result);
        return $result;
    }
}

// ❌ 잘못된 패턴 - 검증 로직 포함
public function create(array $data): Product
{
    if (empty($data['name'])) {  // ❌ FormRequest에서 처리해야 함
        throw new ValidationException();
    }
}
```

**검증 항목**:

- ❌ RepositoryInterface 대신 구체 클래스 타입힌트
- ❌ Service 내 검증 로직
- ✅ 훅 실행 순서: before → filter → action → after
- ✅ 훅 네이밍: `[vendor-module].[entity].[action]_[timing]`

### 6.4 Repository 코드 블록 검증

```php
// ✅ 올바른 패턴
class ProductRepository implements ProductRepositoryInterface
{
    public function __construct(
        private readonly Product $model
    ) {}
}
```

**검증 항목**:

- ✅ RepositoryInterface 구현
- ❌ 비즈니스 로직 포함 (Service로 이동 필요)

### 6.5 FormRequest 코드 블록 검증

```php
// ✅ 올바른 패턴
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
        'price' => ['required', 'numeric', 'min:0'],
    ];
}

public function messages(): array
{
    return [
        'name.required' => __('validation.custom.product.name.required'),
    ];
}
```

**검증 항목**:

- ✅ `__()` 함수를 사용한 다국어 메시지
- ❌ 하드코딩된 한국어 메시지

### 6.6 API Response 코드 블록 검증

```php
// ✅ 올바른 패턴
return ResponseHelper::success(
    data: new ProductResource($product),
    message: __('messages.product.created')
);

// ❌ 잘못된 패턴
return response()->json(['data' => $product]);  // ResponseHelper 미사용
```

### 6.7 작업계획서 검증 결과 보고 형식

```text
## 작업계획서 백엔드 코드 검증 결과

### 검증 파일
- [마크다운 파일 경로]

### 코드 블록 요약
- Controller 코드 블록: X개
- Service 코드 블록: Y개
- Repository 코드 블록: Z개
- FormRequest 코드 블록: W개
- 총 검증 대상: N개

### Controller 검증
- ✅ Service 주입 패턴 준수
- ❌ Repository 직접 주입: [위치]
- ✅ ResponseHelper 사용

### Service 검증
- ✅ RepositoryInterface 사용
- ❌ 구체 클래스 타입힌트: [위치]
- ✅ 훅 실행 순서 준수
- ❌ Service 내 검증 로직: [위치]

### Repository 검증
- ✅ Interface 구현
- ❌ 비즈니스 로직 포함: [위치]

### FormRequest 검증
- ✅ 다국어 메시지 사용
- ❌ 하드코딩된 메시지: [위치]

### API Response 검증
- ✅ ResponseHelper 사용
- ❌ 직접 response() 사용: [위치]
```

---

## 핵심 원칙

```text
⚠️ CRITICAL:
- 규정 문서가 Single Source of Truth - 스킬에 검증 항목 하드코딩 금지
- 규정 문서의 ❌/✅ 예시를 자동으로 검증 패턴으로 사용
- 규정이 변경되면 검증도 자동으로 변경됨
- 파일 유형에 따라 해당 규정 문서 우선 적용
- 작업계획서(.md) 검증 시 코드 블록 추출 후 동일한 규정 적용
```
