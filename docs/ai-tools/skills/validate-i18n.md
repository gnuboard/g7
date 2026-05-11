# 다국어 검증 (validate-i18n)

다국어 처리 및 **작업계획서 내 다국어 관련 코드 블록**이 그누보드7 규정을 준수하는지 검증합니다.

## 0단계: 검증 대상 유형 판별 (CRITICAL)

```text
⚠️ CRITICAL: 검증 대상이 소스코드인지 작업계획서인지 먼저 판별
```

### 판별 기준

| 파일 확장자     | 유형             | 검증 방식                                      |
| --------------- | ---------------- | ---------------------------------------------- |
| `*.php`         | 소스코드         | PHP 다국어 처리 검증                           |
| `*.json`        | 소스코드         | 언어 파일 키 동기화 검증                       |
| `*.tsx`         | 소스코드         | 프론트엔드 다국어 처리 검증                    |
| `*.md`          | **작업계획서**   | 마크다운 내 코드 블록 추출 후 검증             |

### 작업계획서 검증 시 추가 단계

작업계획서(`.md`) 파일인 경우:

1. **코드 블록 추출**: 마크다운에서 PHP, JSON, TypeScript 코드 블록 추출
2. **다국어 관련 코드 식별**: `__()`, `$t:`, `lang/`, `G7Core.t()` 등의 키워드로 식별
3. **규정 적용**: 해당 유형에 맞는 다국어 규정 적용

```text
⚠️ 작업계획서 검증 시 주의사항:
- 코드 블록이 예시/설명 목적인지, 실제 구현 계획인지 구분
- "예시:", "Example:", "// 예시" 등이 포함된 코드는 참고용으로 처리
- 실제 구현 계획 코드만 엄격하게 검증
```

## 1단계: 규정 문서 읽기

다음 규정 문서를 읽어 최신 규칙을 확인합니다:

- `docs/database-guide.md` - 다국어 섹션
- `docs/backend/validation.md` - 검증 메시지 다국어
- `docs/backend/exceptions.md` - Custom Exception 다국어
- `docs/extension/module-i18n.md` - 모듈 다국어 처리
- `docs/frontend/data-binding-i18n.md` - 프론트엔드 다국어 바인딩
- `docs/frontend/components.md` (인덱스) - 컴포넌트 다국어
  - `components-patterns.md` - 다국어 번역 (G7Core.t) 상세

## 2단계: 검증 대상 파일 읽기

$ARGUMENTS 경로의 파일을 읽습니다.

경로가 지정되지 않은 경우, 다음 파일들을 대상으로 합니다:

- `lang/ko/*.php`, `lang/en/*.php` - 코어 언어 파일
- `**/lang/ko/*.php`, `**/lang/en/*.php` - 모듈 언어 파일
- `**/resources/lang/*.json` - 프론트엔드 언어 파일
- `templates/**/lang/*.json` - 템플릿 언어 파일
- `lang-packs/_bundled/g7-*-{locale}/{backend,frontend,seed}/**` - 번들 언어팩 자산 (ja, zh-CN 등)

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

### 3.3 다국어 특화 검증

규정 문서의 다국어 관련 섹션에서 추출한 규칙으로 다음을 검증합니다:

1. **하드코딩 문자열**: PHP/TSX 파일에서 다국어 처리 없이 직접 사용된 문자열
2. **언어 파일 동기화**: `ko/*.php`와 `en/*.php` 키 일치 여부
3. **config 사용**: 로케일 하드코딩 대신 `config()` 함수 사용 여부
4. **시더 다국어 필드**: 배열 형식 사용 여부

### 3.4 모듈 레이아웃 다국어 키 검증 (CRITICAL)

모듈 레이아웃에서 다국어 키 사용 시 **모듈 식별자 접두사** 포함 여부를 검증합니다.

| #   | 검증 항목        | 금지 패턴                              | 올바른 패턴                                    |
| --- | ---------------- | -------------------------------------- | ---------------------------------------------- |
| 1   | 모듈 식별자 누락 | `$t:common.xxx` (모듈 레이아웃에서)    | `$t:sirsoft-ecommerce.common.xxx`              |
| 2   | 모듈 식별자 누락 | `$t:admin.settings.xxx` (모듈 레이아웃에서)  | `$t:sirsoft-ecommerce.admin.settings.xxx`  |

```text
⚠️ CRITICAL: $t:common.xxx는 코어의 common 키를 찾으므로 모듈 다국어와 매칭 안됨
✅ 모듈 레이아웃에서는 반드시 $t:모듈식별자.키 형식 사용
```

### 3.5 로케일 하드코딩 검증

마이그레이션 및 시더에서 로케일을 하드코딩하지 않았는지 검증합니다.

| 금지 패턴                            | 올바른 패턴                                            |
| ------------------------------------ | ------------------------------------------------------ |
| `['ko', 'en']` 직접 나열            | `config('app.supported_locales')`                      |
| `foreach (['ko', 'en'] as $locale)` | `foreach (config('app.supported_locales') as $locale)` |

### 3.6 번들 언어팩 정합성 검증

ko/en 다국어 키 추가/수정/제거 시, 대응하는 번들 언어팩(`lang-packs/_bundled/g7-*-{locale}/`) 도 동기화해야 한다. 동기화하지 않으면 해당 locale 화면에서 미번역 fallback 이 노출되어 UX 손상.

**검증 항목** (수동 비교):

ko/en 언어 파일과 번들 locale 파일의 키를 직접 비교한다.

- ❌ 누락 (ko/en 에 있으나 번들 locale 에 없음) → 해당 locale 에서 미번역 fallback 노출
- ❌ 잉여 (번들 locale 에는 있으나 ko/en 에 없음) → ko 에서 제거된 키의 번들 잔류, 제거 필요
- ❌ 번들 파일 자체 부재 → 디렉토리 구조 어긋남

**파일 유형별 매칭 (코어/모듈/플러그인/템플릿 4 scope)**:

- 코어 backend: `lang/{ko,en}/*.php` ⇔ `lang-packs/_bundled/g7-core-{locale}/backend/{locale}/*.php`
- 모듈 backend: `modules/_bundled/{mod}/{src,resources}/lang/{ko,en}/*.php` (union) ⇔ `g7-module-{mod}-{locale}/backend/{locale}/*.php`
- 모듈 frontend: `modules/_bundled/{mod}/resources/lang/partial/{ko,en}/*.json` ⇔ `g7-module-{mod}-{locale}/frontend/partial/*.json`
- 플러그인: `plugins/_bundled/{pl}/lang/{ko,en}/*.php` ⇔ `g7-plugin-{pl}-{locale}/backend/{locale}/*.php`
- 템플릿: `templates/_bundled/{tpl}/lang/{ko,en}.json` + `lang/partial/{ko,en}/*.json` ⇔ `g7-template-{tpl}-{locale}/frontend/{locale}.json` + `frontend/partial/*.json`

상세: [docs/extension/language-packs.md](../../extension/language-packs.md) "기존 ko/en 변경 시 번들 ja 동기화 의무" 섹션.

### 3.7 파일 유형별 규정 매핑

검증 대상 파일의 유형에 따라 해당하는 규정 문서를 우선적으로 적용합니다:

| 파일 유형 | 우선 적용 규정 |
| --------- | -------------- |
| `lang/**/*.php` | database-guide.md (다국어 섹션) |
| `**/resources/lang/*.json` | module-i18n.md |
| `templates/**/lang/*.json` | data-binding-i18n.md |
| `app/Http/Requests/**` | validation.md |
| `app/Exceptions/**` | exceptions.md |
| `**/*.tsx` | components.md (G7Core.t 섹션) |

## 4단계: 결과 보고

검증 결과를 다음 형식으로 보고합니다:

```text
## 다국어 검증 결과

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

#### 언어 파일 동기화
- ✅ 동기화됨: [키 수]개
- ❌ ko에만 존재: [키 목록]
- ❌ en에만 존재: [키 목록]
```

---

## 5단계: 작업계획서 전용 검증 (마크다운 파일인 경우)

검증 대상이 작업계획서(`.md`)인 경우, 다음 추가 검증을 수행합니다.

### 5.1 다국어 관련 코드 블록 식별

다음 키워드가 포함된 코드 블록을 다국어 관련 코드로 식별:

| 키워드                 | 유형                    |
| ---------------------- | ----------------------- |
| `__()`, `trans()`      | PHP 다국어              |
| `$t:`, `$t:defer:`     | 레이아웃 JSON 다국어    |
| `G7Core.t()`, `t()`    | TSX 컴포넌트 다국어     |
| `lang/`, `resources/lang` | 언어 파일 정의       |

### 5.2 PHP 다국어 코드 블록 검증

```php
// ✅ 올바른 패턴
return ResponseHelper::success(
    message: __('messages.product.created')
);

throw new CustomException(__('exceptions.product.not_found'));

// ❌ 잘못된 패턴 - 하드코딩된 한국어
return ResponseHelper::success(
    message: '상품이 생성되었습니다.'  // ❌
);

throw new CustomException('상품을 찾을 수 없습니다.');  // ❌
```

**검증 항목**:

- ❌ 하드코딩된 한국어 문자열 (사용자에게 표시되는 메시지)
- ✅ `__()` 함수 사용
- ✅ 언어 키 네이밍 규칙 준수

### 5.3 레이아웃 JSON 다국어 코드 블록 검증

```json
// ✅ 올바른 패턴
{
  "props": {
    "label": "$t:sirsoft-ecommerce.admin.product.name",
    "placeholder": "$t:sirsoft-ecommerce.admin.product.name_placeholder"
  }
}

// ❌ 잘못된 패턴 - 하드코딩된 한국어
{
  "props": {
    "label": "상품명",
    "placeholder": "상품명을 입력하세요"
  }
}
```

**검증 항목**:

- ❌ 하드코딩된 한국어 라벨/플레이스홀더
- ✅ `$t:` 또는 `$t:defer:` 사용
- ✅ 모듈 네임스페이스 포함 (`sirsoft-ecommerce.admin.product.*`)

### 5.4 TSX 컴포넌트 다국어 코드 블록 검증

```typescript
// ✅ 올바른 패턴
const label = G7Core.t('sirsoft-ecommerce.admin.product.name');
<Button>{G7Core.t('common.save')}</Button>

// ❌ 잘못된 패턴 - 하드코딩된 한국어
const label = '상품명';  // ❌
<Button>저장</Button>  // ❌
```

### 5.5 언어 파일 정의 검증

```json
// ✅ 올바른 패턴 - ko.json과 en.json 키 일치
// ko.json
{
  "admin": {
    "product": {
      "name": "상품명",
      "price": "가격"
    }
  }
}

// en.json
{
  "admin": {
    "product": {
      "name": "Product Name",
      "price": "Price"
    }
  }
}
```

**검증 항목**:

- ❌ ko에만 존재하는 키
- ❌ en에만 존재하는 키
- ✅ 동일한 키 구조

### 5.6 시더 다국어 필드 검증

```php
// ✅ 올바른 패턴 - 배열 형식
Permission::create([
    'name' => [
        'ko' => '상품 조회',
        'en' => 'View Products',
    ],
]);

// ❌ 잘못된 패턴 - 단일 언어
Permission::create([
    'name' => '상품 조회',  // ❌
]);
```

### 5.7 config 사용 검증

```php
// ✅ 올바른 패턴
$locales = config('app.supported_locales');

// ❌ 잘못된 패턴 - 하드코딩
$locales = ['ko', 'en'];  // ❌
```

### 5.8 작업계획서 검증 결과 보고 형식

```text
## 작업계획서 다국어 검증 결과

### 검증 파일
- [마크다운 파일 경로]

### 다국어 코드 블록 요약
- PHP 코드 블록: X개
- JSON 레이아웃 블록: Y개
- TSX 코드 블록: Z개
- 언어 파일 정의: W개
- 총 검증 대상: N개

### PHP 다국어 검증
- ✅ __() 함수 사용
- ❌ 하드코딩된 한국어: [위치] - "[문자열]"
  - 수정: __('messages.xxx')

### 레이아웃 JSON 다국어 검증
- ✅ $t: 사용
- ❌ 하드코딩된 한국어: [위치] - "[문자열]"
  - 수정: "$t:module.key"

### TSX 컴포넌트 다국어 검증
- ✅ G7Core.t() 사용
- ❌ 하드코딩된 한국어: [위치] - "[문자열]"

### 언어 파일 동기화 검증
- ✅ 키 동기화됨
- ❌ ko에만 존재: [키 목록]
- ❌ en에만 존재: [키 목록]

### 시더 다국어 필드 검증
- ✅ 배열 형식 사용
- ❌ 단일 언어: [위치]
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
