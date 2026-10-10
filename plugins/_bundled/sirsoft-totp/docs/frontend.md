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
않습니다. 이 화면은 플러그인 환경설정이지 별도 메뉴로 세울 사항이
아닙니다. 형제 13종이 모두 같은 규약을 씁니다.

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
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftTotp` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
엔트리는 `resources/js/index.ts` 이고, `plugin.json` 이 `assets.js` 로 그 빌드 산출물
`dist/js/plugin.iife.js` 를, `loading` 으로 `strategy: global`, `priority: 100` 을 선언합니다.
전역 로딩인 것은 등록 화면이 특정 메뉴가 아니라 「플러그인 관리 → 설정」에서 열리기 때문입니다.

엔트리가 부팅 때 하는 일은 셋입니다.

1. **QR 관측자** — `data-totp-qr` 속성을 가진 요소에 `qr.ts` 의 `renderQrSvg()` 로 QR SVG 를
   그리고, `MutationObserver` 로 DOM 변화를 지켜 레이아웃이 카드를 다시 그려도 QR 이 따라오게
   합니다. 속성값(otpauth URI)이 직전에 그린 값과 같으면 다시 그리지 않습니다.
2. **핸들러 등록** — `handlerMap` 의 핸들러를 `sirsoft-totp.{이름}` 으로 `ActionDispatcher` 에
   등록합니다. 디스패처가 아직 없으면 100ms 간격으로 최대 50회 다시 시도하고, 끝내 없으면 경고만
   남깁니다. 디스패처의 `registerHandler` 는 내부에서 `this` 를 쓰므로 변수로 떼어 낸 뒤
   `register.call(dispatcher, …)` 로 수신자를 붙여 부릅니다. 수신자 없이 부르면 예외가 나는데,
   예전에는 그 예외가 부팅 함수를 중단시켜 QR 관측자까지 설치되지 않았습니다. 지금은 관측자를
   먼저 걸고 등록을 `try` 로 감쌉니다.
3. **재등록 전역** — `window.__SirsoftTotp = { identifier, initPlugin }` 을 노출합니다. 로케일을
   바꾸면 코어가 `ActionDispatcher` 를 새로 만든 뒤 `window.__*` 전역의 `initPlugin()` 을
   부릅니다(`TemplateApp.reinitializePluginHandlers()`).

`initPlugin()` 은 **핸들러만 다시 등록합니다.** QR 관측자는 디스패처와 무관하게 부팅 때 건 것이
계속 살아 있으므로 다시 걸지 않습니다 — 다시 걸면 같은 DOM 변화를 관측자 둘이 처리합니다.

이 전역이 없던 동안에는 언어를 바꾼 뒤 `sirsoft-totp.copyToClipboard` 가 새 디스패처에 없어,
백업 코드 화면의 「전체 복사」를 눌러도 코드가 복사되지 않았습니다. 코어는 `__` 로
시작하는 전역에서 `initPlugin` 이라는 이름의 함수를 찾고, 문서 생성기는
`(window as …).__{Name} = …` 대입 모양으로 전역을 찾으므로 이 형태를 바꾸지 않습니다.
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
프론트 산출물은 `dist/js/plugin.iife.js` 하나이고 커밋 대상입니다. 확장 아이콘은
`assets/icon.svg` 입니다.

`resources/js/` 를 고쳤다면 `php artisan plugin:build --production` 으로 다시 만들고
(`sourceMappingURL` 잔존 금지) `php artisan plugin:update sirsoft-totp --force` 로 반영합니다.
브라우저가 받는 것은 커밋된 `dist/` 이므로, 재빌드 없이 커밋하면 소스 수정이 화면에 닿지
않습니다.

QR 인코딩은 번들에 넣은 `qrcode-generator`(MIT, 의존성 없음)가 클라이언트에서 합니다. 서버는
`Totp::otpauthUri()` 가 만든 URI 문자열만 내려주고, 화면은 그 값을 `data-totp-qr` 속성에 실어
엔트리가 그 자리에서 SVG 로 그립니다. QR 을 외부 서비스로 그리게 바꾸는 구현은 평문 시크릿을
제3자에게 넘기는 것과 같습니다.
<!-- @intent END -->
