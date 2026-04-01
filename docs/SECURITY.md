# 그누보드7 템플릿 엔진 보안 가이드

> 그누보드7 레이아웃 JSON 보안 아키텍처 — Custom Validation Rules, FormRequest 검증 계층, 공격 방어 전략

---

## TL;DR (5초 요약)

```text
1. 다층 방어: FormRequest(10개 Custom Rule) → Service → Repository(ORM) → React(자동 이스케이프)
2. 10개 Custom Rule: JSON 구조, 컴포넌트, 엔드포인트, URL, 상속, 슬롯, 데이터소스, 권한, 경로, 파일타입
3. 7개 FormRequest: 용도별 Rule 조합 차등 적용 (Store/Update/Content/Inheritance/Preview/Get)
4. 상속 보안: 순환 참조 감지 + 깊이 10 제한 + 부모 슬롯/데이터소스 ID 고유성 검증
5. 확장 가능: 6개 Hook으로 모듈/플러그인이 검증 규칙을 동적으로 추가 가능
```

---

## 목차

1. [개요](#개요)
2. [보안 아키텍처](#보안-아키텍처)
3. [검증 계층](#검증-계층)
4. [Custom Validation Rules](#custom-validation-rules)
5. [FormRequest별 Rule 적용 현황](#formrequest별-rule-적용-현황)
6. [레이아웃 상속 보안](#레이아웃-상속-보안)
7. [경로 보안 (Path Traversal 방어)](#경로-보안-path-traversal-방어)
8. [Hook 기반 검증 확장](#hook-기반-검증-확장)
9. [공격 방어 전략](#공격-방어-전략)
10. [보안 모범 사례](#보안-모범-사례)
11. [관련 문서](#관련-문서)

---

## 개요

그누보드7 템플릿 엔진은 JSON 기반 레이아웃으로 화면을 동적으로 구성합니다. 이 유연성은 악의적 입력을 통한 공격 가능성을 내포하므로, **10개 Custom Validation Rule**과 **7개 FormRequest**를 조합한 다층 방어 체계로 보호합니다.

### 보안 원칙

1. **다층 방어 (Defense in Depth)**: FormRequest → Service → Repository → 프론트엔드, 각 계층에서 독립적 검증
2. **최소 권한 원칙 (Principle of Least Privilege)**: 필요한 최소한의 권한만 부여
3. **화이트리스트 방식 (Whitelist Approach)**: 명시적으로 허용된 것만 통과
4. **검증 우선 (Fail Secure)**: 검증 실패 시 안전한 상태로 복귀
5. **확장 가능한 검증 (Extensible Validation)**: Hook을 통해 모듈/플러그인이 검증 규칙을 동적으로 추가 가능

---

## 보안 아키텍처

### 전체 검증 플로우

```text
사용자 요청
    ↓
1. Controller 진입 전 검증 (FormRequest + 10개 Custom Rule)
    ├─ ValidLayoutStructure: JSON 스키마 + 중첩 깊이 + actions + permissions 검증
    ├─ ComponentExists: 컴포넌트 매니페스트 대조 (3카테고리, 1시간 캐싱)
    ├─ WhitelistedEndpoint: API 엔드포인트 화이트리스트 + 경로 트래버설 차단
    ├─ NoExternalUrls: 7개 위험 URI 스킴 + 프로토콜 상대 URL 차단
    ├─ ValidParentLayout: 상속 순환 참조 감지 + 깊이 10 제한
    ├─ ValidSlotStructure: 부모에서 정의된 슬롯만 허용
    ├─ ValidDataSourceMerge: 상속 체인 전체 데이터소스 ID 고유성
    ├─ ValidPermissionStructure: or/and 구조 + 깊이 3 제한 + 정규식 형식
    ├─ SafeTemplatePath: 13개 Path Traversal 패턴 + 5회 URL 디코딩 + NULL 바이트
    └─ AllowedTemplateFileType: 14개 확장자 화이트리스트
    ↓
2. Hook 기반 확장 검증 (모듈/플러그인이 추가한 규칙)
    ↓
3. Service 계층 (비즈니스 로직)
    ├─ 레이아웃 상속 병합
    ├─ 훅 실행 (before_save, after_save)
    └─ 데이터베이스 저장
    ↓
4. 프론트엔드 렌더링
    ├─ React 자동 이스케이프 (XSS 방지)
    ├─ CSRF 토큰 검증 (Laravel Sanctum Bearer 토큰)
    └─ Eloquent ORM 파라미터 바인딩 (SQL Injection 방지)
```

### 보안 계층 구조

```text
┌─────────────────────────────────────────────────────────────┐
│                    사용자 브라우저                            │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  React 렌더링 (자동 이스케이프)                        │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                           ↕ HTTPS
┌─────────────────────────────────────────────────────────────┐
│                    Laravel Backend                           │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  1. FormRequest 검증 (Controller 진입 전)            │   │
│  │     10개 Custom Rule + Hook 확장 규칙                │   │
│  └──────────────────────────────────────────────────────┘   │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  2. Service 계층 (비즈니스 로직)                      │   │
│  │     레이아웃 상속 병합 + 훅 실행                      │   │
│  └──────────────────────────────────────────────────────┘   │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  3. Repository 계층 (데이터 접근)                     │   │
│  │     Eloquent ORM (파라미터 바인딩)                    │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                           ↕
┌─────────────────────────────────────────────────────────────┐
│                      MySQL Database                          │
└─────────────────────────────────────────────────────────────┘
```

---

## 검증 계층

### 1단계: FormRequest 검증 (Controller 진입 전)

**위치**: `app/Http/Requests/Layout/`

**목적**: Controller에 도달하기 전에 모든 악의적 입력을 차단

G7은 **용도별 7개 FormRequest**로 검증을 분리합니다. 각 FormRequest는 용도에 맞는 Custom Rule 조합을 적용하며, Hook을 통해 모듈/플러그인이 규칙을 확장할 수 있습니다.

> 상세: [FormRequest별 Rule 적용 현황](#formrequest별-rule-적용-현황)

### 2단계: Service 계층 (비즈니스 로직)

**위치**: `app/Services/LayoutService.php`

**특징**:
- 검증 로직 없음 (FormRequest에서 완료)
- 순수 비즈니스 로직만 처리
- 훅 시스템을 통한 확장성 (`before_save`, `after_save`)

### 3단계: Repository 계층 (데이터 접근)

**위치**: `app/Repositories/LayoutRepository.php`

**특징**:
- Eloquent ORM 사용 (파라미터 바인딩으로 SQL Injection 자동 방어)
- 직접 SQL 문자열 연결 금지

---

## Custom Validation Rules

G7은 레이아웃 JSON 보안을 위해 **10개의 Custom Validation Rule**을 제공합니다.

### 1. ValidLayoutStructure

**파일**: `app/Rules/ValidLayoutStructure.php`

**목적**: 레이아웃 JSON의 구조적 유효성 검증

**검증 항목**:
- 필수 필드 존재 확인 (`version`, `layout_name`, `components`)
- `extends` 레이아웃: `components` 또는 `slots` 중 하나 필수
- 재귀적 컴포넌트 구조 검증 (type, name 필수)
- 컴포넌트 `type`: `basic`, `composite`, `layout` 중 하나만 허용
- **최대 중첩 깊이 제한: 10단계** (`MAX_DEPTH = 10`)
- `actions` 배열 구조 검증 (`type` 또는 `event` 중 하나 필수)
- `permissions` 필드: `ValidPermissionStructure` Rule에 위임
- 슬롯 참조(`{ "slot": "name" }`)와 Partial 참조(`{ "partial": "path" }`) 허용

### 2. ComponentExists

**파일**: `app/Rules/ComponentExists.php`

**목적**: 존재하지 않는 컴포넌트 참조 방지

**검증 방법**:
1. 템플릿의 `components.json` 매니페스트 로드
2. **3개 카테고리**: `basic`, `composite`, `layout` 컴포넌트를 Set으로 구축
3. 레이아웃 JSON의 모든 `name` 필드를 매니페스트와 대조
4. 재귀적으로 `children` 배열 검증

**캐싱 전략**:
- 캐시 키: `template.{template_id}.components_manifest`
- **캐시 TTL: 1시간** (`CACHE_TTL = 3600`)

### 3. WhitelistedEndpoint

**파일**: `app/Rules/WhitelistedEndpoint.php`

**목적**: API 엔드포인트 화이트리스트 검증

**허용 패턴**:

```regex
^/api/(admin|auth|public)/
```

**검증 대상**:
- `data_sources[].endpoint` — 데이터소스 엔드포인트
- `components[].actions[].endpoint` — 액션 핸들러 엔드포인트 (재귀적 children 포함)

**차단**:
- 외부 URL (`http://`, `https://`)
- 비공개 API (`/api/internal/*`)
- 직접 경로 (`/admin/*`)
- **경로 트래버설**: `../`, `..\` 패턴 차단

### 4. NoExternalUrls

**파일**: `app/Rules/NoExternalUrls.php`

**목적**: 외부 URL 차단으로 데이터 유출 및 악의적 리소스 로딩 방지

**차단 대상 — 7개 위험 URI 스킴**:

| 스킴 | 위험성 |
|------|--------|
| `http://` | 외부 서버 통신, 데이터 유출 |
| `https://` | 외부 서버 통신, 데이터 유출 |
| `data:` | 인라인 데이터 삽입, XSS 벡터 |
| `javascript:` | 임의 JavaScript 실행 |
| `vbscript:` | 임의 VBScript 실행 (IE) |
| `file:` | 로컬 파일 시스템 접근 |
| `ftp:` | 외부 FTP 서버 통신 |

**추가 차단**: `//`로 시작하는 프로토콜 상대 URL

**검증 범위**: `components[]` → `props`, `actions` 내 모든 문자열 값을 재귀적으로 스캔

### 5. ValidParentLayout

**파일**: `app/Rules/ValidParentLayout.php`

**목적**: 레이아웃 상속 체계의 안전성 보장

**검증 항목**:
1. **부모 존재 확인**: DB에서 `template_id` + `name` 조회
2. **순환 참조 감지**: 재귀적 체인 추적 (`visited` 배열로 방문 노드 기록)
3. **상속 깊이 제한**: `MAX_INHERITANCE_DEPTH = 10`

> 상세: [레이아웃 상속 보안](#레이아웃-상속-보안)

### 6. ValidSlotStructure

**파일**: `app/Rules/ValidSlotStructure.php`

**목적**: 부모 레이아웃에서 정의된 슬롯만 허용

**검증 로직**:
1. `extends` 필드가 없으면 검증 통과
2. 부모 레이아웃의 `components`에서 `{ "slot": "name" }` 패턴을 재귀적으로 수집
3. 자식 레이아웃의 `slots` 키가 부모에 정의된 슬롯 이름과 일치하는지 검증
4. **부모에 없는 슬롯 이름 사용 시 차단**

### 7. ValidDataSourceMerge

**파일**: `app/Rules/ValidDataSourceMerge.php`

**목적**: 상속 체인 전체에서 데이터소스 ID 고유성 보장

**검증 로직**:
1. 현재 레이아웃 내 `data_sources[].id` 중복 검사
2. `extends`가 있으면 **부모 체인 전체를 순회**하여 모든 데이터소스 ID 수집
3. 현재 레이아웃 ID와 부모 체인 ID의 교집합 검사 → **중복 시 차단**
4. 무한 루프 방지 (`visited` 배열)

### 8. ValidPermissionStructure

**파일**: `app/Rules/ValidPermissionStructure.php`

**목적**: 권한 구조의 형식적 유효성 검증

**지원 구조**:
- **Flat array**: `["perm.read", "perm.write"]`
- **OR 구조**: `{ "or": ["perm.read", "perm.write"] }` (하나라도 만족)
- **AND 구조**: `{ "and": ["perm.read", "perm.write"] }` (모두 만족)
- **중첩 구조**: `{ "or": ["perm.a", { "and": ["perm.b", "perm.c"] }] }`

**제한**:
- **최대 중첩 깊이: 3단계** (`MAX_DEPTH = 3`)
- or/and 연산자에 **최소 2개 항목** 필수 (`MIN_OPERATOR_ITEMS = 2`)
- 권한 식별자 정규식: `/^[a-z0-9_-]+\.[a-z0-9_-]+(\.[a-z0-9_-]+)*$/i`

**사용 위치**: 레이아웃 최상위 `permissions` + 컴포넌트 레벨 `permissions` (ValidLayoutStructure에서 위임)

### 9. SafeTemplatePath

**파일**: `app/Rules/SafeTemplatePath.php`

**목적**: 템플릿 파일 경로의 Path Traversal 공격 방지

> 상세: [경로 보안 (Path Traversal 방어)](#경로-보안-path-traversal-방어)

### 10. AllowedTemplateFileType

**파일**: `app/Rules/AllowedTemplateFileType.php`

**목적**: 허용된 파일 확장자만 통과

**14개 허용 확장자**:

| 카테고리 | 확장자 |
|---------|--------|
| Scripts | `js`, `mjs` |
| Styles | `css` |
| Data | `json` |
| Images | `png`, `jpg`, `jpeg`, `svg`, `webp`, `gif` |
| Fonts | `woff`, `woff2`, `ttf`, `otf`, `eot` |

**그 외 모든 확장자 차단** (PHP, HTML, EXE 등)

---

## FormRequest별 Rule 적용 현황

G7은 레이아웃 CRUD 작업별로 **7개 FormRequest**를 운용합니다. 각 FormRequest는 용도에 맞는 Rule 조합을 적용합니다.

### 비교 표

| FormRequest | 용도 | VLS | CE | WE | NEU | VPL | VSS | VDM | VPS |
|-------------|------|:---:|:--:|:--:|:---:|:---:|:---:|:---:|:---:|
| **StoreLayoutRequest** | 새 레이아웃 생성 | ✅ | ✅ | ✅ | ✅ | | | | |
| **UpdateLayoutRequest** | 메타+콘텐츠 수정 | ✅* | ✅* | ✅* | ✅* | | | | |
| **UpdateLayoutContentRequest** | 콘텐츠만 수정 | ✅ | | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **StoreLayoutInheritanceRequest** | 상속 레이아웃 생성 | | | | | ✅ | ✅ | ✅ | |
| **UpdateLayoutInheritanceRequest** | 상속 레이아웃 수정 | | | | | ✅ | ✅ | ✅ | |
| **StoreLayoutPreviewRequest** | 미리보기 | | | | | | | | |
| **GetLayoutRequest** | 레이아웃 조회 | | | | | | | | |

**범례**: VLS=ValidLayoutStructure, CE=ComponentExists, WE=WhitelistedEndpoint, NEU=NoExternalUrls, VPL=ValidParentLayout, VSS=ValidSlotStructure, VDM=ValidDataSourceMerge, VPS=ValidPermissionStructure
**✅\*** = `sometimes` 규칙 (필드가 존재할 때만 적용)

### 핵심 포인트

- **StoreLayoutRequest / UpdateLayoutRequest**: 기본 4개 Rule (구조, 컴포넌트, 엔드포인트, URL)
- **UpdateLayoutContentRequest**: 가장 엄격 — 상속 관련 4개 Rule 추가 (총 8개)
- **StoreLayoutInheritanceRequest / UpdateLayoutInheritanceRequest**: 상속 전용 3개 Rule
- **StoreLayoutPreviewRequest**: 최소 검증 (`content: required, array`)
- **GetLayoutRequest**: 조회 전용 (보안 Rule 없음)

---

## 레이아웃 상속 보안

G7 레이아웃은 `extends`/`slots`/`partial` 시스템으로 상속을 지원합니다. 상속 체계의 안전성은 3개 Rule이 협력하여 보장합니다.

### 순환 참조 방지 (ValidParentLayout)

```text
A extends B → B extends C → C extends A  ← 차단!
```

**메커니즘**:
1. `extends` 필드의 부모 레이아웃을 DB에서 조회
2. 부모의 `extends`를 재귀적으로 추적 (`visited` 배열로 방문 기록)
3. 이미 방문한 레이아웃 또는 자기 자신을 참조하면 순환 참조로 판정
4. **최대 상속 깊이: 10단계** — 초과 시 차단

### 슬롯 안전성 (ValidSlotStructure)

```text
부모 레이아웃에 { "slot": "header" }, { "slot": "content" } 정의
                                    ↓
자식 레이아웃에서 slots: { "header": [...], "footer": [...] }
                                                    ↑ 차단! (부모에 "footer" 슬롯 미정의)
```

- 부모 `components` 트리를 재귀 탐색하여 `{ "slot": "name" }` 패턴 수집
- 자식의 `slots` 키가 부모 슬롯 목록에 포함되는지 검증

### 데이터소스 ID 고유성 (ValidDataSourceMerge)

```text
조부모: data_sources: [{ id: "users" }]
부모:   data_sources: [{ id: "posts" }]
자식:   data_sources: [{ id: "users" }]  ← 차단! (조부모와 ID 충돌)
```

- **상속 체인 전체**를 순회하여 모든 데이터소스 ID 수집
- 현재 레이아웃 ID와의 교집합 검사
- 무한 루프 방지 로직 포함

---

## 경로 보안 (Path Traversal 방어)

**파일**: `app/Rules/SafeTemplatePath.php`

G7은 OS 레벨 파일 권한에 의존하지 않고, **애플리케이션 레벨에서 경로를 적극적으로 검증**합니다.

### 13개 Path Traversal 패턴 차단

| 패턴 | 설명 |
|------|------|
| `../` | Unix 상위 디렉토리 |
| `..\` | Windows 상위 디렉토리 |
| `//` | 이중 슬래시 |
| `%2e%2e%2f` | URL 인코딩 `../` |
| `%2e%2e/` | 부분 URL 인코딩 |
| `%2e%2e%5c` | URL 인코딩 `..\` |
| `%2e%2e\` | 부분 URL 인코딩 |
| `..%2f` | 혼합 인코딩 `../` |
| `..%5c` | 혼합 인코딩 `..\` |
| `.%2e/` | 부분 인코딩 `../` |
| `.%2e\` | 부분 인코딩 `..\` |

### 다중 인코딩 공격 방지

```php
// 최대 5회 반복 URL 디코딩 (이중/삼중 인코딩 공격 방지)
for ($i = 0; $i < 5 && $decodedPath !== $previousPath; $i++) {
    $previousPath = $decodedPath;
    $decodedPath = urldecode($decodedPath);
}
```

공격자가 `%252e%252e%252f` (삼중 인코딩)를 사용해도 5회 디코딩으로 원본 패턴이 드러남

### 추가 방어

| 방어 항목 | 설명 |
|----------|------|
| **절대 경로 차단** | `/`, `\`, `C:` 등 절대 경로 시작 패턴 차단 (Windows/Linux 모두) |
| **NULL 바이트 차단** | `\0` 문자 감지 시 차단 (C 언어 문자열 종료 공격) |
| **basePath 외부 접근 차단** | `realpath()` 정규화 후 `basePath` 외부 경로 감지 시 차단 |

---

## Hook 기반 검증 확장

모듈/플러그인은 **6개 Filter Hook**을 통해 레이아웃 검증 규칙을 동적으로 추가할 수 있습니다.

### 사용 가능한 Hook

| Hook 이름 | FormRequest | 용도 |
|----------|-------------|------|
| `core.layout.store_validation_rules` | StoreLayoutRequest | 레이아웃 생성 시 규칙 추가 |
| `core.layout.update_validation_rules` | UpdateLayoutRequest | 레이아웃 수정 시 규칙 추가 |
| `core.layout.update_content_validation_rules` | UpdateLayoutContentRequest | 콘텐츠 수정 시 규칙 추가 |
| `core.layout.store_inheritance_validation_rules` | StoreLayoutInheritanceRequest | 상속 레이아웃 생성 시 규칙 추가 |
| `core.layout.update_inheritance_validation_rules` | UpdateLayoutInheritanceRequest | 상속 레이아웃 수정 시 규칙 추가 |
| `core.layout.get_validation_rules` | GetLayoutRequest | 레이아웃 조회 시 규칙 추가 |

### 사용 예시

```php
// 모듈의 Listener에서 커스텀 검증 규칙 추가
class LayoutValidationListener
{
    public function handle(array $rules, StoreLayoutRequest $request): array
    {
        // 모듈 전용 검증 규칙 추가
        $rules['content'][] = new MyCustomRule();

        return $rules;
    }
}
```

```php
// 모듈의 ServiceProvider에서 Hook 등록
HookManager::addFilter(
    'core.layout.store_validation_rules',
    [LayoutValidationListener::class, 'handle'],
    priority: 10
);
```

> 참고: Filter 훅이므로 리스너에서 `type: 'filter'` 명시 필수 (미지정 시 반환값 무시)
> 상세: [훅 시스템](../extension/hooks.md)

---

## 공격 방어 전략

### 공격 시나리오별 방어 매핑

| 공격 유형 | 공격 벡터 | 방어 메커니즘 | 차단 계층 |
|---------|---------|------------|---------|
| **XSS** | `<script>` 태그 삽입 | React 자동 이스케이프 | 프론트엔드 |
| **SQL Injection** | `'; DROP TABLE--` | Eloquent ORM 파라미터 바인딩 | Repository |
| **CSRF** | 토큰 없는 요청 | Laravel Sanctum Bearer 토큰 | 미들웨어 |
| **경로 트래버설** | `../../etc/passwd` | SafeTemplatePath (13패턴 + 5회 디코딩) | FormRequest |
| **다중 인코딩 경로 트래버설** | `%252e%252e%252f` | SafeTemplatePath 5회 반복 디코딩 | FormRequest |
| **NULL 바이트 경로 공격** | `file.php\0.jpg` | SafeTemplatePath NULL 바이트 감지 | FormRequest |
| **외부 리소스 로딩** | `https://evil.com` | NoExternalUrls (7개 스킴) | FormRequest |
| **XSS via URI** | `javascript:alert(1)` | NoExternalUrls (`javascript:` 차단) | FormRequest |
| **Data URI 삽입** | `data:text/html,...` | NoExternalUrls (`data:` 차단) | FormRequest |
| **프로토콜 상대 URL** | `//evil.com/malicious.js` | NoExternalUrls (`//` 차단) | FormRequest |
| **허용되지 않은 API** | `/api/internal/` | WhitelistedEndpoint | FormRequest |
| **API 경로 트래버설** | `/api/admin/../internal/` | WhitelistedEndpoint (`../` 차단) | FormRequest |
| **존재하지 않는 컴포넌트** | `MaliciousComponent` | ComponentExists (매니페스트 대조) | FormRequest |
| **DoS (깊은 중첩)** | 11단계 이상 | ValidLayoutStructure (`MAX_DEPTH = 10`) | FormRequest |
| **DoS (상속 깊이)** | 11단계 이상 | ValidParentLayout (`MAX_INHERITANCE_DEPTH = 10`) | FormRequest |
| **DoS (권한 중첩)** | 4단계 이상 | ValidPermissionStructure (`MAX_DEPTH = 3`) | FormRequest |
| **상속 순환 참조** | A→B→C→A | ValidParentLayout (visited 추적) | FormRequest |
| **유령 슬롯 주입** | 부모에 없는 슬롯 | ValidSlotStructure | FormRequest |
| **데이터소스 ID 충돌** | 부모와 동일 ID | ValidDataSourceMerge (체인 전체 검사) | FormRequest |
| **위험 파일 업로드** | `malicious.php` | AllowedTemplateFileType (14개 화이트리스트) | FormRequest |

### 복합 공격 방어

악의적 사용자가 여러 공격 기법을 조합하더라도, 각 계층에서 독립적으로 검증하므로 하나의 공격이 통과하더라도 다음 계층에서 차단됩니다.

```json
{
  "version": "1.0.0",
  "layout_name": "combined_attack",
  "data_sources": [
    {
      "endpoint": "/api/internal/secrets",
      "params": { "filter": "'; DROP TABLE--" }
    }
  ],
  "components": [
    {
      "name": "NonExistentComponent",
      "type": "basic",
      "props": {
        "imageUrl": "https://evil.com/image.jpg",
        "onClick": "<script>alert(1)</script>"
      }
    }
  ]
}
```

**결과**: FormRequest의 첫 번째 검증 규칙에서 차단 (422 응답)
- `WhitelistedEndpoint`: `/api/internal/` 차단
- `ComponentExists`: `NonExistentComponent` 차단
- `NoExternalUrls`: `https://evil.com` 차단
- React: `<script>` 태그는 자동 이스케이프 (텍스트로 표시)
- Eloquent ORM: SQL 구문은 파라미터 바인딩으로 안전 처리

---

## 보안 모범 사례

### 템플릿 개발자를 위한 지침

1. **컴포넌트 명세 문서화**: `components.json`에 모든 컴포넌트를 `basic`/`composite`/`layout` 카테고리로 분류 등록
2. **외부 의존성 최소화**: 외부 CDN 사용 자제, `/public/build/template/` 디렉토리에 번들링
3. **props 타입 검증**: 컴포넌트 내부에서 TypeScript로 props 타입 검증

### 확장 개발자를 위한 지침

1. **Hook을 통한 검증 추가**: 모듈 전용 검증이 필요하면 `core.layout.*_validation_rules` Hook 사용
2. **Custom Rule 작성**: `Illuminate\Contracts\Validation\ValidationRule` 인터페이스 구현
3. **`__()` 함수 사용**: Custom Rule 에러 메시지는 반드시 다국어 처리

### 시스템 관리자를 위한 지침

1. **정기적인 보안 감사**: `MaliciousJsonTest.php` 실행으로 방어 체계 검증
2. **로그 모니터링**: 422 응답 빈도 모니터링, 반복적인 악의적 시도 IP 차단
3. **환경 변수 보호**: `.env` 파일 권한 제한 (600), API 키/DB 비밀번호 안전 보관
4. **업데이트 관리**: Laravel 및 의존성 패키지 최신 버전 유지

### 코드 리뷰 체크리스트

- [ ] 모든 검증 로직이 FormRequest에 있는가?
- [ ] Service에 검증 로직이 없는가?
- [ ] Custom Rule에서 `__()` 함수로 다국어 처리했는가?
- [ ] 외부 URL 참조가 없는가?
- [ ] `dangerouslySetInnerHTML` 사용이 없는가?
- [ ] DB 쿼리가 Eloquent ORM을 사용하는가?
- [ ] API 라우트에 인증 미들웨어가 있는가?
- [ ] 새로운 엔드포인트가 WhitelistedEndpoint 패턴에 부합하는가?
- [ ] 레이아웃 상속 시 부모 슬롯과 데이터소스 ID 충돌이 없는가?

---

## 관련 문서

### 보안 관련

- [프론트엔드 보안 및 검증](frontend/security.md) — XSS, 표현식, 상태 노출, 인증, 에셋 보안
- [템플릿 보안 정책](extension/template-security.md) — 템플릿 에셋 서빙, 경로 보안

### 백엔드

- [검증 (Validation)](backend/validation.md) — FormRequest + Custom Rule 패턴
- [인증 및 세션 처리](backend/authentication.md) — Sanctum 토큰 기반 인증
- [Service-Repository 패턴](backend/service-repository.md) — 계층 분리

### 확장 시스템

- [훅 시스템](extension/hooks.md) — Action/Filter Hook, 모듈/플러그인 확장
- [레이아웃 확장 시스템](extension/layout-extensions.md) — 레이아웃 동적 주입

### 프론트엔드

- [레이아웃 JSON 스키마](frontend/layout-json.md) — JSON 구조 명세
- [레이아웃 JSON - 상속](frontend/layout-json-inheritance.md) — extends/slots/partial 시스템
- [인증 시스템 (AuthManager)](frontend/auth-system.md) — 프론트엔드 인증 흐름

### 참고 파일

- **Custom Rules**: `app/Rules/*.php` (10개 보안 관련)
- **FormRequests**: `app/Http/Requests/Layout/*.php` (7개)
- **통합 테스트**: `tests/Feature/Security/MaliciousJsonTest.php`
- **다국어 파일**: `lang/ko/validation.php`, `lang/en/validation.php`

### 외부 문서

- [Laravel 보안 가이드](https://laravel.com/docs/12.x/authentication)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)

---

**마지막 업데이트**: 2026-03-30
