/**
 * 팝업 플러그인 프론트 엔트리.
 *
 * 컴포넌트 `sirsoft-popup.PopupLayer`(사용자 템플릿 공통 확장점 `user_global_overlay` 에 끼우는 팝업 레이어)와
 * 관리자 보조 컴포넌트(미리보기·저장 후 상태 안내·동시 수정 충돌 비교·이미지 위 글 상자 편집기)를 코어 레지스트리에 등록한다.
 * 커스텀 액션 핸들러는 없다 — 관리자 화면은 엔진 기본 액션으로 충분하다.
 */
import '../css/popup.css';
import { PopupLayer } from './components/composite/PopupLayer';
import { PopupPreview } from './components/composite/PopupPreview';
import { PopupPeriodHint } from './components/composite/PopupPeriodHint';
import { PopupConflictResolver } from './components/composite/PopupConflictResolver';
import { PopupOverlayEditor } from './components/composite/PopupOverlayEditor';

export { PopupLayer, type PopupLayerProps } from './components/composite/PopupLayer';
export { PopupPreview, type PopupPreviewProps } from './components/composite/PopupPreview';
export { PopupPeriodHint, type PopupPeriodHintProps } from './components/composite/PopupPeriodHint';
export { PopupConflictResolver, type PopupConflictResolverProps } from './components/composite/PopupConflictResolver';
export { PopupOverlayEditor, type PopupOverlayEditorProps } from './components/composite/PopupOverlayEditor';
export type { PublicPopup, PopupDisplaySettings } from './lib/types';

/** 플러그인 식별자 — 컴포넌트 이름 앞에 붙는다. */
const PLUGIN_IDENTIFIER = 'sirsoft-popup';

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

  const components: Array<[string, unknown]> = [
    ['PopupLayer', PopupLayer],
    ['PopupPreview', PopupPreview],
    ['PopupPeriodHint', PopupPeriodHint],
    ['PopupConflictResolver', PopupConflictResolver],
    ['PopupOverlayEditor', PopupOverlayEditor],
  ];
  components.forEach(([name, component]) => {
    registry.registerExtensionComponent(`${PLUGIN_IDENTIFIER}.${name}`, component, { name: `${PLUGIN_IDENTIFIER}.${name}`, type: 'composite' });
  });

  return true;
}

registerComponents();
