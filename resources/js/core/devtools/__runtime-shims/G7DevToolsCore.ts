/**
 * G7DevToolsCore 런타임 shim — DevTools lazy 번들 전용 (vite.config.devtools.js alias 대상)
 * @since engine-v1.52.0
 *
 * DevTools 추적 코어는 디버그 무관 항상 필요해 메인 번들에 남는다. 디버그 lazy 번들
 * (DevToolsPanel 등)은 이 shim 으로 메인 번들의 단일 인스턴스를 빌려 쓴다(싱글톤 동일성).
 * 값은 window.G7Core.__runtime, 타입은 원본에서 재export.
 */

const runtime = (typeof window !== 'undefined'
  ? (window as any).G7Core?.__runtime
  : undefined) as { G7DevToolsCore?: unknown } | undefined;

if (!runtime?.G7DevToolsCore) {
  throw new Error(
    '[devtools] window.G7Core.__runtime.G7DevToolsCore 이 없습니다. ' +
      '코어 번들(template-engine.min.js)이 devtools 번들보다 먼저 로드되어야 합니다.'
  );
}

export const G7DevToolsCore = runtime.G7DevToolsCore as typeof import('../G7DevToolsCore').G7DevToolsCore;
export default G7DevToolsCore;
