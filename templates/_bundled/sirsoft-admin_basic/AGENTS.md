# Admin Basic — 에이전트 가이드

> 이 문서는 이 템플릿을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 템플릿 (sirsoft-admin_basic) — 코어·모든 번들 확장의 관리자 화면을 그리는 유일한 admin 템플릿
2. 확장 방식: 필수 컴포넌트(35개, config/template.php)만 써서 레이아웃 JSON 작성 — 다른 admin 템플릿으로 교체돼도 동작 보장
3. 건드리면 안 되는 것: `Icon` 컴포넌트로 AdminSidebar 아이콘 렌더(반드시 `I`+FontAwesome 클래스), `_admin_base` 슬롯 구조를 임의로 재배치
4. 작업 위치: `templates/_bundled/sirsoft-admin_basic` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan template:update sirsoft-admin_basic --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
그누보드7 이 기본 제공하는 유일한 admin 타입 템플릿입니다 — 코어 관리자 화면(대시보드·사용자·역할·
설정·확장 관리 등)뿐 아니라 **모든 번들 모듈/플러그인의 관리자 레이아웃**(`resources/layouts/
admin/`)이 이 템플릿의 베이스(`_admin_base`)를 extends 하고 이 템플릿의 컴포넌트로 그려집니다.
그래서 이 템플릿의 공개 계약(필수 컴포넌트 35개, `_admin_base` 슬롯 구조)을 깨면 코어가
아니라 **전체 번들 확장의 관리자 화면**이 동시에 영향을 받습니다 — 다른 번들 확장 하나를
고치는 것과는 파급 범위가 다릅니다.

**설계 원칙**: 모듈/플러그인 개발자가 "이 컴포넌트만 쓰면 다른 admin 템플릿으로 바꿔도
안전하다"는 보장을 받도록, 필수 컴포넌트 목록(config/template.php)을 이 템플릿 하나가 아니라
**admin 템플릿이라면 지켜야 할 계약**으로 취급합니다 — 이 템플릿에만 있는 편의 컴포넌트를
필수 목록에 넣지 않습니다.

**의도적으로 하지 않는 것**: 방문자용(user) 화면은 이 템플릿의 범위가 아닙니다(`sirsoft-basic`
소관). 또한 컴포넌트 Props 전체 레퍼런스는 이 문서가 다시 나열하지 않습니다 — 코어
`docs/frontend/component-props*.md` 가 SSoT 이고, 이 문서는 이 템플릿에서만 유효한 계약
(필수 컴포넌트·AdminSidebar·SlotContainer·베이스 레이아웃 구조)만 다룹니다.
<!-- @intent END -->

## 2. 디렉토리 지도

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

## 3. 핵심 흐름

<!-- @intent START -->
**새 관리자 화면 렌더**: 방문자가 `/admin/{path}` 접근 → `routes.json` 이 경로를 레이아웃
이름으로 매핑 → 그 레이아웃이 `extends: _admin_base` 로 사이드바·헤더·Toast·콘텐츠 슬롯을
상속 → `slots.content` 에 정의된 컴포넌트(대개 `PageHeader`+`DataGrid`/`Card`/`Form`
조합)가 데이터소스 API 를 호출해 화면을 채웁니다. 이 흐름은 코어 화면과 번들 확장 화면이
완전히 동일합니다 — 확장이 관리자 화면을 추가할 때 이 템플릿을 복제하지 않고 자기
`resources/layouts/admin/*.json` 에서 그대로 `_admin_base` 를 extends 합니다.

**사이드바 접힘 상태 복원**: 페이지 로드 → `src/index.ts` 부트스트랩이 `initSidebar()` 직접
호출(레이아웃 `init_actions` 아님) → localStorage 값을 `_global.sidebarCollapsed` 에 반영 →
`_admin_base.json` 의 사이드바 영역 className 표현식이 그 값을 읽어 접힘 스타일 적용.

**로케일 전환**: 코어가 로케일을 바꾸면 이 템플릿의 `initTemplate()` 재등록 진입점이 호출되어
핸들러 맵을 다시 등록합니다(§docs/handlers.md "부트스트랩"). 사이드바 접힘 등 1회성 부팅
작업은 이 진입점에 섞지 않습니다 — 로케일 전환마다 중복 실행되면 안 되기 때문입니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 제공 컴포넌트 | 125개 | [제공 컴포넌트](docs/components.md#제공-컴포넌트) |
| 레이아웃 | 145개 | [레이아웃 목록](docs/layouts.md#레이아웃-목록) |
| 전용 핸들러 | 17개 | [템플릿 전용 핸들러](docs/handlers.md#템플릿-전용-핸들러) |
| 확장 오버라이드 | 0개 | [확장 오버라이드](docs/layouts.md#확장-오버라이드) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
이 템플릿은 훅을 발행/구독하지 않습니다 — 관리자 화면 확장은 훅이 아니라 **레이아웃 확장
오버라이드**(`extensions/{module-identifier}/*.json`, 지금은 0개)와 **컴포넌트 재사용**
(공통 컴포넌트를 그대로 쓰는 것) 두 경로로만 이뤄집니다. 다른 확장의 관리자 화면 UI 를
이 템플릿에서만 다르게 그리고 싶다면 첫 번째 경로를, 새 화면 유형(예: 새로운 카드 스타일)이
필요하면 `src/components/composite/` 에 컴포넌트를 추가하고 `components.json` 에 등록하는
쪽을 씁니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan template:update sirsoft-admin_basic --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` 동기화 + CHANGELOG 기재
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 필수 컴포넌트(35개) 의 Props 시그니처를 깨는 변경은 전체 번들 확장 관리자 화면에 영향 — 변경 전 `src/components/{basic,composite}/` 의 실사용처를 넓게 확인
- [ ] `_admin_base.json` 슬롯 구조(`content` 슬롯 등) 변경 시 그 슬롯에 의존하는 모든 화면(145개 레이아웃 대다수) 영향 검토
- [ ] AdminSidebar 의 `MenuItem`/`AdminSidebarProps` 인터페이스 확장 시 이 문서의 §docs/components.md "AdminSidebar 상세" 동기화
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 의 동반 의무 표를 따라 `editor-spec/` 블록을 함께 갱신 — 컴포넌트는 팔레트·역량·중첩 **넷 다** 손대야 편집기에서 온전히 동작하고, 하나만 빠지면 절반만 동작한다. 반영은 `php artisan template:update sirsoft-admin_basic --force` (편집기는 활성 디렉토리만 읽는다)

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| `AdminSidebar` 메뉴 아이콘을 `Icon name="home"` 식으로 렌더 | `I` 컴포넌트 + FontAwesome 클래스 문자열(`icon` 필드가 이미 `"fas fa-home"` 형태) | 메뉴 API 가 클래스 문자열을 내려주므로 `Icon`(IconName enum) 으로 받으면 렌더되지 않는다 |
| 필수 컴포넌트 목록 밖의 이 템플릿 전용 컴포넌트를 모듈 레이아웃에서 사용 | 필수 컴포넌트(config/template.php) 만 사용 | 다른 admin 템플릿으로 교체 시 그 화면만 깨진다 |
| 사이드바 접힘 상태를 레이아웃 `init_actions` 로 매번 복원 | 템플릿 부트스트랩(`src/index.ts`)에서 1회 복원 | `init_actions` 는 화면 진입마다 재실행되어 불필요한 반복 처리가 된다 |
| `_admin_base` 를 상속하는데 로그인 화면처럼 `initTheme`/메뉴 초기화를 다시 호출 | `_admin_base` 상속 화면은 이미 초기화된 전역 상태를 그대로 사용 | 중복 호출은 낭비이며, 두 초기화 지점의 결과가 어긋나면 화면 간 상태 불일치가 생긴다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 0개 | — |
| Vitest | 206개 | `vitest.config.ts` |
| Playwright | 9개 | `tests/Playwright` |
| 시나리오 매니페스트 | 2개 | `tests/scenarios` |

```bash
# Vitest (확장 디렉토리에서) (PowerShell)
cd templates/_bundled/sirsoft-admin_basic && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (확장 디렉토리에서) (Bash)
cd templates/_bundled/sirsoft-admin_basic && npm run test:e2e -- specs/<대상>.spec.ts

```

무필터 전체 실행은 금지되어 있습니다 — 변경 범위에 걸리는 대상만 지정해 실행합니다.
<!-- @generated:test-commands END -->

## 8. 문서 목차

<!-- @generated:docs-index START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 문서 | 내용 | 상태 |
|---|---|---|
| [docs/README.md](docs/README.md) | 문서 통합 목차와 실측 집계 | ✅ |
| [docs/architecture.md](docs/architecture.md) | 설계 의도·계층 지도·디렉토리 맵 | ✅ |
| [docs/components.md](docs/components.md) | 템플릿이 제공하는 컴포넌트 | ✅ |
| [docs/layouts.md](docs/layouts.md) | 레이아웃 목록과 라우트 매핑 | ✅ |
| [docs/handlers.md](docs/handlers.md) | 템플릿 전용 핸들러와 부트스트랩 | ✅ |
| [docs/editor-spec.md](docs/editor-spec.md) | 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->
