# 그누보드7 Basic 템플릿 개발자 문서

> templates/_bundled/sirsoft-basic · 템플릿

<!-- @generated:stats START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**훅 수**: 0 · **구독 훅 수**: 0 · **라우트 수**: 50 · **모델 수**: 0 · **테이블 수**: 0 · **마이그레이션 수**: 0 · **레이아웃 수**: 196 · **핸들러 수**: 42
<!-- @generated:stats END -->

## 문서 목차

<!-- @generated:doc-toc START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 문서 | 내용 |
|---|---|
| [architecture.md](architecture.md) | 설계 의도·계층 지도·디렉토리 맵 |
| [components.md](components.md) | 템플릿이 제공하는 컴포넌트 |
| [layouts.md](layouts.md) | 레이아웃 목록과 라우트 매핑 |
| [handlers.md](handlers.md) | 템플릿 전용 핸들러와 부트스트랩 |
| [editor-spec.md](editor-spec.md) | 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 |
| [../AGENTS.md](../AGENTS.md) | 에이전트·확장개발자 진입점 |
| [../README.md](../README.md) | 사람(도입검토자·운영자) 진입점 |
<!-- @generated:doc-toc END -->

## 받는 중 표시 (1.3.3)

화면은 먼저 그려지고 데이터는 받는 대로 하나씩 들어온다(progressive). 받기 전 값은 `undefined` 라, 받은 뒤에만 뜻이 있는 요소는
받기 전에 그리지 않거나 흐린다. 로그인 사용자(`_global.currentUser`)도 같다 — 비회원은 엔진이 실패 대체값 `[]` 를 넣어 곧바로
정해지고, 회원은 응답이 올 때까지 `undefined` 다.

- 헤더: `_user_base` 가 `Header` 에 `userLoading="{{_global.currentUser === undefined}}"` 를 넘긴다. 받는 동안 사용자 메뉴 자리에 빈 자리(아바타 원 + 고정 폭 이름 막대, `role="status"`)를 그린다. 이름 막대는 이름을 받기 전이라 고정 폭(`w-20`)이어서, 받은 뒤 실제 이름 폭(`min-w-12`~`max-w-32`)과의 차이만큼 오른쪽 묶음이 움직일 수 있다(PC, 모바일은 이름을 그리지 않아 불변). 모바일 사용자 단추·메뉴의 로그인 묶음도 같다.
- 비회원 분기: `{{_global.currentUser !== undefined && !_global.currentUser?.uuid}}`. 로그인·가입·비밀번호 찾기 폼은 받은 비회원에게만 그린다(회원은 폼 없이 홈으로 간다).
- 안내: `{{ds !== undefined && (원래 조건)}}` — 받기 실패(`null`)는 원래 조건대로 둔다. 숫자(`?? 0`)는 그 소스를 기다리는 `blur_until_loaded` 아래에 둔다.

전수 검사는 `__tests__/layouts/loadingNegativeState.test.tsx` 다(받는 중 상태로 모든 레이아웃의 조건을 엔진 평가기로 평가).

## 모듈 결합 가드 (1.3.0)

게시판·이커머스·페이지 모듈과 주소 검색 플러그인은 선택 연동(`template.json` 의 `integrations`)이다. 모듈 화면 라우트는
`routes.json` 의 `requires` 로, 공통 화면의 데이터소스·노드·액션은 `{{_global.activeModules?.includes('<id>') ?? true}}` 한 모양의
`if` 로(데이터소스는 404 삼킴 동반), `Header`·`Footer` 링크는 모듈 활성 props 로 가린다(코어 7.1.4+, 옛 코어는 모두 켜짐으로 본다).
규칙은 [../AGENTS.md 「모듈 결합 가드 규칙」](../AGENTS.md#모듈-결합-가드-규칙-130), 화면별 가드 자리는
[layouts.md 「모듈 결합 가드」](layouts.md#모듈-결합-가드-130)에 있다.
