# 보안 및 검증

> 이 문서는 그누보드7 프론트엔드 템플릿 시스템의 보안 및 검증 규칙을 설명합니다.
> 전체 보안 아키텍처 및 공격 방어 전략은 [SECURITY.md](../SECURITY.md) 참조.

---

## TL;DR (5초 요약)

```text
1. 레이아웃 JSON: FormRequest + Custom Rule 10종 검증 (서버 사전 차단)
2. XSS 방지: React 자동 이스케이프 + dangerouslySetInnerHTML 금지 + HtmlContent만 허용
3. 표현식 보안: Optional Chaining + fallback 필수 ({{value ?? ''}})
4. 상태/설정 노출: _global에 민감정보 금지 + FiltersFrontendSchema로 G7Config 최소화
5. 인증: Bearer 토큰 전용 + 401 자동 갱신 + 로그아웃 상태 정리
6. 에셋 보안: 확장자 화이트리스트 + 디렉토리 탈출 방지
```

---

## 목차

- [레이아웃 JSON 서버 검증](#레이아웃-json-서버-검증)
- [XSS 방지](#xss-방지)
- [표현식 평가 보안](#표현식-평가-보안)
- [인증/토큰 프론트엔드 보안](#인증토큰-프론트엔드-보안)
- [상태 관리 및 데이터 노출 보안](#상태-관리-및-데이터-노출-보안)
- [렌더링 오류 방어](#렌더링-오류-방어)
- [템플릿 에셋 보안](#템플릿-에셋-보안)
- [의존성 검증](#의존성-검증)
- [보안 체크리스트](#보안-체크리스트)
- [관련 문서](#관련-문서)

---

## 레이아웃 JSON 서버 검증

레이아웃 JSON은 저장 전 서버에서 **10가지 Custom Rule**로 사전 검증됩니다.
프론트엔드 개발자는 이를 인지하되, 검증 로직을 프론트엔드에서 재구현할 필요가 없습니다.

### 검증 시점

| 시점 | FormRequest | 설명 |
|------|-------------|------|
| 레이아웃 생성 | `StoreLayoutRequest` | 기본 4개 Rule |
| 레이아웃 수정 | `UpdateLayoutRequest` | 기본 4개 Rule (sometimes) |
| 콘텐츠만 수정 | `UpdateLayoutContentRequest` | 가장 엄격 — 8개 Rule |
| 상속 레이아웃 생성/수정 | `Store/UpdateLayoutInheritanceRequest` | 상속 전용 3개 Rule |

### Custom Rule 요약

| 검증 영역 | Rule | 핵심 |
|----------|------|------|
| JSON 구조 | ValidLayoutStructure | 필수 필드, 깊이 10단계 제한, 타입 검증 |
| 컴포넌트 | ComponentExists | components.json 매니페스트 대조 |
| API 엔드포인트 | WhitelistedEndpoint | `/api/(admin\|auth\|public)/` 패턴만 허용 |
| 외부 URL | NoExternalUrls | http, data, javascript 등 7개 위험 스킴 차단 |
| 상속 | ValidParentLayout | 순환 참조 방지, 상속 깊이 10 제한 |
| 슬롯 | ValidSlotStructure | 부모에서 정의된 슬롯만 허용 |
| 데이터소스 | ValidDataSourceMerge | 상속 체인 ID 고유성 |
| 권한 | ValidPermissionStructure | or/and 구조 + 깊이 3 제한 |
| 경로 | SafeTemplatePath | Path Traversal 13패턴 차단 |
| 파일 타입 | AllowedTemplateFileType | 14개 확장자 화이트리스트 |

> 상세: [SECURITY.md - Custom Validation Rules](../SECURITY.md#custom-validation-rules)

---

## XSS 방지

### 다층 방어 전략

| 계층 | 방어 방식 | 담당 |
|------|----------|------|
| 저장 시 | 레이아웃 JSON 검증 | Custom Rule |
| 렌더링 시 | 자동 이스케이프 | React |
| URL 검증 | 외부 URL 차단 | NoExternalUrls Rule |

### React 자동 이스케이프

React는 JSX에서 출력되는 모든 값을 자동으로 이스케이프합니다:

```jsx
// React가 자동으로 HTML 엔티티로 변환
<div>{userInput}</div>  // "<script>" → "&lt;script&gt;"
```

### dangerouslySetInnerHTML 금지

```
필수: 텍스트 출력 시 React의 기본 렌더링 사용
금지: dangerouslySetInnerHTML 직접 사용
```

### HtmlContent / HtmlEditor (HTML 렌더링이 필요한 경우)

HTML을 렌더링해야 하는 경우 (게시판 본문, 상품 설명 등) **반드시 지정된 컴포넌트만** 사용합니다:

| 컴포넌트 | 용도 | 비고 |
|----------|------|------|
| `HtmlContent` | HTML 콘텐츠 읽기 전용 렌더링 | 서버에서 sanitize된 데이터만 사용 |
| `HtmlEditor` | HTML 편집기 (WYSIWYG) | 내부적으로 안전한 렌더링 처리 |

```json
// ✅ HtmlContent 컴포넌트로 안전한 HTML 렌더링
{
  "type": "composite",
  "name": "HtmlContent",
  "props": {
    "html": "{{post?.data?.content ?? ''}}"
  }
}

// ❌ 임의 컴포넌트에서 dangerouslySetInnerHTML 사용 금지
```

> 상세: [editors.md](editors.md)

### Translation 키 보안

`$t:key` 값은 다국어 파일에서만 로드되며, 사용자 입력 키를 직접 사용할 수 없습니다.

---

## 표현식 평가 보안

### 엔진 파서 메커니즘

템플릿 엔진은 `{{expression}}` 내부를 JavaScript `new Function()` 기반으로 평가합니다.

**보안 전제**: 레이아웃 JSON은 서버에서 4단계 Custom Rule 검증을 거쳐 저장되므로, 악의적 표현식이 포함될 가능성은 서버 검증으로 사전 차단됩니다. `new Function()`은 관리자가 작성한 검증된 표현식만 실행합니다.

### 안전한 데이터 접근 (필수)

데이터 로드 전에 렌더링이 시작되므로, **Optional Chaining + fallback**이 없으면 런타임 에러가 발생합니다:

```json
// ❌ 위험: 데이터 로드 전 undefined 에러
{ "text": "{{user.profile.name}}" }

// ✅ 안전: 단계적 확인 + fallback
{ "text": "{{user?.profile?.name ?? ''}}" }
```

### 필수 fallback 패턴

| 데이터 유형 | 패턴 | 예시 |
|------------|------|------|
| 텍스트 | `?? ''` | `{{user?.name ?? ''}}` |
| 배열 | `?? []` | `{{options ?? []}}` |
| 객체 | `?? {}` | `{{config ?? {}}}` |
| 숫자 | `?? 0` | `{{count ?? 0}}` |
| API 응답 | `?.data` | `{{products?.data?.data}}` |
| 에러 응답 | `.errors` | `{{error.errors}}` (`error.data` 아님) |

### 파서 모호성 회피

`{{}}` 내부에서 객체 리터럴 `{}`의 닫는 중괄호가 표현식 종료 `}}`와 충돌할 수 있습니다:

```json
// ❌ 파서 모호성: {}}} — 어디가 표현식 끝인지 불명확
{ "value": "{{error.data?.errors ?? {}}}" }

// ✅ 안전: API 응답 구조에 맞는 정확한 경로 사용
{ "value": "{{error.errors}}" }
```

> 상세: [data-binding.md](data-binding.md)

---

## 인증/토큰 프론트엔드 보안

### 인증 원칙

| 원칙 | 설명 |
|------|------|
| **토큰 전용** | Bearer 토큰만 사용 (세션 쿠키 의존 금지) |
| **자동 갱신** | 401 응답 시 AuthManager가 토큰 갱신 시도 |
| **실패 시 정리** | 갱신 실패 → 로그아웃 + 토큰 삭제 + 상태 초기화 + 로그인 페이지 리다이렉트 |

### 401 응답 처리 흐름

```
API 요청 → 401 응답
    ↓
AuthManager.refreshToken()
    ├── 성공 → 원래 요청 재시도
    └── 실패 → logout()
              ├── 토큰 삭제
              ├── _global 인증 상태 초기화
              └── 로그인 페이지 리다이렉트
```

### 로그아웃 시 클라이언트 필수 정리

1. 토큰 삭제 (Authorization 헤더 제거)
2. AuthManager 상태 초기화
3. 서버의 세션 쿠키 자동 만료 (서버가 401 응답 시 `Set-Cookie` 만료 헤더 전송)

> 상세: [auth-system.md](auth-system.md), [백엔드 인증](../backend/authentication.md)

---

## 상태 관리 및 데이터 노출 보안

### _global 상태 민감 정보 금지

`_global` 상태는 브라우저 DevTools(React DevTools, 콘솔)로 **누구나 조회** 가능합니다.

```
❌ _global에 저장 금지: API 토큰, 비밀번호, 개인정보, 내부 시스템 경로
✅ _global 적합 대상: UI 상태 (사이드바 열림, 테마), 공개 설정값, 인증 여부 (boolean)
```

### initGlobal 서버 주입 범위 제한

`initGlobal`로 서버에서 주입하는 데이터는 최소한으로 제한합니다:

```json
// ✅ 필요한 최소 데이터만 주입
{
  "initGlobal": {
    "siteName": "{{settings.site_name}}",
    "isDebug": "{{settings.debug_mode}}"
  }
}

// ❌ 민감 데이터 주입 금지
{
  "initGlobal": {
    "dbPassword": "{{settings.db_password}}",
    "apiSecret": "{{settings.api_key}}"
  }
}
```

### window.G7Config 설정 노출 제어

서버 설정이 `window.G7Config`를 통해 브라우저에 불필요하게 노출되지 않도록 `defaults.json`의 `frontend_schema` 섹션에서 필드 레벨로 제어합니다.

#### FiltersFrontendSchema 트레이트 (`app/Traits/FiltersFrontendSchema.php`)

코어/모듈/플러그인 설정의 프론트엔드 노출 필터링을 담당하는 공통 트레이트:

| 메서드 | 역할 |
|--------|------|
| `loadFrontendSchema($path)` | `defaults.json`에서 `frontend_schema` 섹션 로드 |
| `filterByFrontendSchema($settings, $schema)` | `expose: true`인 카테고리/필드만 필터링 |

**필터링 규칙**:

| 조건 | 동작 |
|------|------|
| 카테고리 `expose: false` | 해당 카테고리 전체 미노출 |
| 카테고리 `expose: true` + `fields` 미정의 | 카테고리 전체 노출 (하위 호환) |
| 카테고리 `expose: true` + `fields: {}` (빈 객체) | 아무 필드도 노출하지 않음 (안전 기본값) |
| 필드 `expose: false` | 해당 필드만 미노출 |
| 필드 `sensitive: true` | 해당 필드 미노출 (비밀번호, 토큰 등) |

#### 노출 차단 카테고리

| 카테고리 | expose | 사유 |
|----------|--------|------|
| `security` | `false` | 로그인 시도 제한, 비밀번호 정책 등 공격자 정보 제공 위험 |
| `mail` | `false` | SMTP password, API secret 포함 |
| `seo` | `false` | 프론트엔드 미참조 (관리자 환경설정은 API 데이터 기반) |
| `drivers` | `false` | S3 secret, Redis password 등 인프라 자격증명 포함 |

#### defaults.json 설정 예시

```json
{
  "frontend_schema": {
    "general": {
      "expose": true,
      "fields": {
        "site_name": { "type": "string", "sensitive": false },
        "site_url": { "type": "string", "sensitive": false, "expose": false },
        "admin_email": { "type": "string", "sensitive": false, "expose": false }
      }
    },
    "security": {
      "expose": false,
      "_comment": "보안 정책은 프론트엔드에서 미참조 — 노출 시 공격자에게 정보 제공 위험"
    },
    "mail": {
      "expose": false,
      "_comment": "mail 설정은 password 포함으로 프론트엔드에 노출하지 않음"
    }
  }
}
```

#### 확장(모듈/플러그인)의 설정 노출 제어

모듈/플러그인도 각자의 `defaults.json`에 `frontend_schema` 섹션을 정의하여 동일한 패턴으로 노출을 제어합니다:

```json
// modules/_bundled/vendor-module/config/defaults.json
{
  "defaults": { },
  "frontend_schema": {
    "some_category": {
      "expose": true,
      "fields": {
        "public_field": { "type": "string", "sensitive": false },
        "secret_field": { "type": "string", "sensitive": true }
      }
    },
    "internal_category": {
      "expose": false
    }
  }
}
```

- `ModuleSettingsService`와 `PluginSettingsService`는 `FiltersFrontendSchema` 트레이트를 사용
- 각 확장의 `defaults.json`에서 `frontend_schema`를 로드하여 `filterByFrontendSchema()`로 필터링
- `frontend_schema`가 없는 플러그인은 하위 호환을 위해 기존 동작 유지

### globalHeaders 민감 헤더 관리

데이터 소스의 `globalHeaders`에 민감 헤더를 설정할 때는 pattern으로 범위를 제한합니다:

```json
// ✅ 특정 API 경로에만 민감 헤더 적용
{
  "globalHeaders": [
    {
      "pattern": "/api/admin/*",
      "headers": { "X-Admin-Token": "{{_global.adminToken}}" }
    }
  ]
}

// ❌ 모든 요청에 민감 헤더 전송 (외부 요청에도 노출 위험)
{
  "globalHeaders": [
    {
      "pattern": "*",
      "headers": { "X-Secret-Key": "{{_global.secretKey}}" }
    }
  ]
}
```

> 상세: [state-management.md](state-management.md), [data-sources-advanced.md](data-sources-advanced.md)

#### 관련 코드

| 파일 | 역할 |
|------|------|
| `app/Traits/FiltersFrontendSchema.php` | 공통 필터링 트레이트 |
| `app/Services/SettingsService.php` | 코어 `getFrontendSettings()` |
| `app/Services/ModuleSettingsService.php` | 모듈 `getAllActiveSettings()` |
| `app/Services/PluginSettingsService.php` | 플러그인 `getAllActiveSettings()` |
| `app/Http/View/Composers/TemplateComposer.php` | Admin 뷰 설정 바인딩 |
| `app/Http/View/Composers/UserTemplateComposer.php` | User 뷰 설정 바인딩 |
| `config/settings/defaults.json` | 코어 설정 스키마 정의 |

---

## 렌더링 오류 방어

백엔드에서 잘못된 데이터가 전달되더라도 프론트엔드가 완전히 깨지지 않도록 방어 로직이 필요합니다.

### 문제 상황

Laravel API에서 빈 객체 `{}`가 반환되면 React에서 다음 오류가 발생합니다:

```text
Error #31: Objects are not valid as a React child
```

**원인**: Laravel Resource의 `$this->when()`이 `MissingValue` 객체를 반환하고, 커스텀 메서드에서는 이 객체가 필터링되지 않아 빈 객체로 JSON에 포함됨

### 방어 전략

| 계층 | 방어 방식 | 위치 |
|------|----------|------|
| 백엔드 | 삼항 연산자 사용 | Resource 커스텀 메서드 |
| 컴포넌트 | safeRenderValue() | DataGrid 등 |
| 렌더러 | ErrorBoundary | DynamicRenderer |

### 1. safeRenderValue() 패턴

셀 값을 안전하게 렌더링 가능한 형태로 변환합니다:

```typescript
const safeRenderValue = (value: any): React.ReactNode => {
  // null, undefined는 빈 문자열로
  if (value === null || value === undefined) {
    return '';
  }

  // 원시 타입은 그대로 반환
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }

  // 배열인 경우 각 요소를 안전하게 변환 후 조인
  if (Array.isArray(value)) {
    return value.map((v) => safeRenderValue(v)).join(', ');
  }

  // 객체인 경우
  if (typeof value === 'object') {
    // React 엘리먼트는 그대로 반환
    if (React.isValidElement(value)) {
      return value;
    }

    // MissingValue나 빈 객체 처리
    if (Object.keys(value).length === 0) {
      return '';
    }

    // 일반 객체는 JSON 문자열로 변환 시도
    try {
      return JSON.stringify(value);
    } catch {
      return '[Object]';
    }
  }

  return String(value);
};
```

**적용 대상**: 데이터를 직접 렌더링하는 모든 컴포넌트

### 2. ComponentErrorBoundary 패턴

컴포넌트 렌더링 중 오류가 발생해도 전체 페이지가 깨지지 않도록 합니다:

```typescript
class ComponentErrorBoundary extends Component<Props, State> {
  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  render(): React.ReactNode {
    if (this.state.hasError) {
      return (
        <div className="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-800 dark:text-red-400">
          <div className="font-semibold">컴포넌트 로드 실패</div>
          <div className="text-xs">
            {this.props.componentName && `[${this.props.componentName}] `}
            데이터를 표시할 수 없습니다.
          </div>
          {this.state.error && (
            <details className="mt-2">
              <summary>상세 정보</summary>
              <div>{this.state.error.message}</div>
            </details>
          )}
        </div>
      );
    }
    return this.props.children;
  }
}
```

**적용 위치**: `DynamicRenderer.tsx`에서 각 컴포넌트를 ErrorBoundary로 래핑

### 컴포넌트 개발 시 체크리스트

- [ ] API 데이터를 직접 렌더링하는 경우 `safeRenderValue()` 사용
- [ ] 빈 객체 `{}`가 들어와도 에러가 발생하지 않는지 확인
- [ ] null, undefined 값 처리가 되어 있는지 확인

### 관련 백엔드 규정

백엔드에서의 원인과 해결책은 다음 문서 참조:

- [api-resources.md - $this->when() 사용 시 주의사항](../backend/api-resources.md#thisw-사용-시-주의사항)

---

## 템플릿 에셋 보안

템플릿의 빌드된 에셋(JS, CSS, 폰트, 이미지)은 API를 통해 동적으로 제공됩니다.

### 보안 메커니즘

| 단계 | 검증 | 방어 대상 |
|------|------|----------|
| 1 | 템플릿 활성화 여부 확인 | 비활성 템플릿 에셋 접근 차단 |
| 2 | 파일 확장자 화이트리스트 | 실행 파일(.php, .sh) 접근 차단 |
| 3 | `realpath()` + `str_starts_with()` | 디렉토리 탈출 공격 방지 (`../../etc/passwd`) |
| 4 | MIME 타입 명시적 설정 | 콘텐츠 스니핑 방지 |

### 허용 확장자 (화이트리스트)

| 카테고리 | 확장자 |
|----------|--------|
| 스크립트 | `js`, `mjs`, `js.map` |
| 스타일 | `css`, `css.map` |
| 폰트 | `woff`, `woff2`, `ttf`, `otf`, `eot` |
| 이미지 | `png`, `jpg`, `jpeg`, `svg`, `webp`, `gif` |
| 데이터 | `json` |

### 금지 확장자 (절대 추가 금지)

| 카테고리 | 확장자 |
|----------|--------|
| PHP 실행 파일 | `.php`, `.phar` |
| 실행 스크립트 | `.sh`, `.bat`, `.exe` |
| 설정 파일 | `.env`, `.htaccess`, `.conf` |
| 데이터베이스 파일 | `.sql`, `.db` |
| 압축 파일 | `.zip`, `.tar`, `.gz` |

> 상세: [template-security.md](../extension/template-security.md)

---

## 의존성 검증

### 검증 대상

`template.json`의 `dependencies` 섹션에 정의된 모듈/플러그인

### 검증 시점

| 시점 | 동작 |
|------|------|
| 템플릿 활성화 시 | 의존성 설치 여부 확인 |
| 미설치 의존성 발견 | 활성화 차단 |

### template.json 예시

```json
{
  "name": "sirsoft-admin_basic",
  "version": "1.0.0",
  "dependencies": {
    "modules": ["sirsoft-core"],
    "plugins": ["sirsoft-auth"]
  }
}
```

### API 의존성 검증

템플릿 활성화 API 호출 시:
1. `dependencies.modules` 설치 확인
2. `dependencies.plugins` 설치 확인
3. 모두 설치된 경우에만 활성화 진행
4. 미설치 항목이 있으면 오류 반환

---

## 보안 체크리스트

### 레이아웃 JSON 작성 시

- [ ] 모든 데이터 바인딩에 Optional Chaining + fallback 적용 (`{{value ?? ''}}`)
- [ ] 외부 URL이 포함되지 않았는가?
- [ ] 사용한 컴포넌트가 `components.json`에 등록되어 있는가?
- [ ] `dangerouslySetInnerHTML` 사용이 없는가? (HTML 필요 시 HtmlContent 사용)
- [ ] 파서 모호성 패턴 (`{}}}`)이 없는가?
- [ ] 검증 메시지가 다국어(`__()`)로 처리되는가?

### 상태 관리 시

- [ ] `_global`에 민감 정보(토큰, 비밀번호, 개인정보)를 저장하지 않았는가?
- [ ] `initGlobal` 서버 주입 데이터가 최소한인가?
- [ ] 새 설정 필드 추가 시 `frontend_schema`에 `expose` 여부를 정의했는가?
- [ ] `sensitive: true` 필드(비밀번호, 토큰)가 노출되지 않는가?
- [ ] globalHeaders의 민감 헤더가 pattern으로 범위 제한되었는가?

### 컴포넌트 개발 시

- [ ] API 데이터를 직접 렌더링할 때 `safeRenderValue()` 사용했는가?
- [ ] 빈 객체 `{}`, null, undefined 처리가 되어 있는가?
- [ ] ErrorBoundary로 래핑되어 있는가?

### 인증 관련

- [ ] Bearer 토큰만 사용하는가? (세션 쿠키 의존 금지)
- [ ] 401 응답 시 토큰 갱신 및 실패 시 로그아웃이 처리되는가?
- [ ] 로그아웃 시 클라이언트 상태가 완전히 정리되는가?

### 코드 리뷰 시

- [ ] `dangerouslySetInnerHTML` 사용 여부
- [ ] 검증 메시지 하드코딩 여부
- [ ] Custom Rule 적용 여부
- [ ] 모듈/플러그인 `defaults.json`에 `frontend_schema` 정의 여부

---

## 관련 문서

### 보안 전체

- [SECURITY.md](../SECURITY.md) - 전체 보안 아키텍처, 공격 방어 전략
- [template-security.md](../extension/template-security.md) - 템플릿 시스템 보안 정책, 에셋 서빙 규칙

### 프론트엔드

- [data-binding.md](data-binding.md) - 데이터 바인딩 문법 및 표현식
- [auth-system.md](auth-system.md) - 프론트엔드 인증 시스템 (AuthManager)
- [state-management.md](state-management.md) - 상태 관리 규칙
- [editors.md](editors.md) - HtmlEditor/HtmlContent 컴포넌트
- [data-sources-advanced.md](data-sources-advanced.md) - globalHeaders 등 데이터 소스 고급 기능

### 백엔드

- [authentication.md](../backend/authentication.md) - Sanctum 토큰 인증
- [validation.md](../backend/validation.md) - FormRequest 패턴
- [api-resources.md](../backend/api-resources.md) - API 리소스 규칙
- [middleware.md](../backend/middleware.md) - 미들웨어 등록 규칙

### 확장 시스템

- [permissions.md](../extension/permissions.md) - 권한 시스템
- [layout-json.md](layout-json.md) - 레이아웃 JSON 스키마
- [data-sources.md](data-sources.md) - API 엔드포인트 규칙
