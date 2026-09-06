# Admin Basic — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
이 템플릿의 설계는 "관리자 화면 디자인"과 "관리자 화면 구성"을 분리하는 데 집중합니다.
컴포넌트(디자인 구현)는 이 템플릿이 소유하지만, 어떤 화면을 어떻게 구성할지(레이아웃 JSON)는
코어와 모든 번들 확장이 **각자 소유**합니다 — 이 템플릿은 그 구성을 그릴 부품만 제공합니다.
그래서 이 템플릿의 실질 소스는 컴포넌트(`src/components/`)와 코어 화면 레이아웃뿐이고, 확장
관리자 화면(모듈/플러그인이 소유)은 이 템플릿 디렉토리 밖(`modules/`, `plugins/`)에 있습니다.

필수 컴포넌트 계약(§AGENTS.md)이 이 분리를 지탱합니다 — 계약이 없으면 확장 개발자가 이
템플릿에만 있는 컴포넌트를 무심코 써버려 "관리자 화면 디자인 교체"가 사실상 불가능해집니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
routes.json (URL → 레이아웃 이름 매핑)
        │
        ▼
layouts/*.json (extends: _admin_base)
        │
        ▼
_admin_base.json (사이드바·헤더·Toast·PageTransitionIndicator·콘텐츠 슬롯·푸터)
        │
        ▼
src/components/{basic,composite,layout}/*.tsx (필수 컴포넌트 35개 포함 125개)
        │
        ▼
src/handlers/*.ts (이 템플릿 전용 핸들러 17개 — 범용 핸들러는 코어 ActionDispatcher)
```

이 트리는 코어 화면에만 적용되는 것이 아닙니다 — 모듈/플러그인의 관리자 레이아웃도 같은
`_admin_base` → 컴포넌트 경로를 그대로 타므로, 이 템플릿을 고치면 그 확장들의 화면도 같은
방향으로 바뀝니다(§AGENTS.md "수정 시 동반 의무" 참고).
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `template.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json 동기화 |
| `routes.json` | 라우트 → 레이아웃 매핑 | `php artisan template:update sirsoft-admin_basic --force` |
| `layouts/` | 레이아웃 JSON | `php artisan template:update sirsoft-admin_basic --force` (빌드 불필요) |
| `src/components/` | React 컴포넌트 | `php artisan template:build` → `php artisan template:update sirsoft-admin_basic --force` |
| `src/handlers/` | 템플릿 전용 액션 핸들러 | `php artisan template:build` → `php artisan template:update sirsoft-admin_basic --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan template:update sirsoft-admin_basic --force` |
| `editor-spec/` | 분할 편집기 스펙 | `php artisan template:update sirsoft-admin_basic --force` |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan template:update sirsoft-admin_basic --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
