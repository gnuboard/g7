/**
 * sirsoft-basic 설정 화면 묶음 진입 (#434 D28·D30)
 *
 * 코어가 관리자 화면을 열 때 설치된 템플릿의 설정 화면 묶음(`dist/settings/settings.iife.js`)을 플러그인 묶음처럼 싣는다 —
 * 이 템플릿이 꺼져 있거나 다른 관리자 템플릿이 켜져 있어도. 여기서 설정 화면 부품을 `sirsoft-basic.이름` 으로 등록하고,
 * 부품 문구는 이 템플릿 문구(`sirsoft-basic.{묶음}`, 부품 매니페스트 `components.settings.json` 의 `lang`)를 먼저 찾게 한다.
 */

import { TemplateSettingsScreen } from './TemplateSettingsScreen';
import { setTranslationNamespace } from '../support/translate';
import '../styles/settings.css';

const TEMPLATE_IDENTIFIER = 'sirsoft-basic';

setTranslationNamespace(TEMPLATE_IDENTIFIER);

/**
 * 설정 화면 부품을 확장 부품 좌표로 등록합니다 — 켜진 관리자 템플릿이 `overrides` 로 대체했으면 그쪽이 남는다.
 *
 * @returns 등록했으면 true (부품 레지스트리가 아직 없으면 false)
 */
export function registerSettingsComponents(): boolean {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const registry = (window as any).G7Core?.getComponentRegistry?.();

  if (!registry?.registerExtensionComponent) {
    return false;
  }

  registry.registerExtensionComponent(`${TEMPLATE_IDENTIFIER}.TemplateSettingsScreen`, TemplateSettingsScreen, {
    name: `${TEMPLATE_IDENTIFIER}.TemplateSettingsScreen`,
    type: 'composite',
  });

  return true;
}

registerSettingsComponents();
