/**
 * 템플릿 부트스트랩 — `initTemplate()` (학습용 최소 구현, 7.1.3)
 *
 * 모든 템플릿은 `initTemplate()` 에서 ① 템플릿 전용 핸들러를 엔진에 등록하고 ② 본인인증 모달 launcher 를
 * 등록하거나 코어 기본을 쓴다고 정한다(docs/frontend/template-development.md 「템플릿 부트스트랩 의무」).
 * 이 샘플은 전용 핸들러가 없고(빈 `handlerMap`), 본인인증은 **코어 기본 launcher**(토스트 + `/identity/challenge`
 * 화면 이동)를 쓴다 — 그래서 `G7Core.identity.setLauncher` 를 부르지 않는다. 핸들러를 추가하면 `handlerMap` 에
 * 이름 → 함수로 넣는다.
 */
/** 템플릿 전용 핸들러(이름 → 함수). 샘플이라 비어 있다 */
export declare const handlerMap: Record<string, (...args: any[]) => unknown>;
/**
 * 템플릿 전용 핸들러를 엔진에 등록합니다. 엔진이 아직 없으면 100ms 간격으로 다시 시도합니다.
 *
 * @returns 반환값 없음
 */
export declare function initTemplate(): void;
