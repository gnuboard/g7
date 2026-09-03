# Hello 사용자 템플릿 — 핸들러

> 템플릿 전용 핸들러와 부트스트랩 · 진입점: [AGENTS.md](../AGENTS.md)

## 템플릿 전용 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
없습니다. 이 샘플의 화면은 코어 엔진의 기본 핸들러(`apiCall` · `navigate` · `setState` 등)
만으로 그려집니다. 홈 화면의 목록도 `data_sources` 선언만으로 채워지므로 별도 핸들러가 필요
없습니다.

템플릿 전용 핸들러가 필요해지는 경우는 **그 템플릿의 화면 구조에만 있는 동작**을 다룰 때입니다 —
테마 전환, 장바구니 선택 상태, 브라우저 저장소 관리 같은 것들이며, `sirsoft-basic` 이 32개를
갖는 것이 그 예입니다.

핸들러를 도입할 때는 `ActionDispatcher` 에 등록하는 부트스트랩 함수가 함께 필요하고, 그 등록도
**레지스트리·디스패처 가용을 기다리는 재시도 루프** 안에 두어야 합니다.
<!-- @intent END -->

## 부트스트랩

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
전용 부트스트랩 함수가 없습니다. `src/index.ts` 가 **모듈 로드 시점에 곧바로** 코어
`ComponentRegistry` 를 찾아 Basic 8개를 등록하고, 레지스트리가 없으면 경고를 남기고
건너뜁니다.

> **이 형태는 따라 하지 않는 것이 좋습니다.** 같은 샘플군의
> `gnuboard7-hello_admin_template` 은 `initTemplate()` 안에서 100ms 간격 최대 50회
> **재시도**하며 `window.load` 이후에 시작합니다. 한 번만 시도하는 이 형태는 로드 순서에 따라
> 등록이 누락될 수 있고, 그러면 레이아웃이 컴포넌트 이름을 찾지 못해 **화면이 조용히
> 빕니다** — 콘솔 경고 외에는 흔적이 없습니다.

템플릿은 전역 객체를 노출하지 않습니다. 모듈·플러그인은
`window.__[Name].initModule/initPlugin` 을 고정 이름으로 노출해야 하지만(로케일 전환 후 코어가
그것을 다시 부릅니다), 템플릿은 코어가 부트스트랩 경로를 직접 알고 있습니다.

액션 핸들러를 도입하면 그때 `ActionDispatcher` 등록이 추가되며, 그 등록도 같은 재시도 루프
안에 두어야 합니다.
<!-- @intent END -->
