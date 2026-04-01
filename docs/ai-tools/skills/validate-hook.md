# 훅 패턴 검증 (validate-hook)

훅 시스템 및 **작업계획서 내 훅 관련 코드 블록**이 그누보드7 규정을 준수하는지 검증합니다.

## 0단계: 검증 대상 유형 판별 (CRITICAL)

```text
⚠️ CRITICAL: 검증 대상이 소스코드인지 작업계획서인지 먼저 판별
```

### 판별 기준

| 파일 확장자 | 유형             | 검증 방식                                      |
| ----------- | ---------------- | ---------------------------------------------- |
| `*.php`     | 소스코드         | 파일 전체를 훅/리스너 코드로 검증              |
| `*.md`      | **작업계획서**   | 마크다운 내 PHP 코드 블록 추출 후 검증         |

### 작업계획서 검증 시 추가 단계

작업계획서(`.md`) 파일인 경우:

1. **코드 블록 추출**: 마크다운에서 \`\`\`php 코드 블록 추출
2. **훅 관련 코드 식별**: `HookManager`, `doAction`, `applyFilters`, `Listener` 등의 키워드로 식별
3. **규정 적용**: hooks.md의 훅 시스템 규칙 적용

```text
⚠️ 작업계획서 검증 시 주의사항:
- 코드 블록이 예시/설명 목적인지, 실제 구현 계획인지 구분
- "예시:", "Example:", "// 예시" 등이 포함된 코드는 참고용으로 처리
- 실제 구현 계획 코드만 엄격하게 검증
```

## 1단계: 규정 문서 읽기

다음 규정 문서를 읽어 최신 규칙을 확인합니다:

- `docs/extension/hooks.md` - 훅 시스템 규칙
- `docs/backend/service-repository.md` - Service에서의 훅 실행
- `docs/extension/plugin-development.md` - 플러그인 격리 원칙

## 2단계: 검증 대상 파일 읽기

$ARGUMENTS 경로의 파일을 읽습니다.

경로가 지정되지 않은 경우, 다음 파일들을 대상으로 합니다:

- `**/Listeners/**/*.php` - 훅 리스너
- `app/Services/**/*.php` - 훅 실행 서비스
- `modules/_bundled/**/src/Services/**/*.php` - _bundled 모듈 서비스
- `modules/_bundled/**/src/Listeners/**/*.php` - _bundled 모듈 리스너
- `plugins/_bundled/**/src/Listeners/**/*.php` - _bundled 플러그인 리스너

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

### 3.3 훅 특화 검증

규정 문서의 훅 관련 섹션에서 추출한 규칙으로 다음을 검증합니다:

1. **훅 네이밍**: `[vendor-module].[entity].[action]_[timing]` 패턴 준수 여부
2. **훅 실행 순서 (Service)**: before → filter → action → after 순서
3. **Listener 인터페이스**: `HookListenerInterface` 구현 여부
4. **Filter 훅 type 필드**: `'type' => 'filter'` 명시 여부
5. **Filter 훅 리턴 값**: 값 반환 여부

### 3.4 파일 유형별 규정 매핑

검증 대상 파일의 경로에 따라 해당하는 규정 문서를 우선적으로 적용합니다:

| 파일 경로 패턴 | 우선 적용 규정 |
| -------------- | -------------- |
| `**/Listeners/**` | hooks.md |
| `**/Services/**` | service-repository.md, hooks.md |

### 3.5 CLAUDE.md CRITICAL RULES 검증 (MANDATORY)

CLAUDE.md 및 규정 문서에 명시된 CRITICAL 규칙을 검증합니다.

#### 3.5.1 _bundled 디렉토리 작업 원칙

```text
⚠️ CRITICAL: 확장 수정/개발은 반드시 _bundled 디렉토리에서만 작업
→ 활성 디렉토리 직접 수정 절대 금지
```

**검증 항목**:

- 수정 대상 파일이 `_bundled/` 하위에 있는지 확인
- 활성 디렉토리(`modules/vendor-xxx/`, `plugins/vendor-xxx/`)의 파일 수정 시 경고

#### 3.5.2 플러그인 격리 원칙

```text
⚠️ CRITICAL: 플러그인 간 직접 의존 금지 → 모듈에만 의존, 플러그인끼리는 API로만 통신
```

| ❌ 금지 | ✅ 올바른 사용 |
| -------- | --------------- |
| 플러그인 A → 플러그인 B 직접 참조 | 플러그인 A → 모듈 API → 플러그인 B |
| 플러그인에서 다른 플러그인 클래스 import | 훅/이벤트 또는 API 통신 |

**검증 항목**:

- 플러그인 코드에서 다른 플러그인의 네임스페이스(`Plugins\`) 참조 여부 확인
- 플러그인에서 모듈(`Modules\`) 의존만 허용

## 4단계: 결과 보고

검증 결과를 다음 형식으로 보고합니다:

```text
## 훅 패턴 검증 결과

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

#### 훅 네이밍 검증
- ✅ 올바름: [훅 이름]
- ❌ 잘못됨: [훅 이름] → [수정 제안]

#### Filter 훅 type 필드 검증
- ✅ type 명시됨: [훅 이름]
- ❌ type 누락: [훅 이름] → 'type' => 'filter' 추가 필요
```

---

## 5단계: 작업계획서 전용 검증 (마크다운 파일인 경우)

검증 대상이 작업계획서(`.md`)인 경우, 다음 추가 검증을 수행합니다.

### 5.1 훅 관련 코드 블록 식별

다음 키워드가 포함된 코드 블록을 훅 관련 코드로 식별:

| 키워드                          | 용도                    |
| ------------------------------- | ----------------------- |
| `HookManager::doAction`         | Action Hook 실행        |
| `HookManager::applyFilters`     | Filter Hook 실행        |
| `implements HookListenerInterface` | 리스너 클래스 정의   |
| `getHooks()`                    | 훅 등록 정의            |
| `'type' => 'filter'`            | Filter Hook 타입 명시   |

### 5.2 훅 네이밍 검증

```php
// ✅ 올바른 패턴
HookManager::doAction('sirsoft-ecommerce.product.before_create', $data);
HookManager::doAction('sirsoft-ecommerce.product.after_create', $result);
$data = HookManager::applyFilters('sirsoft-ecommerce.product.filter_create_data', $data);

// ❌ 잘못된 패턴 - 네이밍 규칙 위반
HookManager::doAction('product.create', $data);  // ❌ vendor-module 누락
HookManager::doAction('beforeCreateProduct', $data);  // ❌ 잘못된 형식
```

**네이밍 규칙**: `[vendor-module].[entity].[action]_[timing]`

**검증 항목**:

- ❌ vendor-module 접두사 누락
- ❌ entity 누락
- ❌ action_timing 형식 위반
- ✅ 올바른 네이밍 패턴

### 5.3 훅 실행 순서 검증 (Service 코드)

```php
// ✅ 올바른 순서: before → filter → action → after
public function create(array $data): Product
{
    // 1. before
    HookManager::doAction('sirsoft-ecommerce.product.before_create', $data);

    // 2. filter
    $data = HookManager::applyFilters('sirsoft-ecommerce.product.filter_create_data', $data);

    // 3. action
    $result = $this->productRepository->create($data);

    // 4. after
    HookManager::doAction('sirsoft-ecommerce.product.after_create', $result);

    return $result;
}

// ❌ 잘못된 순서
public function create(array $data): Product
{
    $result = $this->productRepository->create($data);  // ❌ 훅 없이 바로 실행
    HookManager::doAction('sirsoft-ecommerce.product.before_create', $data);  // ❌ 순서 잘못됨
    return $result;
}
```

### 5.4 Listener 정의 검증

```php
// ✅ 올바른 패턴
class ProductCacheListener implements HookListenerInterface
{
    public function getHooks(): array
    {
        return [
            [
                'hook' => 'sirsoft-ecommerce.product.after_create',
                'method' => 'clearProductCache',
                'priority' => 10,
            ],
            [
                'hook' => 'sirsoft-ecommerce.product.filter_list_data',
                'method' => 'addCategoryInfo',
                'priority' => 10,
                'type' => 'filter',  // ✅ Filter Hook은 type 명시 필수
            ],
        ];
    }

    public function addCategoryInfo(array $data): array
    {
        // ... 데이터 변형
        return $data;  // ✅ Filter Hook은 반드시 값 반환
    }
}

// ❌ 잘못된 패턴 - Filter Hook인데 type 누락
[
    'hook' => 'sirsoft-ecommerce.product.filter_list_data',
    'method' => 'addCategoryInfo',
    'priority' => 10,
    // ❌ 'type' => 'filter' 누락
],

// ❌ 잘못된 패턴 - Filter Hook인데 반환값 없음
public function addCategoryInfo(array $data): void  // ❌ void 반환
{
    // ...
}
```

**검증 항목**:

- ❌ HookListenerInterface 미구현
- ❌ Filter Hook에 `'type' => 'filter'` 누락
- ❌ Filter Hook 메서드가 값을 반환하지 않음
- ✅ 올바른 리스너 구조

### 5.5 Filter Hook 반환값 검증

```php
// ✅ 올바른 패턴
public function filterProductData(array $data): array
{
    $data['calculated_price'] = $data['price'] * 1.1;
    return $data;  // ✅ 반드시 반환
}

// ❌ 잘못된 패턴
public function filterProductData(array $data): void  // ❌ void
{
    $data['calculated_price'] = $data['price'] * 1.1;
    // 반환값 없음
}
```

### 5.6 작업계획서 검증 결과 보고 형식

```text
## 작업계획서 훅 패턴 검증 결과

### 검증 파일
- [마크다운 파일 경로]

### 훅 코드 블록 요약
- 훅 실행 코드 블록: X개
- 리스너 정의 블록: Y개
- 총 검증 대상: Z개

### 훅 네이밍 검증
- ✅ 올바른 네이밍: [훅 이름]
- ❌ 잘못된 네이밍: [훅 이름] (코드 블록 위치)
  - 규칙: [vendor-module].[entity].[action]_[timing]
  - 수정 제안: [올바른 이름]

### 훅 실행 순서 검증
- ✅ before → filter → action → after 순서 준수
- ❌ 순서 위반: [위치] - [문제 설명]

### Listener 정의 검증
- ✅ HookListenerInterface 구현
- ❌ Interface 미구현: [위치]

### Filter Hook type 필드 검증
- ✅ type 명시됨: [훅 이름]
- ❌ type 누락: [훅 이름] (코드 블록 위치)
  - 수정: 'type' => 'filter' 추가 필요

### Filter Hook 반환값 검증
- ✅ 반환값 있음: [메서드명]
- ❌ 반환값 없음: [메서드명] (코드 블록 위치)
  - void 대신 array/mixed 반환 필요
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
- 모든 확장 작업은 _bundled 디렉토리에서만 수행 (활성 디렉토리 직접 수정 금지)
- 플러그인 간 직접 의존 금지 → 모듈에만 의존
```
