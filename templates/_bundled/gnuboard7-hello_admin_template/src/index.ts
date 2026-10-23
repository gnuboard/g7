// e2e:allow 학습용 숨김(hidden) 샘플 템플릿 — 운영 사이트에서 활성화되지 않아 브라우저 경로가 없다. 링크 판정·부트스트랩은 Vitest(__tests__/components/A.coreLinkApi·__tests__/bootstrap/initTemplate)로 잠그고, 같은 코어 링크 API 의 브라우저 확인은 tests/Playwright/specs/engine-713-binding-cache-links.spec.ts 가 맡는다
/**
 * Gnuboard7 Hello Admin Template
 *
 * 학습용 최소 Admin 템플릿 스켈레톤 — Basic 8개 컴포넌트만 포함.
 *
 * **템플릿은 컴포넌트를 스스로 등록하지 않는다.** 코어가 `components.json` 을 읽고 이 번들의
 * 전역 export 에서 구현체를 찾아 등록한다 — 이 파일이 할 일은 매니페스트가 선언한 이름을
 * export 하는 것뿐이다. (자가등록은 매니페스트에 실릴 자리가 없는 **확장**의 방식이다.)
 */

// Basic 8개 컴포넌트
export * from './components/basic';

// Template Metadata
import templateMetadata from '../template.json';

export { templateMetadata };

// 템플릿 부트스트랩 (docs/frontend/template-development.md 「템플릿 부트스트랩 의무」)
export { handlerMap, initTemplate } from './bootstrap';
import { initTemplate } from './bootstrap';

// 템플릿 초기화 자동 실행
initTemplate();
