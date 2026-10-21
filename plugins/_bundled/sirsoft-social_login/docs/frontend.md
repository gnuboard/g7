# 소셜 로그인 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 7개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 7개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `_policy_tab` | `admin` | partial | - |
| `_provider_add` | `admin` | partial | - |
| `_provider_editor` | `admin` | partial | - |
| `_provider_impact` | `admin` | partial | - |
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
| `social_login_diagnostics` | `admin` | 화면 | `_admin_base` |
| `social_login_stats` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
관리자 화면 셋입니다. 설정 화면(`plugin_settings`)은 코어 「플러그인 관리 → 설정」 경로가 띄우고 탭 둘(`?tab=providers` 기본, `?tab=policy`)로 나뉩니다 — 제공자 탭은 카드마다 Redirect URI 와 웹훅 주소 복사, 편집 칸(비밀값은 마스크 그대로 두면 유지), 연결 테스트, 브라우저 테스트, 영향 보기, 위아래 순서, 일반 OIDC 추가와 삭제를 두고, 정책 탭은 사이트 전체 정책 15칸을 저장합니다. 켜진 제공자를 끄거나 자격증명을 바꾸면 저장 전에 카드 안 영향 패널로 확인을 받습니다(모달은 연 화면의 값을 열 때 값으로만 읽어 편집 중 값을 따라가지 못한다). 이 확인의 [계속 저장]은 지금 열린 편집 칸을 저장하므로, 다른 카드 편집을 열거나 편집 칸을 닫으면 패널도 닫힙니다. 진단(`social_login_diagnostics`)과 통계(`social_login_stats`)는 플러그인 라우트(`routes.json`, `settings.view`)로 열리고 관리자 메뉴 「소셜 로그인」 아래에 있습니다. 필터와 기간은 주소 쿼리에 담겨 새로고침 뒤에도 남습니다. 관리자 회원 화면에는 레이아웃 확장 셋(상세 탭, 목록 가입경로 필터, 목록 「소셜 연결」 열)을 붙입니다. 상세 탭의 연결 목록은 데스크톱과 태블릿에서 표, 휴대폰 폭(`responsive` 의 `mobile`)에서 줄마다 세로 카드로 그려 [강제 해제]가 가로 스크롤 없이 보입니다. 사용자 화면(로그인·가입 버튼, 가입 모달, 마이페이지 카드, 재확인)은 자기 레이아웃이 아니라 템플릿 화면에 주입하는 레이아웃 확장입니다(`extension-points.md` 「레이아웃 확장」).
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 4개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `startLink` | `sirsoft-social_login.startLink` |
| `startReauth` | `sirsoft-social_login.startReauth` |
| `copyToClipboard` | `sirsoft-social_login.copyToClipboard` |
| `openTestLogin` | `sirsoft-social_login.openTestLogin` |
<!-- @generated:handlers END -->

<!-- @intent START -->
로그인 회원의 왕복 시작 두 가지입니다. `startLink` 는 마이페이지 [연결], `startReauth` 는 대체 이메일 회원의 「{제공자}로 다시 확인」에서 부릅니다. 둘 다 코어 API 클라이언트(`G7Core.api`)로 시작 API 를 불러 인가 주소를 받고, 같은 출처이거나 https 인 주소로만 이동합니다. 로그인 토큰을 직접 읽지 않고 fetch 를 덮어쓰지 않습니다. 실패하면 서버 문구를 토스트로 보입니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftSocialLogin` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`resources/js/index.ts` 가 핸들러를 등록하고 버튼 컴포넌트를 내보냅니다. 관리자 설정 화면은 핸들러 둘을 더 씁니다 — `copyToClipboard`(Redirect URI 와 웹훅 주소 복사, 클립보드 API 가 없으면 선택 복사로 대신)와 `openTestLogin`(빈 창을 먼저 열어 팝업 차단을 피하고 `test-login` 응답의 인가 주소가 https 이거나 같은 출처일 때만 그 창을 보낸다, 실패하면 창을 닫는다).
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
`plugin.json` 의 assets 는 JS(`resources/js/index.ts` → `dist/js/plugin.iife.js`)와 CSS(`resources/css/buttons.css` → `dist/css/plugin.css`)입니다. 소스를 바꾸면 `php artisan plugin:build sirsoft-social_login --production` 으로 다시 굽습니다. 테스트 결과 화면과 모의 동의 화면은 서버가 그리는 단독 HTML 입니다.
<!-- @intent END -->

## 컴포넌트

<!-- @intent START -->
`ProviderButtons`(등록 이름 `sirsoft-social_login.ProviderButtons`, 원본 `resources/js/components/composite/ProviderButtons.tsx`) — 제공자 버튼 목록입니다.

| prop | 값 | 설명 |
| --- | --- | --- |
| `providers` | 공개 제공자 목록(`GET …/providers`) 또는 마이페이지 응답의 `available`·`accounts` | 비면 아무것도 그리지 않는다 |
| `intent` | `login` · `register` · `link` · `reauth` | 로그인·가입은 시작 주소 `<a href>`(새 탭 가능), 연결·재확인은 핸들러 `startLink`·`startReauth` |
| `redirect` | 같은 출처 경로 | 로그인·가입 뒤 돌아갈 곳. `/` 로 시작하지 않거나 `//` 이면 싣지 않는다 |
| `size` · `disabled` · `className` | `md`·`sm` / bool / 문자열 | 비활성이면 `href` 를 빼고 `aria-disabled` |
| `id` · `editorAttrs` | 문자열 / 객체 | 바깥 요소의 DOM id, 레이아웃 편집기가 편집 모드에서만 넣는 표식(바깥 요소에 그대로 펼친다) |

- 모양은 제공자의 `brand`(`kakao`·`naver`·`google`·`facebook`·`custom`)로 고릅니다. 모의 제공자도 원 제공자 brand 를 씁니다. 각 사 버튼 지침의 색(카카오 `#FEE500`, 네이버 `#03A94D`, 구글 흰 배경·`#747775` 테두리, 페이스북 `#1877F2`)과 공식 심볼 SVG 를 인라인으로 그리고, 커스텀(일반 OIDC)은 `button_style` 색과 이름 첫 글자 원을 씁니다.
- 다크는 사이트 다크(`html.dark`)만 따릅니다(구글 `#131314`·`#8E918F`·`#E3E3E3`). 운영체제 다크 설정은 보지 않습니다 — 사이트를 라이트로 둔 방문자에게 버튼 하나만 어둡게 나오지 않게.
- CSS 는 자체 접두 `.sl-*` 만 쓰고 템플릿 의미 색 클래스에 기대지 않습니다. 버튼마다 `aria-label`(「카카오 로그인」 등)과 `data-social-provider` 가 붙습니다.

`ProviderMark`(등록 이름 `sirsoft-social_login.ProviderMark`, 원본 `resources/js/components/basic/ProviderMark.tsx`) — 마이페이지 「연결된 소셜 계정」 줄 앞의 제공자 표시입니다. 버튼과 같은 공식 심볼을 버튼 색 원(카카오 `#FEE500`, 네이버 `#03A94D`, 구글 흰 배경·`#747775` 테두리 / 다크 `#131314`·`#8E918F`, 페이스북 `#1877F2`) 안에 그리고, 커스텀은 이름 첫 글자 원을 씁니다. props 는 `brand`(내장 4종 밖은 custom) · `label` · `className` · `id`. 이름은 옆 텍스트가 맡으므로 `aria-hidden` 이고 `data-social-provider-mark` 에 계열이 붙습니다. 편집기 팔레트에는 올리지 않습니다.
<!-- @intent END -->
