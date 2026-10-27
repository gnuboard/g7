# 그누보드7 Basic 템플릿 개발자 문서

> templates/_bundled/sirsoft-basic · 템플릿

<!-- @generated:stats START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**훅 수**: 0 · **구독 훅 수**: 0 · **라우트 수**: 49 · **모델 수**: 0 · **테이블 수**: 0 · **마이그레이션 수**: 0 · **레이아웃 수**: 193 · **핸들러 수**: 37
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

## 모듈 결합 가드 (1.3.0)

게시판·이커머스·페이지 모듈과 주소 검색 플러그인은 선택 연동(`template.json` 의 `integrations`)이다. 모듈 화면 라우트는
`routes.json` 의 `requires` 로, 공통 화면의 데이터소스·노드·액션은 `{{_global.activeModules?.includes('<id>') ?? true}}` 한 모양의
`if` 로(데이터소스는 404 삼킴 동반), `Header`·`Footer` 링크는 모듈 활성 props 로 가린다(코어 7.1.4+, 옛 코어는 모두 켜짐으로 본다).
규칙은 [../AGENTS.md 「모듈 결합 가드 규칙」](../AGENTS.md#모듈-결합-가드-규칙-130), 화면별 가드 자리는
[layouts.md 「모듈 결합 가드」](layouts.md#모듈-결합-가드-130)에 있다.
