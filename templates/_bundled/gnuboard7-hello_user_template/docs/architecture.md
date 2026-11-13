# Hello 사용자 템플릿 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
목표가 둘입니다 — "User 템플릿의 **최소 구성**은 무엇인가" 와 "모듈의 데이터를 화면에 **어떻게
연결하는가**".

앞의 답은 Admin 샘플과 같습니다: Basic 컴포넌트 8개 · 베이스 레이아웃 · 화면 하나 ·
**오류 레이아웃 6종**. 오류 6종이 선택이 아닌 이유도 같습니다 — 코어가 그 상황에서 활성
템플릿의 레이아웃을 부르는데 없으면 방문자에게 백지가 됩니다.

뒤의 답이 이 샘플만의 것입니다. 홈 화면이 `data_sources` 로 학습용 모듈의 메모 API 를 호출해
목록을 그립니다 — **모듈이 데이터를, 템플릿이 화면을** 담당하는 경계를 가장 짧게 보여주는
예시이며, 실제 게시판·이커머스 모듈도 방문자 화면을 갖지 않고 같은 방식으로 템플릿에
맡깁니다.

**의도적으로 하지 않는 것**: 실제 사이트 화면 · composite/layout 컴포넌트 · 액션 핸들러 ·
SEO 설정 · 확장 오버라이드. `sirsoft-basic` 과 나란히 보면 무엇이 필수이고 무엇이 그 템플릿의
선택인지가 드러납니다.

`manifest.hidden = true` 는 학습용이 운영 사이트의 템플릿 목록에 섞이지 않게 하면서도 CLI 로는
실제로 설치·동작하게 하는 장치입니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
template.json          manifest — type: user · features 플래그 · 컴포넌트 레지스트리
routes.json            `/` → home (auth_required: false)
     │
layouts/_user_base.g7.html      방문자 공통 뼈대 (헤더 + 콘텐츠 + 푸터)
     │  extends
     ├─ layouts/home.g7.html          data_sources: 학습용 모듈 메모 API
     └─ layouts/errors/{401,403,404,500,503,maintenance}.json   ← 6종 필수
     │
src/components/basic/  Div · Button · H1 · H2 · H3 · A · Span · Img
     │
src/index.ts           Basic 8개 + 대체본 1개 + templateMetadata export 만 (등록은 코어가 components.json 기준으로)
     │
dist/                  커밋되는 빌드 산출물
__tests__/layouts/     createLayoutTest() + API 모킹 기반 렌더링 테스트
```

**서버 코드가 없습니다.** 데이터는 전부 모듈·코어의 공개 API 에서 오며, 그래서 템플릿을 갈아
끼워도 데이터는 그대로입니다. 반대로 이 템플릿만으로는 홈 화면이 비어 있습니다 — manifest 가
학습용 모듈을 의존으로 선언하는 이유입니다.

> **템플릿은 컴포넌트를 스스로 등록하지 않습니다.** 코어 `ComponentRegistry` 가
> `components.json` 을 읽고, 번들이 내보낸 전역 `window.Gnuboard7HelloUserTemplate` 에서 선언된
> 이름의 구현체를 찾아 등록합니다. 코어는 엔진과 템플릿 번들이 모두 적재된 뒤에 초기화하므로
> 템플릿 쪽에서 적재 순서를 기다릴 일이 없습니다. 대신 export 이름이 `components.json` 의
> `name`(대체본은 `overrides[].exportName`)과 어긋나면 그 컴포넌트는 등록되지 않고, 레이아웃이
> 그 이름을 참조하는 자리가 조용히 빕니다. Admin 샘플도 같은 방식입니다.

레이아웃 렌더링 테스트는 **이 템플릿 디렉토리에 둡니다**(`__tests__/layouts/`). API 응답을
모킹해 데이터소스 연동까지 검증하는 것이 이 샘플 테스트의 학습 포인트입니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `template.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json 동기화 |
| `routes.json` | 라우트 → 레이아웃 매핑 | `php artisan template:update gnuboard7-hello_user_template --force` |
| `layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan template:update gnuboard7-hello_user_template --force` (빌드 불필요) |
| `src/components/` | React 컴포넌트 | `php artisan template:build` → `php artisan template:update gnuboard7-hello_user_template --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan template:update gnuboard7-hello_user_template --force` |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan template:update gnuboard7-hello_user_template --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan template:update gnuboard7-hello_user_template --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 설정 예제 (템플릿 환경설정, 코어 7.2.0)

이 템플릿은 템플릿 환경설정의 최소 예제(브랜딩 섹션의 파비콘 1칸)를 담고 있습니다.

| 파일 | 역할 |
| --- | --- |
| `config/settings/defaults.json` | 섹션(`_meta.categories: ["branding"]`)·기본값(`branding.favicon: []`)·칸 선언(표준 키 `branding.favicon` — attachments, 1개, 파비콘 7형식)·화면 노출(`branding.favicon_url`) |
| `settings/template_settings.g7.html` | 설정 화면 레이아웃 — `layouts/` 밖에 둔다. 코어가 켜진 관리자 템플릿마다 `{id}.template_settings` 로 교차 등록한다. 받기·저장·되돌리기·주소 바꾸기를 맡고 화면은 부품 하나가 그린다 |
| `src/settings/*` · `src/styles/settings.css` · `vite.settings.config.ts` | 화면 부품 묶음 — `dist/settings/settings.iife.js`·`settings.css` 로 구워 코어가 관리자 화면에 싣는다. 어느 관리자 템플릿에서나 뜨도록 관리자 템플릿 부품을 쓰지 않고, CSS 는 뿌리 `.hello-settings` 안으로 좁힌다 |
| `components.settings.json` | 부품 매니페스트 — 레이아웃 검사가 `{id}.TemplateSettingsScreen` 을 알고, 관리자 템플릿이 `overrides` 로 덮어쓸 수 있다. `lang` 은 관리자 화면에 실을 언어 묶음 |
| `lang/{ko,en}.json` 의 `settings.*` | 섹션·칸 라벨·안내 (`$t:settings.sections.branding.label`·`$t:settings.favicon.label`) |

여기 정한 파비콘은 이 템플릿이 켜져 있을 때 사용자 화면 탭 아이콘으로 쓰이고, 비우면 환경설정 일반 탭의 파비콘(없으면 기본 아이콘)이 쓰입니다. 형식·확장 방법은 코어 문서 `docs/extension/template-settings.md` 를 봅니다.

## 첫 화면에 미리 싣는 템플릿 설정 (expose)

`template.json` 최상위 `expose` 에 `scroll_restoration`·`errorHandling`·`error_config` 를 선언한다. 서버가 그 키의 값만 화면 HTML (`G7Config.templateConfig`)에 함께 실어 엔진이 앱 시작 때 템플릿 설정 API 를 부르지 않는다(코어 engine-v1.95.0 부터). template.json 에 없는 키는 `null` 로 실린다. 엔진이 읽는 키를 새로 template.json 에 더하면 `expose` 에도 더한다 — 빠지면 설치·업데이트 결과에 경고가 나오고 첫 화면에 API 왕복이 1회 남는다. 형식은 코어 문서 「템플릿 기초 — 첫 화면에 미리 싣는 키」.

예시 템플릿이라 `scroll_restoration` 이 없지만 선언해 둔다 — `null` 로 실려 API 를 부르지 않는다.
