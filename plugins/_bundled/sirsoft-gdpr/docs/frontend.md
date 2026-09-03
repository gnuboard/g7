# GDPR (일반 데이터 보호 규정) — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 4개 (루트: `resources/layouts`).

| 그룹 | 개수 |
|---|---|
| `admin` | 4개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `gdpr_consent_log` | `admin` | 화면 | `_admin_base` |
| `_policy_version_snapshot_modal` | `admin` | partial | - |
| `_policy_version_publish_modal` | `admin` | partial | - |
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
관리자 화면 4개뿐이고 **쿠키 배너·마이페이지 동의 탭은 이 표에 없습니다** — 그 둘은
"레이아웃"이 아니라 "레이아웃 확장 조각"(`resources/extensions/cookie_banner.json`,
`mypage_privacy_tab.json`, §extension-points.md)으로 다른 확장/템플릿 레이아웃에 주입되는
형태라 별도 수집 축에 잡힙니다. 방문자가 실제로 보는 UI를 찾으려면 이 표가 아니라
확장점 문서를 봐야 합니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 1개 (정의: `resources/js/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `syncConsent` | `sirsoft-gdpr.syncConsent` |
<!-- @generated:handlers END -->

<!-- @intent START -->
`syncConsent` 하나뿐인 이유는 배너·마이페이지 UI 가 사실상 "동의 상태를 서버에 반영하고
화면을 갱신한다"는 단일 동작만 필요로 하기 때문입니다. 자동 차단/복원 로직(외부 스크립트·
iframe·1st-party 저장소 게이팅)은 이 액션 핸들러가 아니라 `dist/js/plugin.iife.js` 가 페이지
로드 시 스스로 수행합니다 — 사용자 조작에 반응하는 것과 페이지 로드마다 항상 실행되는 것을
액션 핸들러/전역 스크립트로 구분한 것입니다.

그 저장소 게이팅의 판정 목록(필수 허용목록)은 **인라인 페이로드**로 옵니다. 인터셉터는
`fetchPublicSettings()` 앞에서 서야 동의 전 첫 저장을 막을 수 있으므로 응답을 기다릴 수 없고,
그래서 `window.G7Config.plugins['sirsoft-gdpr']` 의 `necessary_storage_allowlist` ·
`necessary_storage_locked` 를 동기로 읽습니다(`readInlineNecessaryAllowlist()`). 이 경로가
비면 인터셉터는 잠금 집합만으로 서고, 운영자가 등재한 항목이 그 창에서 파기됩니다 — 예외도
로그도 남지 않으므로 새 설정 키를 노출할 때는 `config/settings/defaults.json` 의
`frontend_schema` 에 `expose: true` 를 반드시 함께 넣어야 합니다.

판정 함수는 `resources/js/necessaryAllowlist.ts` 한 곳에 있고 인터셉터 둘과 정리기가 그것을
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

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`initPlugin()` 이 "핸들러 재등록만" 하도록 좁혀 둔 것은 코어 규정(§CLAUDE.md "확장 미들웨어는
...")을 그대로 따른 결과입니다 — 자동 차단 스크립트의 부팅(도메인 카탈로그 로드, DOM 스캔
시작)을 여기 넣으면 로케일 전환마다 그 부팅이 중복 실행됩니다. 자동 차단 부팅은 `blocker.ts`/
`preblocker.ts` 가 페이지 최초 로드 시 1회만 수행합니다.
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
