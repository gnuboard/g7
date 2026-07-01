/**
 * LayoutDocumentContext.tsx — useLayoutDocument 결과 공유 컨텍스트
 *
 * useLayoutDocument 를 LayoutEditorChrome 에서 호출해 결과를 Context 로 내려주고,
 * PreviewCanvas / Toolbar / Overlay 들이 모두 동일 인스턴스를 공유한다. 두 곳
 * 에서 같은 hook 을 부르면 두 인스턴스가 되어 patch/save 가 서로 보이지 않는
 * 결함이 생기므로 단일 진입점이 필수.
 *
 * @since engine-v1.50.0
 */

import React, { createContext, useContext } from 'react';
import type { UseLayoutDocumentResult } from './hooks/useLayoutDocument';

const LayoutDocumentContext = createContext<UseLayoutDocumentResult | null>(null);

export interface LayoutDocumentProviderProps {
  value: UseLayoutDocumentResult;
  children: React.ReactNode;
}

export function LayoutDocumentProvider({ value, children }: LayoutDocumentProviderProps): React.ReactElement {
  return React.createElement(LayoutDocumentContext.Provider, { value }, children);
}

/**
 * Provider 마운트 전에 호출되면 fallback (자체 useLayoutDocument 호출) 을 위해
 * null 반환. 호출자가 그 경우를 분기 처리한다.
 */
export function useLayoutDocumentContext(): UseLayoutDocumentResult | null {
  return useContext(LayoutDocumentContext);
}
