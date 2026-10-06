# GDPR (일반 데이터 보호 규정) — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 10개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 10개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `gdpr_consent_log` | `admin` | 화면 | `_admin_base` |
| `gdpr_notice_log` | `admin` | 화면 | `_admin_base` |
| `_policy_version_snapshot_modal` | `admin` | partial | - |
| `_modal_notification_definition_reset` | `admin` | partial | - |
| `_modal_notification_template_edit` | `admin` | partial | - |
| `_modal_notification_template_preview` | `admin` | partial | - |
| `_modal_privacy_run` | `admin` | partial | - |
| `_modal_privacy_targets` | `admin` | partial | - |
| `_policy_version_publish_modal` | `admin` | partial | - |
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
관리자 화면 4개뿐이고 **쿠키 배너·마이페이지 동의 탭은 이 표에 없습니다** — 그 둘은
"레이아웃"이 아니라 "레이아웃 확장 조각"(`resources/extensions/cookie_banner.g7.html`,
`mypage_privacy_tab.g7.html`, §extension-points.md)으로 다른 확장/템플릿 레이아웃에 주입되는
형태라 별도 수집 축에 잡힙니다. 방문자가 실제로 보는 UI를 찾으려면 이 표가 아니라
확장점 문서를 봐야 합니다.

관리자 레이아웃의 모양은 관리자 템플릿(`sirsoft-admin_basic`)의 스타일 자산 클래스(`row-stack`·`kv-list-2`·`kv-row` 등)에
기댑니다. 템플릿의 Tailwind 는 **템플릿 자신의 레이아웃만** 훑어 CSS 를 만들므로, 플러그인 레이아웃에만 쓰인 임의값
유틸리티(`grid-cols-[…]` 등)는 CSS 가 생기지 않을 수 있습니다 — 「라벨 값」 목록처럼 모양을 맞춰야 하는 자리는 자산
클래스를 씁니다(정책 버전 스냅샷 창의 메타 목록이 `kv-list-2`).
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
이 플러그인은 액션 핸들러를 등록하지 않습니다(`plugin.json` 의 `assets.handlers` 가 `false`). 예전의
`sirsoft-gdpr.syncConsent` 는 #639 에서 없어졌고, 배너·마이페이지 레이아웃은 동의 저장 성공 뒤 코어 내장
핸들러 `refreshConsent` 를 부릅니다. 흐름은 이렇습니다.

| 단계 | 누가 | 무엇을 |
|---|---|---|
| 1 | 레이아웃 | 동의 저장 API 성공 → `<action handler="refreshConsent"/>` |
| 2 | 코어 동의 런타임 | `GET /api/consent` 재조회 → `_global.consent` 갱신 → 코어 이벤트 `consent:changed` |
| 3 | 이 플러그인 (`index.ts`) | `G7Core.consent.subscribe()` 로 받은 스냅샷을 차단 엔진·preblocker·저장소 인터셉터에 반영 |

로그인·로그아웃(SPA 이동)도 코어 런타임이 2단계부터 다시 돌리므로, 게스트 동의가 회원 화면에 남지 않습니다.
이 플러그인은 자체 동의 조회도, `gdpr:consent-changed` 같은 자체 window 이벤트도 두지 않습니다 — 동의를 읽는 곳이
둘이면 로그인 직후처럼 둘 중 하나만 갱신되는 순간이 생깁니다.

소비 규칙: 스냅샷 `status` 가 `ready`·`none` 일 때만 엔진을 바꾸고, `unknown`(부팅 중·재조회 중)이면 현재 상태를
유지합니다. 기능 저장소 정리(EDPB §117)는 `ready` 이면서 기능 미동의일 때만 실행합니다 — 로그인 직후의 일시적
`unknown` 으로 동의한 회원의 저장소를 지우지 않기 위해서입니다. 코어 동의 런타임이 없는 화면(관리자)에서는 처음의
보수적 차단 상태를 유지합니다.

그 저장소 게이팅의 판정 목록(필수 허용목록)은 **인라인 페이로드**로 옵니다. 인터셉터는
`fetchPublicSettings()` 앞에서 서야 동의 전 첫 저장을 막을 수 있으므로 응답을 기다릴 수 없고,
그래서 `window.G7Config.plugins['sirsoft-gdpr']` 의 `necessary_storage_allowlist` ·
`necessary_storage_locked` · `necessary_storage_provided`(활성 확장이 계약 훅으로 제공한 키 — 서버가
요청마다 모은 계산값)를 동기로 읽어 합칩니다(`readInlineNecessaryAllowlist()` →
`resolveInlineAllowlist()`). 이 경로가
비면 인터셉터는 잠금 집합만으로 서고, 운영자가 등재한 항목이 그 창에서 파기됩니다 — 예외도
로그도 남지 않으므로 새 설정 키를 노출할 때는 `config/settings/defaults.json` 의
`frontend_schema` 에 `expose: true` 를 반드시 함께 넣어야 합니다.

판정 함수는 `plugins/_bundled/sirsoft-gdpr/resources/js/necessaryAllowlist.ts` 한 곳에 있고 인터셉터 둘과 정리기가 그것을
공유합니다. 소비자마다 따로 해석하면 저장소 카드는 와일드카드가 되고 쿠키 카드는 정확 일치만
되는 식으로 갈라집니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftGdpr` |
| 재등록 진입점 | `initPlugin()` |

이 엔트리는 액션 핸들러를 등록하지 않으므로 재등록 계약 대상이 아닙니다. 전역 객체는 외부에서 확장 존재를 확인하는 용도로만 남습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`initPlugin()` 은 빈 함수입니다 — 재등록할 핸들러가 없기 때문입니다. 전역
`window.__SirsoftGdpr` 를 지우지 않은 것은 외부에서 이 이름으로 플러그인 존재를 확인하던 코드를 깨지 않기
위해서입니다. 동의 구독은 코어 `componentEvent` 에 걸려 있어 로케일 재초기화에도 유지됩니다. 자동 차단 스크립트의
부팅(도메인 카탈로그 로드, DOM 스캔 시작)을 여기 넣으면 로케일 전환마다 중복 실행되므로 넣지 않습니다 — 그 부팅은
`index.ts` 가 페이지 최초 로드 시 1회만 수행합니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/css/plugin.css` | 빌드 산출물 (커밋 대상) |
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":0,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
`priority: 0`(다른 확장보다 먼저 로드)인 이유는 자동 차단이 **다른 확장의 추적 스크립트가
실행되기 전에** 걸려 있어야 하기 때문입니다 — 이 플러그인이 늦게 로드되면 동의 없이 이미
로드된 스크립트를 사후에 막을 방법이 없습니다. `strategy: "global"` 도 같은 이유로, 특정
페이지에서만 지연 로드하면 그 페이지에서는 동의 전 차단이 통째로 빠집니다.
<!-- @intent END -->
