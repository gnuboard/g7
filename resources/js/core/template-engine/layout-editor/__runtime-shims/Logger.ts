/**
 * Logger 런타임 shim — 편집기 번들 전용 (vite.config.editor.js alias 대상)
 * @since engine-v1.51.0
 * 값은 window.G7Core.__runtime(로거 팩토리 동일성), 타입은 원본에서 재export.
 * 원본 경로: resources/js/core/utils/Logger (본 shim 기준 ../../../../utils/Logger)
 */

import { getCoreRuntime } from './runtime';

export const createLogger = getCoreRuntime().createLogger;

export type { Logger } from '../../../utils/Logger';
