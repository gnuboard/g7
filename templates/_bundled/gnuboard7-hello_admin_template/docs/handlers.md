# Hello Admin Template — 핸들러

> 템플릿 전용 핸들러와 부트스트랩 · 진입점: [AGENTS.md](../AGENTS.md)

## 템플릿 전용 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
없습니다. 이 샘플의 화면은 코어 엔진의 기본 핸들러(`apiCall` · `navigate` · `setState` 등)
만으로 그려집니다.

템플릿 전용 핸들러가 필요해지는 경우는 **그 템플릿의 화면 구조에만 있는 동작**을 다룰 때입니다 —
테마 전환, 장바구니 선택 상태, 브라우저 저장소 관리 같은 것들이며,
`sirsoft-basic` 이 32개를 갖는 것이 그 예입니다.

핸들러를 도입할 때는 `initTemplate()` 안에서 `ActionDispatcher` 에 등록합니다. 등록도
컴포넌트 등록과 같은 재시도 루프 안에 두어야 합니다 — 그 시점에 디스패처가 아직 없을 수
있습니다.
<!-- @intent END -->

## 부트스트랩

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `src/index.ts` |
| 전역 객체 | **미노출** |
| 재등록 진입점 | `initTemplate()` |

재등록 진입점이 전역에 고정 이름으로 노출되지 않으면 로케일 전환 후 이 확장의 액션이 전부 무반응이 됩니다 (오류·토스트 없음).
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
전역 객체가 **미노출**입니다. 모듈·플러그인은 `window.__[Name].initModule/initPlugin` 을 고정
이름으로 노출해야 하지만(로케일 전환 후 코어가 그것을 다시 부릅니다), 템플릿은 코어가 부트스트랩
경로를 직접 알고 있어 전역 노출이 필요하지 않습니다.

`initTemplate()` 이 진입점이며 모듈 로드 시 스스로 실행됩니다. 하는 일은 하나 — 코어
`ComponentRegistry` 에 Basic 8개를 등록하는 것입니다.

**재시도 루프가 이 함수의 핵심**입니다. 실행 시점에 레지스트리가 아직 없을 수 있어 100ms 간격으로
최대 50회 다시 시도하며, `window.load` 이후에 시작합니다. 재시도가 없으면 로드 순서에 따라
등록이 건너뛰어지고, 레이아웃이 참조하는 컴포넌트 이름을 찾지 못해 **화면이 조용히 빕니다** —
오류도 경고도 남지 않습니다.

최대 재시도를 넘기면 `logger.error` 로 사실을 남깁니다. 조용히 포기하지 않는 것이 규약입니다.
<!-- @intent END -->
