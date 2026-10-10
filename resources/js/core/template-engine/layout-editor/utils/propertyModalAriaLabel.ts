/**
 * propertyModalAriaLabel.ts — 속성 편집 창의 접근 가능한 이름(aria-label)
 *
 * 창 제목(`PropertyEditorModal`)과 같은 규칙으로 요소 이름을 끼운다 — 편집 역량 이름(`$t:` 면 해석) → 컴포넌트 이름 →
 * 「요소」. 창을 여는 쪽이 이름 인자 없이 `layout_editor.property_modal.title` 을 풀면 접근성 트리에 「속성 편집: {name}」
 * 자리표시 원문이 남는다(7.1.0 실측).
 *
 * @since engine-v1.78.3
 */

import { getComponentCapability } from '../spec/editorSpecLoader';
import type { EditorSpec } from '../spec/specTypes';

type Translate = (key: string, params?: Record<string, string | number>) => string;

/**
 * 속성 편집 창의 aria-label 을 만든다.
 *
 * @param t 편집기 번역 함수(편집 대상 사전 우선)
 * @param spec 병합된 편집기 스펙
 * @param node 편집 대상 노드(이름만 본다)
 * @return 「속성 편집: {요소 이름}」
 * @since engine-v1.78.3
 */
export function propertyModalAriaLabel(
  t: Translate,
  spec: EditorSpec | null | undefined,
  node: { name?: unknown },
): string {
  const componentName = typeof node.name === 'string' ? node.name : '';
  const label = componentName ? (getComponentCapability(spec, componentName) as { label?: unknown } | null)?.label : undefined;
  const friendly = typeof label === 'string' && label.length > 0
    ? (label.startsWith('$t:') ? t(label.slice(3)) : label)
    : componentName || t('layout_editor.property_modal.element');
  return t('layout_editor.property_modal.title', { name: friendly });
}
