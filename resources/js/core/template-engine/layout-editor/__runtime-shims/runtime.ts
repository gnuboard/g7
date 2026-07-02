/**
 * 코어 런타임 접근 헬퍼 — 편집기 번들 전용 shim 공용 유틸
 *
 * @since engine-v1.51.0
 *
 * 편집기 lazy 번들의 런타임 shim 들이 `window.G7Core.__runtime`(메인 번들이 노출)에서
 * 코어 런타임 값을 안전하게 획득한다. 메인 `<script>` 가 동기 선행 실행되므로 편집기 IIFE
 * 실행 시점에 `__runtime` 은 항상 존재하지만, 부재 시 명확한 에러로 조기 실패시킨다.
 */

interface CoreRuntime {
  DynamicRenderer: any;
  ComponentRegistry: any;
  TranslationEngine: any;
  DataSourceManager: any;
  dataSourceManager: any;
  DataBindingEngine: any;
  dataBindingEngine: any;
  ActionDispatcher: any;
  TranslationReactContext: any;
  TranslationProvider: any;
  useTranslation: any;
  TransitionProvider: any;
  useTransitionState: any;
  ResponsiveContext: any;
  ResponsiveProvider: any;
  useResponsive: any;
  responsiveManager: any;
  BREAKPOINT_PRESETS: any;
  SlotProvider: any;
  useSlot: any;
  AuthManager: any;
  createLogger: any;
}

/**
 * `window.G7Core.__runtime` 반환 — 부재 시 즉시 throw.
 *
 * @returns 코어 런타임 표면 객체
 * @throws {Error} 메인 번들 미로드로 __runtime 부재 시
 */
export function getCoreRuntime(): CoreRuntime {
  const runtime = (typeof window !== 'undefined'
    ? (window as any).G7Core?.__runtime
    : undefined) as CoreRuntime | undefined;

  if (!runtime) {
    throw new Error(
      '[layout-editor] window.G7Core.__runtime 이 없습니다. ' +
        '코어 번들(template-engine.min.js)이 편집기 번들보다 먼저 로드되어야 합니다.'
    );
  }
  return runtime;
}
