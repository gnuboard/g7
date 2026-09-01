# Hello Admin Template — 에이전트 가이드

> 이 문서는 이 템플릿을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 템플릿 (gnuboard7-hello_admin_template, type=admin) — 학습용 최소 Admin 템플릿. Basic 컴포넌트 8 + 베이스 1 + 대시보드 1 + **오류 6종**이 전부. `hidden: true`
2. 확장 방식: 훅 없음 — 확장점은 화면 구조다. 제공 컴포넌트 목록이 곧 계약이며, 모듈 조각은 그 안의 컴포넌트만 쓸 수 있다
3. 건드리면 안 되는 것: 오류 레이아웃 6종 축소, 401 에서 직접 리다이렉트, 컴포넌트 등록 재시도 제거, 샘플에 실제 관리 화면 추가
4. 작업 위치: `templates/_bundled/gnuboard7-hello_admin_template` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan template:update gnuboard7-hello_admin_template --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
**학습용 최소 Admin 템플릿**입니다. 관리자 템플릿이 성립하기 위한 **최소 구성**만 담아
"템플릿은 이 정도만 있으면 동작한다" 를 보이는 것이 목적입니다.

담긴 것은 넷뿐입니다 — Basic 컴포넌트 8개(`Div` · `Button` · `H1` · `H2` · `H3` · `A` ·
`Span` · `Img`), 베이스 레이아웃 `_admin_base`, 대시보드 화면 하나, 그리고 **오류 레이아웃
6종**(401 · 403 · 404 · 500 · 503 · maintenance).

**오류 6종이 필수인 것이 이 샘플의 핵심 학습 포인트**입니다. 코어는 오류 상황에서 활성 템플릿의
해당 레이아웃을 부르는데, 없으면 그 오류가 화면에 나타나지 못합니다 — 사용자에게는 백지가
됩니다. 화면이 하나뿐인 템플릿에도 이 6종은 있어야 합니다.

`manifest.hidden = true` 라 관리자 UI 의 템플릿 목록에 나타나지 않습니다. artisan CLI 로는
정상 설치·활성화됩니다.

**의도적으로 하지 않는 것**: 실제 관리 화면·composite/layout 컴포넌트·핸들러·다국어 확장·
SEO 설정. `sirsoft-admin_basic` 이 컴포넌트 125개와 화면 145개를 갖는 것과 대비해 보면, 무엇이
**필수**이고 무엇이 그 템플릿의 선택인지가 드러납니다.
<!-- @intent END -->

## 2. 디렉토리 지도

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `template.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json 동기화 |
| `routes.json` | 라우트 → 레이아웃 매핑 | `php artisan template:update gnuboard7-hello_admin_template --force` |
| `layouts/` | 레이아웃 JSON | `php artisan template:update gnuboard7-hello_admin_template --force` (빌드 불필요) |
| `src/components/` | React 컴포넌트 | `php artisan template:build` → `php artisan template:update gnuboard7-hello_admin_template --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan template:update gnuboard7-hello_admin_template --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
서버 코드가 없으므로 흐름은 **부트스트랩 → 컴포넌트 등록 → 라우트 → 레이아웃** 입니다.

**부트스트랩**: `src/index.ts` 가 모듈 로드 시 `initTemplate()` 을 스스로 실행합니다. 그 안에서
코어의 `ComponentRegistry` 를 찾아 Basic 8개를 등록하는데, **레지스트리가 아직 준비되지 않았을
수 있으므로 100ms 간격으로 최대 50회 재시도**합니다(`window.load` 이후 시작). 이 재시도가 없으면
로드 순서에 따라 컴포넌트가 등록되지 않고, 그러면 레이아웃이 참조하는 이름을 찾지 못해 화면이
비게 됩니다.

**화면 렌더**: `routes.json` 이 `*/admin` 을 `admin_dashboard` 에 대응 → 그 레이아웃이
`_admin_base` 를 상속해 공통 뼈대를 얻고 콘텐츠를 채웁니다.

**오류 렌더**: 코어가 401/403/404/500/503/maintenance 상황을 만나면 활성 템플릿의 해당
레이아웃을 부릅니다. 여섯 모두 `_admin_base` 를 상속하므로 오류 화면에서도 공통 뼈대가
유지됩니다.

`401` 레이아웃에서 **로그인 리다이렉트를 직접 구현하지 않습니다** — 코어
`TemplateApp.showRouteError` 가드가 처리하므로, 여기서 다시 이동시키면 이중 리다이렉트가
됩니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 제공 컴포넌트 | 8개 | [제공 컴포넌트](docs/components.md#제공-컴포넌트) |
| 레이아웃 | 8개 | [레이아웃 목록](docs/layouts.md#레이아웃-목록) |
| 전용 핸들러 | 0개 | [템플릿 전용 핸들러](docs/handlers.md#템플릿-전용-핸들러) |
| 확장 오버라이드 | 0개 | [확장 오버라이드](docs/layouts.md#확장-오버라이드) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
템플릿의 확장점은 훅이 아니라 **화면 구조**입니다. 이 샘플은 그 구조의 최소 형태를 보입니다.

| 확장점 | 이 샘플에서 |
|---|---|
| 제공 컴포넌트 | Basic 8개. 다른 확장이 조각을 끼워 넣을 때 **여기 있는 것만** 쓸 수 있습니다 |
| 레이아웃 | 8개(베이스 1 + 화면 1 + 오류 6). 모듈이 `layout_extensions` 로 조각을 끼울 자리는 대시보드뿐입니다 |
| 확장 오버라이드 | 없음. `extensions/{확장}/` 에 같은 이름의 조각을 두면 그 확장이 제공한 원본을 대체합니다 |
| 전용 핸들러 | 없음 |

**컴포넌트 목록이 곧 계약**이라는 점이 중요합니다. 모듈이 관리자 화면 조각을 만들 때 `DataGrid`
같은 컴포넌트를 쓰면, 그것을 제공하지 않는 이 템플릿에서는 **그 조각이 렌더되지 않습니다.**
모듈이 어느 템플릿에서든 동작하려면 코어가 정한 **필수 컴포넌트 집합**만 써야 하며, 그 목록의
SSoT 는 코어 `config/template.php` 의 `required_admin_components` 입니다.

이 샘플이 Basic 8개만 갖는 것은 그래서 실험이기도 합니다 — 이 템플릿으로 바꿨을 때 깨지는
화면이 있다면, 그 화면이 템플릿 고유 컴포넌트에 의존하고 있다는 뜻입니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan template:update gnuboard7-hello_admin_template --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` 동기화 + CHANGELOG 기재
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 오류 레이아웃 6종(401·403·404·500·503·maintenance)이 모두 있는지 확인 — 하나라도 없으면 그 상황에서 백지가 된다
- [ ] 컴포넌트를 추가·삭제했다면 소스 · `template.json` 레지스트리 · `components.json` 을 함께 갱신
- [ ] `manifest.hidden = true` 를 유지 (복제본에서만 제거)
- [ ] TSX 를 고쳤다면 `template:build --production` 후 `dist/` 동반 커밋 (`sourceMappingURL` 잔존 금지)
- [ ] `docs/extension/sample-extensions.md` 의 계층 표와 어긋나지 않는지 확인
- [ ] 레이아웃 렌더링 테스트(`__tests__/layouts/*.test.tsx`)는 이 템플릿 디렉토리에 둔다 — 코어 디렉토리에 두지 않는다
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 를 확인 — 이 확장은 편집기 스펙이 없어도 되는 상태(공용 ID 만 사용)다. 이 확장만 쓰는 `data_source` 를 새로 붙이는 순간 `editor-spec.json` 신설이 필요해진다

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 오류 레이아웃 6종 중 일부를 빼기 | 401 · 403 · 404 · 500 · 503 · maintenance 전부 유지 | 코어가 그 상황에서 활성 템플릿의 레이아웃을 부른다 — 없으면 사용자에게 백지가 된다 |
| `401` 레이아웃에서 로그인으로 직접 리다이렉트 | 코어 `TemplateApp.showRouteError` 가드에 위임 | 이중 리다이렉트가 되고, 돌아올 위치를 코어가 이미 관리한다 |
| 컴포넌트 등록에 재시도 없이 한 번만 시도 | `initTemplate()` 의 재시도 루프 유지 | 로드 순서에 따라 레지스트리가 아직 없을 수 있다 — 등록에 실패하면 레이아웃이 이름을 못 찾아 화면이 빈다 |
| `extends` 없는 독립 레이아웃에서 `toast`·`openModal` 사용 | `_admin_base` 를 상속하거나 호스트 컴포넌트를 직접 마운트 | 호스트가 없으면 핸들러는 성공으로 기록되는데 화면에는 아무것도 나타나지 않는다 |
| 이 샘플에 실제 관리 화면을 더해 "쓸모 있게" 만들기 | 짧게 유지하고, 필요한 화면은 별도 템플릿으로 | 샘플의 가치는 "최소 구성이 무엇인가" 를 보이는 것이다 |
| `manifest.hidden` 을 제거 | 그대로 둔다 (복제본에서만 제거) | 학습용 템플릿이 운영 사이트의 템플릿 목록에 섞인다 |
| 컴포넌트를 추가하면서 `template.json` 의 레지스트리를 갱신하지 않기 | 소스·레지스트리·`components.json` 을 함께 | 레이아웃이 참조하는 이름을 찾지 못해 그 컴포넌트만 조용히 렌더되지 않는다 |
| TSX 를 고치고 `dist/` 재빌드 없이 커밋 | `template:build --production` 후 `dist/` 동반 커밋 | 브라우저가 받는 것은 커밋된 `dist/` 다 — 소스 수정이 사문화된다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 0개 | — |
| Vitest | 2개 | `vitest.config.ts` |
| Playwright | 0개 | — |
| 시나리오 매니페스트 | 0개 | — |

```bash
# Vitest (확장 디렉토리에서) (PowerShell)
cd templates/_bundled/gnuboard7-hello_admin_template && powershell -Command "npm run test:run -- <대상>"

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
