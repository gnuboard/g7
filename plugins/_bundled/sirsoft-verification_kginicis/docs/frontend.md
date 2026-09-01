# KG이니시스 본인인증 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 1개 (루트: `resources/layouts`).

| 그룹 | 개수 |
|---|---|
| `admin` | 1개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
결제 PG 플러그인들과 마찬가지로 이 플러그인이 소유한 화면 레이아웃은 관리자 설정 화면
하나뿐입니다 — 회원가입·마이페이지의 본인확인 UI는 이 플러그인 소유가 아니라
§레이아웃 확장(다른 확장/템플릿 레이아웃에 주입되는 조각) 및 코어 IDV 공통 팝업 UI로
존재합니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 1개 (정의: `resources/js/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `startAuth` | `sirsoft-verification_kginicis.startAuth` |
<!-- @generated:handlers END -->

<!-- @intent START -->
`startAuth`는 반드시 사용자 클릭 이벤트 핸들러 안에서(동기적으로) 호출돼야 합니다 —
`window.open`으로 빈 팝업을 먼저 연 뒤 그 팝업에 이니시스 인증 폼을 제출하는 방식인데,
`window.open`이 사용자 제스처 컨텍스트 밖(예: API 응답을 기다린 뒤의 비동기 콜백)에서
호출되면 Chrome 등 브라우저의 팝업 차단기에 걸립니다. 과거 `setLauncher`로 코어 IDV
런처를 덮어써 챌린지 발급 이후 시점에 팝업을 여는 방식을 썼다가 이 문제로 폐기됐습니다
(소스 주석 "Phase E′-revert").
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftVerificationKginicis` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`window.__SirsoftVerificationKginicis`로 노출되는 이유는 코어가 로케일 전환 시 이 이름으로
재등록 진입점을 찾기 때문입니다(§CLAUDE.md "재등록 진입점"). 이 플러그인은 결제 PG
플러그인들과 달리 결제창 SDK 를 동적 로드하지 않습니다 — 이니시스 인증 폼은 팝업
안에서 서버가 렌더링한 페이지로 제출되므로 프론트가 별도 스크립트를 불러올 필요가
없습니다.
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
제3자 SDK 스크립트가 목록에 없는 것은 §전역 진입점에서 설명한 대로 이 플러그인이 그런
자산을 동적 로드하지 않기 때문입니다 — 인증 폼 자체가 서버 렌더링 페이지라 프론트는
팝업을 열고 결과를 회수하는 역할만 합니다. CSS 산출물이 없는 것은 이 플러그인의 UI가
버튼·상태 카드 같은 최소한의 코어 컴포넌트로만 구성되기 때문입니다.
<!-- @intent END -->
