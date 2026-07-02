/**
 * layout-editor-entry.ts — 레이아웃 편집기 lazy 번들 진입점
 *
 * @since engine-v1.51.0
 *
 * 이 파일은 별도 번들(`layout-editor.min.js`)의 엔트리다. 메인 코어 번들
 * (`template-engine.min.js`)에서 편집기 코드를 제거해 초기 접속 payload 를 줄이고,
 * `/admin/layout-editor/*` 진입 시에만 이 번들을 런타임 `<script>` 주입으로 로드한다.
 *
 * 역할:
 * 1. `LayoutEditorChrome` **컴포넌트**를 `window.G7Core.__LayoutEditorChrome` 로 노출.
 *    - mount 함수가 아닌 컴포넌트 자체를 노출한다. provider wrapping(Translation/Transition/
 *      Responsive/Slot)은 메인 번들 `template-engine.ts` `renderTemplate()` 이 그대로 유지하고,
 *      편집기 번들은 `ReactDOM.createRoot` 를 절대 호출하지 않는다(LayoutEditorChrome 규약).
 * 2. `__onChromeReady` 콜백 호출 — 메인 번들의 `loadLayoutEditorBundle()` 이 주입 전에
 *    등록해 둔 resolve. `onload` 이벤트를 fallback 경로로 병행한다.
 *
 * `LayoutEditorChrome` 를 import 하는 것만으로 편집기 트리 전체가 이 번들에 포함되고,
 * 모듈 로드 side-effect(`registerCoreWidgets`/`registerCoreEditors`/`exposeLayoutEditorGlobals`
 * — 메인 번들 stub 큐 flush)가 자동 실행된다.
 *
 * 코어 런타임(DynamicRenderer/엔진/컨텍스트)은 `vite.config.editor.js` 의 alias 로
 * `window.G7Core.__runtime` 에서 빌려 쓴다(0바이트 중복, 싱글톤/컨텍스트 동일성 보장).
 * React/ReactDOM/jsx-runtime 은 external 로 `window.React` 등을 참조한다.
 */

import { LayoutEditorChrome } from './template-engine/layout-editor/LayoutEditorChrome';

if (typeof window !== 'undefined') {
  const G7Core = (window as any).G7Core || ((window as any).G7Core = {});

  // 편집기 셸 컴포넌트 노출 (메인 번들이 provider 트리 안에서 렌더)
  G7Core.__LayoutEditorChrome = LayoutEditorChrome;

  // 준비 완료 통지 — loadLayoutEditorBundle() 이 주입 전에 등록한 resolve 호출
  if (typeof G7Core.__onChromeReady === 'function') {
    try {
      G7Core.__onChromeReady();
    } catch {
      /* 콜백 실패 격리 — onload fallback 이 별도로 resolve */
    }
  }
}

export { LayoutEditorChrome };
