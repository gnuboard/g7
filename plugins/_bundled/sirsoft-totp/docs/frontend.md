# 인증 앱 2단계 인증 (TOTP) — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 1개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 1개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
관리자 등록 화면은 `resources/layouts/admin/plugin_settings.g7.html`(+ 짝 `.json`)입니다.

이 파일명이 계약입니다 — 코어 `AbstractPlugin::getSettingsLayout()` 이
`resources/layouts/admin/plugin_settings` 를 찾아 「플러그인 관리 → 설정」
(`/admin/plugins/sirsoft-totp/settings`)으로 띄우므로, 전용 메뉴도 `routes.json` 도 두지
않습니다(PO 확정 2026-09-18 — 이 화면은 플러그인 환경설정이지 별도 메뉴로 세울 사항이
아니다). 형제 13종이 모두 같은 규약을 씁니다.

화면은 카드 4장이 상호배타 `data-if` 로 전환하고(등록됨 / 시작 전 / 진행 / 백업 코드),
`src/routes/api.php` 의 엔드포인트를 `data_source` 로 물어 상태를 가릅니다.

화면을 더할 때 지켜야 할 것은 레이아웃 규약보다 API 계약입니다 — 평문 시크릿과 백업
코드는 발급·확정·재발급 응답에 **한 번만** 실리고 조회로는 다시 내려오지 않으므로, 그
1회 노출을 책임지는 것은 화면입니다. 사용자가 시크릿을 앱에 넣고 백업 코드를 옮겨 적기
전에 화면을 벗어나면 복구 수단은 재발급뿐입니다.

`resources/extensions/` 도 비어 있습니다 — 다른 확장의 레이아웃에 조각을 주입하지
않습니다. 관리자 템플릿을 고치지 않으므로 플러그인을 끄면 메뉴와 화면이 함께 사라집니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 1개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `copyToClipboard` | `sirsoft-totp.copyToClipboard` |
<!-- @generated:handlers END -->

<!-- @intent START -->
핸들러는 `copyToClipboard` 하나입니다 — 백업 코드 화면의 「전체 복사」가 씁니다. 엔진에는
클립보드 액션이 없고(`ActionDispatcher` 의 액션 목록에 없다) 클립보드는 브라우저 API 라
레이아웃 문법으로 표현할 수 없어, 형제 플러그인(`sirsoft-pay_nhnkcp`)과 같은 방식으로
플러그인이 named action 을 내줍니다. 보안 컨텍스트(HTTPS·localhost)가 아니면
`navigator.clipboard` 가 막히므로 숨긴 textarea + `execCommand('copy')` 로 내려갑니다 —
사내망을 HTTP 로 쓰는 설치본이 있기 때문입니다.

이 하나를 둔 이유는 백업 코드가 **그 화면에서 한 번만** 보이기 때문입니다. 옮겨 적다
놓치면 휴대폰을 잃었을 때 로그인할 길이 사라지므로, 눈으로 받아 적는 것 말고 한 번에
가져갈 수단이 「보관했습니다」 앞에 있어야 합니다.

로그인 2단계 인증 화면은 코어 소유이므로 이 플러그인이 그 화면의 동작을 핸들러로 바꾸지
않습니다. 대신 `TotpIdentityProvider::requestChallenge()` 가 challenge 의
`publicPayload` 에 `code_length: 6` 과 `delivery: 'none'` 을 실어, 코어 화면이 「인증번호
다시 받기」를 숨기고 안내 문구를 앱 기준으로 바꿀 근거를 **데이터로** 넘깁니다.
`getRenderHint()` 가 `text_code` 를 돌려주는 것도 같은 맥락이라, 코어 화면은 메일 수단과
같은 코드 입력 UI 를 그대로 씁니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
전역 프론트 엔트리포인트가 없습니다.

`plugin.json` 의 `assets.js` 는 `entry: resources/js/index.ts` ·
`output: dist/js/plugin.iife.js` 를, `loading` 은 `strategy: global` · `priority: 100` 을
선언하고 있지만 **해당 소스 파일이 아직 없습니다** — `resources/js/` 는 빈 디렉토리입니다.
지금 상태로 `php artisan plugin:build` 를 돌리면 엔트리를 찾지 못합니다.

전역 로딩을 선언해 둔 것은 등록 화면이 관리자 영역 어디서든 열릴 수 있기 때문이지만,
엔트리가 생기기 전까지 이 선언은 효력이 없습니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
프론트 에셋이 없습니다. `dist/` 가 없고 `assets/` 는 비어 있어 확장
아이콘(`assets/icon.svg`)도 아직 없습니다.

에셋을 더할 때의 제약은 둘입니다. 빌드 산출물은 커밋 대상이므로
`php artisan plugin:build --production` 으로 만들고(`sourceMappingURL` 잔존 금지), 반영은
`php artisan plugin:update sirsoft-totp --force` 로 합니다. 그리고 QR 코드 라이브러리를
들일 경우 서버는 `Totp::otpauthUri()` 가 만든 URI 문자열만 내려주므로 QR 렌더는 전적으로
클라이언트에서 이뤄집니다 — 시크릿이 이미지 생성을 위해 제3의 서비스로 나가는 구현은
평문 시크릿을 외부에 넘기는 것과 같습니다.
<!-- @intent END -->
