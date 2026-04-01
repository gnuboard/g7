# 템플릿 전용 핸들러

> **메인 문서**: [actions-handlers.md](actions-handlers.md)
> **관련 문서**: [template-development.md](template-development.md) | [actions-handlers-ui.md](actions-handlers-ui.md)

---

## TL;DR (5초 요약)

```text
1. setLocale: 앱 언어 변경 — 엔진 빌트인 (ActionDispatcher)
2. setTheme/initTheme: 다크/라이트 모드 — 양쪽 템플릿 공통
3. 각 템플릿별 전용 핸들러 → 아래 링크 참조
4. sirsoft-admin_basic: scrollToSection, initMenuFromUrl, filterVisibility 4종, multilingualTag 3종
5. sirsoft-basic: 장바구니 6종, 상품 옵션 2종, 다중 통화 5종, 스토리지 6종
```

---

## 템플릿별 상세 문서

<!-- AUTO-GENERATED-START: frontend-template-handlers -->
| 템플릿 식별자 | 핸들러 문서 | TL;DR 핵심 |
|--------------|-----------|----------|
| `sirsoft-admin_basic` | [handlers.md](templates/sirsoft-admin_basic/handlers.md) | setLocale: 앱 언어 변경 (locale 파라미터) |
| `sirsoft-basic` | [handlers.md](templates/sirsoft-basic/handlers.md) | setTheme/initTheme: 다크/라이트 모드 전환 (admin과 동일 키 공유) |

<!-- AUTO-GENERATED-END: frontend-template-handlers -->

---

## 엔진 빌트인 핸들러

아래 핸들러는 **템플릿과 무관하게** 엔진 레벨(ActionDispatcher)에서 제공됩니다:

| 핸들러 | 설명 |
|--------|------|
| `setLocale` | 앱 언어 변경 (번역 파일 재로드) |

`setLocale`은 별도 템플릿 등록 없이 모든 레이아웃에서 사용 가능합니다.

---

## 공통 핸들러 (양 템플릿 모두 등록)

| 핸들러 | 설명 | 소스 |
|--------|------|------|
| `setTheme` | 테마 변경 (light/dark/auto) | setThemeHandler.ts |
| `initTheme` | 저장된 테마 복원 | setThemeHandler.ts |

---

## 관련 문서

- [액션 핸들러 개요](actions-handlers.md)
- [템플릿 개발 가이드](template-development.md)
- [다크 모드 지원](dark-mode.md)
- [sirsoft-admin_basic 컴포넌트](templates/sirsoft-admin_basic/components.md)
- [sirsoft-basic 컴포넌트](templates/sirsoft-basic/components.md)
