# NHN KCP 휴대폰 본인확인 — 프론트엔드

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
`sirsoft-verification_kginicis`와 마찬가지로 이 플러그인이 소유한 화면 레이아웃은 관리자
설정 화면 하나뿐입니다 — 회원가입·마이페이지의 본인확인 UI는 §레이아웃 확장 조각 및 코어
IDV 공통 팝업 UI로 존재합니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 1개 (정의: `resources/js/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `startAuth` | `sirsoft-verification_nhnkcp.startAuth` |
<!-- @generated:handlers END -->

<!-- @intent START -->
`startAuth`는 `isMobileEnvironment()`로 기기를 판별해 데스크톱에서는
`sirsoft-verification_kginicis`와 같은 팝업 패턴(사용자 클릭 컨텍스트 안에서
`window.open`)을, 모바일에서는 `sessionStorage`(`g7.identity.redirectStash`)에 복귀
정보를 저장한 뒤 전체 페이지 리다이렉트를 씁니다. 반드시 클릭 이벤트 핸들러 안에서
호출돼야 팝업 차단을 피할 수 있다는 제약은 데스크톱 경로에만 해당합니다 — 모바일
리다이렉트는 팝업 차단과 무관합니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftVerificationNhnkcp` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`window.__SirsoftVerificationNhnkcp`로 노출되는 이유는 코어가 로케일 전환 시 이 이름으로
재등록 진입점을 찾기 때문입니다(§CLAUDE.md "재등록 진입점"). `sirsoft-verification_kginicis`와
마찬가지로 이 플러그인은 결제창 SDK 를 동적 로드하지 않습니다 — 인증 화면 자체가 서버
렌더링 페이지(팝업 안) 또는 리다이렉트 대상(모바일)이므로 프론트가 별도 스크립트를 불러올
필요가 없습니다.
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
제3자 SDK 스크립트가 목록에 없는 이유는 §전역 진입점과 동일합니다 — 인증 화면은 팝업
안의 서버 렌더링 페이지이거나 모바일 리다이렉트 대상이라 프론트가 동적 로드할 자산이
없습니다. CSS 산출물이 없는 것은 UI가 버튼·상태 카드 같은 최소한의 코어 컴포넌트로만
구성되기 때문입니다.
<!-- @intent END -->
