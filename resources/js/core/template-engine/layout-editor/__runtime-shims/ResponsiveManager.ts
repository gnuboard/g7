/**
 * ResponsiveManager 런타임 shim — 편집기 번들 전용 (vite.config.editor.js alias 대상)
 * @since engine-v1.51.0
 * 값은 window.G7Core.__runtime(싱글톤 동일성 필수).
 */

import { getCoreRuntime } from './runtime';

const rt = getCoreRuntime();

export const responsiveManager = rt.responsiveManager;
export const BREAKPOINT_PRESETS = rt.BREAKPOINT_PRESETS;
