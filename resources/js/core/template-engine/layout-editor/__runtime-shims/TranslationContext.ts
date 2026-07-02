/**
 * TranslationContext 런타임 shim — 편집기 번들 전용 (vite.config.editor.js alias 대상)
 * @since engine-v1.51.0
 * React Context 객체 동일성 필수 → window.G7Core.__runtime 에서 값 획득.
 */

import { getCoreRuntime } from './runtime';

const rt = getCoreRuntime();

export const TranslationProvider = rt.TranslationProvider;
export const useTranslation = rt.useTranslation;
export const TranslationReactContext = rt.TranslationReactContext;
