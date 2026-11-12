/**
 * 히어로섹션 플러그인 프론트 엔트리.
 *
 * 컴포넌트 `sirsoft-hero_section.HeroSlider`(사용자 템플릿 확장점에 끼우는 슬라이더)와
 * `sirsoft-hero_section.HeroBannerPreview`(관리자 미리보기)·편집 보조 컴포넌트(기간 안내·미리보기 언어·충돌 비교·문구 자리 고르기)를
 * 코어 레지스트리에 등록한다.
 * 대상 설정 저장 완료 처리기는 응답 시점의 주소·입력을 확인해 늦은 응답을 격리한다.
 */
import '../css/main.css';
import { handlerMap } from './handlers';
import { HeroSlider } from './components/composite/HeroSlider';
import { HeroBannerPreview } from './components/composite/HeroBannerPreview';
import { HeroPeriodHint } from './components/composite/HeroPeriodHint';
import { HeroPreviewLanguage } from './components/composite/HeroPreviewLanguage';
import { HeroConflictResolver } from './components/composite/HeroConflictResolver';
import { HeroTextPositionPad } from './components/composite/HeroTextPositionPad';

export { HeroSlider, type HeroSliderProps, type HeroBanner, type HeroSliderSettings } from './components/composite/HeroSlider';
export { HeroBannerPreview, type HeroBannerPreviewProps } from './components/composite/HeroBannerPreview';
export { HeroPeriodHint, type HeroPeriodHintProps } from './components/composite/HeroPeriodHint';
export { HeroPreviewLanguage, type HeroPreviewLanguageProps } from './components/composite/HeroPreviewLanguage';
export { HeroConflictResolver, type HeroConflictResolverProps } from './components/composite/HeroConflictResolver';
export { HeroTextPositionPad, type HeroTextPositionPadProps } from './components/composite/HeroTextPositionPad';

/** 플러그인 식별자 — 컴포넌트 이름 앞에 붙는다. */
const PLUGIN_IDENTIFIER = 'sirsoft-hero_section';

/**
 * 컴포넌트를 코어 레지스트리에 등록합니다.
 *
 * @returns 등록했으면 true
 */
export function registerComponents(): boolean {
  const registry = (window as any).G7Core?.getComponentRegistry?.();

  if (!registry?.registerExtensionComponent) {
    return false;
  }

  registry.registerExtensionComponent(`${PLUGIN_IDENTIFIER}.HeroSlider`, HeroSlider, {
    name: `${PLUGIN_IDENTIFIER}.HeroSlider`,
    type: 'composite',
  });
  registry.registerExtensionComponent(`${PLUGIN_IDENTIFIER}.HeroBannerPreview`, HeroBannerPreview, {
    name: `${PLUGIN_IDENTIFIER}.HeroBannerPreview`,
    type: 'composite',
  });
  registry.registerExtensionComponent(`${PLUGIN_IDENTIFIER}.HeroPeriodHint`, HeroPeriodHint, { name: `${PLUGIN_IDENTIFIER}.HeroPeriodHint`, type: 'composite' });
  registry.registerExtensionComponent(`${PLUGIN_IDENTIFIER}.HeroPreviewLanguage`, HeroPreviewLanguage, { name: `${PLUGIN_IDENTIFIER}.HeroPreviewLanguage`, type: 'composite' });
  registry.registerExtensionComponent(`${PLUGIN_IDENTIFIER}.HeroConflictResolver`, HeroConflictResolver, { name: `${PLUGIN_IDENTIFIER}.HeroConflictResolver`, type: 'composite' });
  registry.registerExtensionComponent(`${PLUGIN_IDENTIFIER}.HeroTextPositionPad`, HeroTextPositionPad, { name: `${PLUGIN_IDENTIFIER}.HeroTextPositionPad`, type: 'composite' });

  return true;
}

registerComponents();

/** 대상 저장 완료 처리기를 엔진에 등록한다. */
function registerHandlers(): boolean {
  const dispatcher = (window as any).G7Core?.getActionDispatcher?.();
  if (!dispatcher?.registerHandler) return false;
  for (const [name, handler] of Object.entries(handlerMap)) dispatcher.registerHandler(`${PLUGIN_IDENTIFIER}.${name}`, handler, { category: 'plugin', source: PLUGIN_IDENTIFIER });
  return true;
}
if (!registerHandlers()) {
  let retries = 0;
  const timer = setInterval(() => { if (registerHandlers() || ++retries >= 50) clearInterval(timer); }, 100);
}

/** 로케일 전환으로 디스패처가 교체되면 처리기만 다시 등록한다. */
export function initPlugin(): void { registerHandlers(); }
(window as unknown as Record<string, unknown>).__SirsoftHeroSection = { identifier: PLUGIN_IDENTIFIER, initPlugin };
