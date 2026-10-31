# SSO 연동 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 7개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 7개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `_idp_edit` | `admin` | partial | - |
| `_idp_list` | `admin` | partial | - |
| `_policy_tab` | `admin` | partial | - |
| `_retention_tab` | `admin` | partial | - |
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
| `sso_accounts` | `admin` | 화면 | `_admin_base` |
| `sso_logs` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
관리자 화면 셋입니다. 설정 화면(`plugin_settings`)은 코어 「플러그인 관리 → 설정」 경로가 띄우고 탭 셋(`?tab=idps` 기본, `?tab=policy`, `?tab=retention` — 「IdP」, 「정책」, 「보존」)으로 나뉩니다. 탭 내용은 partial 넷(`_idp_list`, `_idp_edit`, `_policy_tab`, `_retention_tab`)으로 나뉩니다. IdP 편집은 같은 화면의 보기 전환이고 `?idp={id}` 쿼리로 새로고침 뒤에도 남습니다. 편집 칸은 드라이버 `configSchema()` 가 준 정의로 그리고, `settings.update` 가 없으면 읽기 전용 안내 띠가 뜹니다. 화면 데이터는 모두 플러그인 API 로 읽고 쓰며 코어 설정 「저장」 단추를 쓰지 않습니다.

「회원 연결」(`sso_accounts`)과 「이력」(`sso_logs`)은 플러그인 라우트(`routes.json`)로 열리고 관리자 메뉴 「SSO 연동」 아래에 있습니다. 로그인 화면 버튼과 모달, 관리자 회원 화면, 마이페이지 카드는 자기 레이아웃이 아니라 레이아웃 확장입니다([extension-points.md](extension-points.md#레이아웃-확장)).
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
위 표가 비어 있는 것은 생성기가 묶음 객체를 펼쳐 등록한 핸들러를 세지 못하기 때문입니다. 실제로는 다섯 개를 `sirsoft-sso.{이름}` 으로 등록합니다(`resources/js/handlers/index.ts` 가 영역별 묶음 `flowHandlers`, `policyHandlers`, `adminHandlers` 를 합친다 — `policyHandlers` 는 비어 있다).

| 핸들러 | 영역 | 하는 일 |
|---|---|---|
| `startLogin` | flow | 로그인 버튼. `params.url` 이 이 플러그인의 같은 출처 시작 경로(`/plugins/sirsoft-sso/login/`)일 때만 전체 페이지 이동. 엔진 `navigate` 는 같은 출처 화면 이동이라 서버 302 라우트를 열지 못한다 |
| `startWithTicket` | flow | 마이페이지 [연결하기]. 코어 API 클라이언트로 `params.endpoint`(플러그인 API)를 불러 받은 `start_url` 로 이동, 실패하면 서버 문구를 토스트로 |
| `copyToClipboard` | admin | 「IdP 콘솔에 넣을 값」, SCIM 주소와 토큰 복사 |
| `readCsvFile` | admin | CSV 가져오기 파일 읽기(최대 1MB) |
| `downloadCsvResult` | admin | CSV 가져오기 결과 내려받기 |

로그인 토큰을 직접 읽지 않고, 외부 주소로 이동하지 않습니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftSso` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`initPlugin()` 은 핸들러 재등록만 합니다. 처음 불릴 때 액션 디스패처가 아직 없으면 100ms 간격으로 최대 50번 다시 시도합니다. 컴포넌트를 내보내지 않으므로 `components.json` 의 컴포넌트 목록은 비어 있습니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/css/plugin.css` | 빌드 산출물 (커밋 대상) |
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
JS(`resources/js/index.ts` → `dist/js/plugin.iife.js`)와 CSS(`resources/css/main.css` → `dist/css/plugin.css`, 앞글자 `sso` — 레이아웃용 접두 유틸리티 `sso:*` 와 템플릿 의미 클래스 사본 `sso-*`)입니다. 화면은 템플릿 CSS 에 기대지 않고, 다크 모드는 템플릿이 선언한 다크 방식에 따라 코어가 다는 `<html data-g7-color-scheme="dark">` 표식을 따릅니다. 소스를 바꾸면 `php artisan plugin:build sirsoft-sso --production` 으로 다시 굽습니다. 브라우저 로그인 테스트 결과, SAML 자동 제출 폼, 모의 IdP 화면은 서버가 그리는 단독 HTML 입니다.
<!-- @intent END -->
