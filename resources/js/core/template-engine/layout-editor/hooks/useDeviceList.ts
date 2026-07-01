/**
 * useDeviceList.ts — 캔버스 토글/스타일 세부탭 공유 디바이스 목록 훅
 *
 * 현재 편집 문서(`LayoutDocumentContext.document.raw.components`)를 스캔해 디바이스 키 목록을
 * 도출한다(엔진 프리셋 4 + 레이아웃 사용 커스텀 키). 캔버스 상단 토글과 속성 모달 스타일
 * 세부탭이 같은 목록을 공유하는 SSoT 진입점.
 *
 * @since engine-v1.50.0
 */

import { useMemo } from 'react';
import { useLayoutDocumentContext } from '../LayoutDocumentContext';
import { collectDeviceKeys } from '../spec/deviceList';
import type { EditorNode } from '../utils/layoutTreeUtils';

/**
 * 현재 편집 문서 기준 디바이스 키 목록(프리셋 + 동적 커스텀)을 반환한다.
 *
 * 문서 컨텍스트가 없으면 프리셋만 반환한다(collectDeviceKeys(null) === 프리셋 4개).
 *
 * @return 디바이스 키 목록
 */
export function useDeviceList(): string[] {
  const docCtx = useLayoutDocumentContext();
  const components = docCtx?.document?.raw?.components as EditorNode[] | undefined;
  return useMemo(() => collectDeviceKeys(components ?? null), [components]);
}
