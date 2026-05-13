# 컴포넌트 개발 규칙

> **참조**: [프론트엔드 가이드 인덱스](./index.md)

---

## TL;DR (5초 요약)

```text
1. HTML 태그 직접 사용 금지 (<div> → Div, <button> → Button)
2. 타입: basic (HTML 래핑), composite (조합), layout (배치)
3. 집합 컴포넌트 재사용 우선 (새로 만들기 전 기존 확인)
4. G7Core.t() 함수로 다국어 처리
5. 다크 모드: light/dark variant 함께 지정
```

---

## 하위 문서 안내

| 하위 문서 | 주요 내용 | 설명 |
|----------|----------|------|
| [components-types.md](components-types.md) | basic, composite, layout | 컴포넌트 타입별 개발 규칙, 재사용 가이드라인 |
| [components-patterns.md](components-patterns.md) | 순환 의존성, G7Core.t, skipBindingKeys | 패턴 및 다국어 처리 |
| [components-advanced.md](components-advanced.md) | componentEvent, 아이콘, 체크리스트 | 이벤트 통신, 아이콘 규칙, 개발 체크리스트 |

<!-- AUTO-GENERATED-START: frontend-template-reference -->
### 템플릿별 레퍼런스

| 템플릿 식별자 | 컴포넌트 | 핸들러 | 레이아웃 |
|--------------|---------|--------|--------|
| `sirsoft-admin_basic` | [components.md](templates/sirsoft-admin_basic/components.md) | [handlers.md](templates/sirsoft-admin_basic/handlers.md) | [layouts.md](templates/sirsoft-admin_basic/layouts.md) |
| `sirsoft-basic` | [components.md](templates/sirsoft-basic/components.md) | [handlers.md](templates/sirsoft-basic/handlers.md) | [layouts.md](templates/sirsoft-basic/layouts.md) |

<!-- AUTO-GENERATED-END: frontend-template-reference -->

---

## 목차

### 타입별 개발 규칙 ([상세 문서](components-types.md))

1. [핵심 원칙](components-types.md#핵심-원칙)
2. [기본 컴포넌트 (Basic Component)](components-types.md#1-기본-컴포넌트-basic-component)
3. [집합 컴포넌트 (Composite Component)](components-types.md#2-집합-컴포넌트-composite-component)
4. [집합 컴포넌트 재사용 가이드라인](components-types.md#3-집합-컴포넌트-재사용-가이드라인)
5. [레이아웃 컴포넌트 (Layout Component)](components-types.md#4-레이아웃-컴포넌트-layout-component)

### 패턴 및 다국어 ([상세 문서](components-patterns.md))

6. [순환 의존성 해결 패턴](components-patterns.md#순환-의존성-해결-패턴)
7. [다국어 번역 (G7Core.t)](components-patterns.md#다국어-번역-g7coret)
8. [skipBindingKeys (바인딩 지연 처리)](components-patterns.md#skipbindingkeys-바인딩-지연-처리)

### 고급 기능 ([상세 문서](components-advanced.md))

9. [컴포넌트 간 이벤트 통신 (G7Core.componentEvent)](components-advanced.md#컴포넌트-간-이벤트-통신-g7corecomponentevent)
10. [이벤트 생성 헬퍼](components-advanced.md#이벤트-생성-헬퍼)
11. [아이콘 사용 규칙 (Font Awesome)](components-advanced.md#아이콘-사용-규칙-font-awesome)
12. [Form 자동 바인딩 메타데이터 (bindingType)](components-advanced.md#form-자동-바인딩-메타데이터-bindingtype)
13. [컴포넌트 개발 체크리스트](components-advanced.md#컴포넌트-개발-체크리스트)

---

## 핵심 원칙

```
필수: 기본 컴포넌트 사용 (HTML 태그 직접 사용 금지)
필수: 기본 컴포넌트만 사용 (Div, Button, H2 등)
✅ 필수: 집합 컴포넌트 재사용 우선
```

→ [상세 문서](components-types.md#핵심-원칙)

---

## 기능 빠른 참조

### 컴포넌트 타입 요약

| 타입 | 정의 | 예시 |
|------|------|------|
| `basic` | HTML 태그 래핑 | Button, Input, Div, Icon, H1, Span |
| `composite` | 기본 컴포넌트 조합 | Card, DataGrid, Modal, Pagination |
| `layout` | 자식 요소 배치 | Container, Grid, Flex, SectionLayout |

→ [상세 문서](components-types.md)

### 다국어 처리 (G7Core.t)

```tsx
// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

// 사용
<Button>{t('common.confirm')}</Button>
<Span>{t('admin.users.pagination_info', { from: 1, to: 10, total: 100 })}</Span>
```

→ [상세 문서](components-patterns.md#다국어-번역-g7coret)

### 컴포넌트 이벤트 통신

```tsx
// 이벤트 구독
const unsubscribe = G7Core.componentEvent.on('eventName', callback);

// 이벤트 발생
G7Core.componentEvent.emit('triggerUpload:logo_uploader');
```

→ [상세 문서](components-advanced.md#컴포넌트-간-이벤트-통신-g7corecomponentevent)

### 아이콘 사용 (Font Awesome)

```tsx
import { Icon, IconName } from '../basic/Icon';

<Icon name={IconName.Check} />
<Icon name="fa-solid fa-user" />
```

```text
주의: Font Awesome Pro 전용 아이콘 사용 금지 (Light, Thin, Duotone)
주의: 다른 아이콘 라이브러리 직접 import 금지
```

→ [상세 문서](components-advanced.md#아이콘-사용-규칙-font-awesome)

### 컴포넌트 등록 체크리스트

```text
필수: 새 컴포넌트 생성 시 아래 4개 파일에 등록
□ 컴포넌트 파일 생성: templates/[vendor-template]/src/components/{type}/{Name}.tsx
□ index.ts export 추가: templates/[vendor-template]/src/components/{type}/index.ts
□ components.json 등록: templates/[vendor-template]/components.json
□ 테스트 파일 생성: templates/[vendor-template]/src/components/{type}/__tests__/{Name}.test.tsx
```

→ [상세 문서](components-advanced.md#컴포넌트-개발-체크리스트)

---

## 관련 문서

- [g7core-api.md](g7core-api.md) - G7Core 전역 API 레퍼런스
- [레이아웃 JSON 스키마](./layout-json.md) - 컴포넌트를 레이아웃 JSON에서 사용하는 방법
- [데이터 바인딩](./data-binding.md) - props에서 데이터 바인딩 사용법
- [sirsoft-admin_basic 컴포넌트](./templates/sirsoft-admin_basic/components.md) - Admin 컴포넌트 목록 (111개)
- [sirsoft-basic 컴포넌트](./templates/sirsoft-basic/components.md) - User 컴포넌트 목록 (58개)
- [다크 모드](./dark-mode.md) - 컴포넌트 다크 모드 지원 가이드
- [상태 관리](./state-management.md) - 전역/로컬 상태 관리 및 동기화 패턴
