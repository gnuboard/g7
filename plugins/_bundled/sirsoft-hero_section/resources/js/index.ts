/**
 * 히어로섹션 플러그인 프론트 엔트리.
 *
 * 컴포넌트 `sirsoft-hero_section.HeroSlider`(사용자 템플릿 확장점에 끼우는 슬라이더)와
 * `sirsoft-hero_section.HeroBannerPreview`(관리자 미리보기)를 코어 레지스트리에 등록한다.
 * 커스텀 액션 핸들러는 없다 — 관리자 화면은 엔진 기본 액션으로 충분하다.
 */
import '../css/hero.css';
import { HeroSlider } from './components/composite/HeroSlider';
import { HeroBannerPreview } from './components/composite/HeroBannerPreview';
import { HeroPeriodHint } from './components/composite/HeroPeriodHint';
import { HeroPreviewLanguage } from './components/composite/HeroPreviewLanguage';

export { HeroSlider, type HeroSliderProps, type HeroBanner, type HeroSliderSettings } from './components/composite/HeroSlider';
export { HeroBannerPreview, type HeroBannerPreviewProps } from './components/composite/HeroBannerPreview';
export { HeroPeriodHint, type HeroPeriodHintProps } from './components/composite/HeroPeriodHint';
export { HeroPreviewLanguage, type HeroPreviewLanguageProps } from './components/composite/HeroPreviewLanguage';

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

  return true;
}

registerComponents();
