import { default as templateMetadata } from '../template.json';
/**
 * Gnuboard7 Hello Admin Template
 *
 * 학습용 최소 Admin 템플릿 스켈레톤 — Basic 8개 컴포넌트만 포함.
 *
 * **템플릿은 컴포넌트를 스스로 등록하지 않는다.** 코어가 `components.json` 을 읽고 이 번들의
 * 전역 export 에서 구현체를 찾아 등록한다 — 이 파일이 할 일은 매니페스트가 선언한 이름을
 * export 하는 것뿐이다. (자가등록은 매니페스트에 실릴 자리가 없는 **확장**의 방식이다.)
 */
export * from './components/basic';
export { templateMetadata };
