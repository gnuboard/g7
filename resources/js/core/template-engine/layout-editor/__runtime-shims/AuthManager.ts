/**
 * AuthManager 런타임 shim — 편집기 번들 전용 (vite.config.editor.js alias 대상)
 * @since engine-v1.51.0
 * 값은 window.G7Core.__runtime(인증 싱글톤 동일성 필수).
 * 원본 경로: resources/js/core/auth/AuthManager (본 shim 기준 ../../../auth/AuthManager)
 */

import { getCoreRuntime } from './runtime';

export const AuthManager = getCoreRuntime().AuthManager;
