/**
 * devtools-entry.ts — DevTools lazy 번들 진입점
 *
 * @since engine-v1.51.0
 *
 * 개발자 진단도구(DevTools)는 디버그 모드(`G7Config.debug` / 환경설정)를 켰을 때만 쓰인다.
 * 그럼에도 메인 코어 번들(`template-engine.min.js`)에 정적 포함되면 모든 사용자가 받고도
 * 실행하지 않는 죽은 코드가 된다(패널 UI 만 311KB). 이를 별도 `devtools.min.js` 로 분리해
 * `initDevToolsAPI()` 가 `isEnabled()` 참일 때만 런타임 `<script>` 주입으로 로드한다.
 *
 * 노출: 디버그 전용 무거운 모듈 4종을 `window.G7Core.__devtools` 로 노출.
 *   - DiagnosticEngine (진단 엔진 클래스)
 *   - getServerConnector (서버 커넥터 팩토리)
 *   - getStyleTracker (스타일 추적기 팩토리)
 *   - DevToolsPanel (패널 UI React 컴포넌트)
 *
 * `G7DevToolsCore`(추적 코어, 디버그 무관 항상 필요)는 메인 번들에 남아 `window.G7Core.__runtime`
 * 으로 공유되며, DevToolsPanel 등은 vite.config.devtools.js alias 로 그것을 빌려 쓴다.
 * React 는 external 로 window.React 재사용.
 */

import { DiagnosticEngine } from './devtools/DiagnosticEngine';
import { getServerConnector } from './devtools/ServerConnector';
import { getStyleTracker } from './devtools/StyleTracker';
import { DevToolsPanel } from './devtools/ui/DevToolsPanel';

if (typeof window !== 'undefined') {
  const G7Core = (window as any).G7Core || ((window as any).G7Core = {});

  G7Core.__devtools = {
    DiagnosticEngine,
    getServerConnector,
    getStyleTracker,
    DevToolsPanel,
  };

  // 준비 완료 통지 — loadDevToolsBundle() 이 주입 전에 등록한 resolve 호출
  if (typeof G7Core.__onDevToolsReady === 'function') {
    try {
      G7Core.__onDevToolsReady();
    } catch {
      /* 콜백 실패 격리 — onload fallback 이 별도 resolve */
    }
  }
}

export { DiagnosticEngine, getServerConnector, getStyleTracker, DevToolsPanel };
