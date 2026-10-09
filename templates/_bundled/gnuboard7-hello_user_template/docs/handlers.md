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

핸들러를 도입할 때는 핸들러 맵을 만들어 `src/index.ts` 에서 `window.G7TemplateHandlers =
handlerMap` 으로 노출합니다(`templates/_bundled/sirsoft-basic/src/index.ts` 참고). 맵의 키가 레이아웃에서 부르는
이름이며, `sirsoft-basic` 은 `'sirsoft-basic.addSelectedItemIfComplete'` 처럼 템플릿 식별자를
앞에 붙입니다. 코어 `TemplateApp` 은 부팅 때(초기 라우트 처리 전) 이 맵을 `ActionDispatcher` 에
등록하고, 로케일을 바꿔 디스패처를 새로 만든 뒤에도 같은 맵을 다시 등록합니다
(`reinitializeTemplateHandlers()`). 그래서 템플릿에는 디스패처를 기다리는 부트스트랩 함수나
재시도 루프가 필요 없습니다. 문서 생성기도 `G7TemplateHandlers` 를 템플릿의 재등록 경로로
인정합니다.
<!-- @intent END -->

## 부트스트랩

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
부트스트랩 함수가 없습니다. `src/index.ts` 는 Basic 8개, 확장 컴포넌트 대체본
`HelloModuleMemoCard`, `templateMetadata` 를 export 할 뿐이고, 액션 핸들러가 없으므로
`window.G7TemplateHandlers` 도 노출하지 않습니다.

**컴포넌트 등록은 코어 몫입니다.** 블레이드가 엔진 번들과 이 템플릿의 `components.iife.js` 를
함께 싣고, 둘 다 적재된 뒤 코어가 초기화합니다. 그때 `ComponentRegistry` 가 `components.json` 을
읽어 선언된 이름마다 번들 전역(`window.Gnuboard7HelloUserTemplate`)에서 구현체를 찾아 등록합니다.
Basic 은 `name` 으로, 대체본은 `overrides[].exportName` 으로 찾습니다. 템플릿이 레지스트리를 찾아
기다리거나 재시도할 일이 없습니다.

깨지는 지점은 이름 대응입니다. export 이름이 선언과 다르거나 export 가 빠지면 코어는 경고만
남기고 건너뜁니다. Basic 쪽이면 레이아웃이 그 이름을 참조하는 자리가 렌더되지 않고, 대체본
쪽이면 Hello 모듈의 원본 `MemoCard` 가 그대로 그려집니다. 이 경고는 디버그 모드에서만 콘솔에
보이므로, 운영 화면에서는 **화면이 조용히 빈 것**이나 서식이 원본으로 돌아간 것으로만
드러납니다.

코어는 부팅 때 `window.G7TemplateHandlers` 를 찾고, 없으면 `Template handlers not found` 경고를
남깁니다. 핸들러가 없는 이 템플릿에서는 정상입니다.
<!-- @intent END -->
