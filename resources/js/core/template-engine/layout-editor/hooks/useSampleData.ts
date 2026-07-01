/**
 * useSampleData.ts — 편집기 캔버스용 SampleDataProvider 구성 hook
 *
 * `editorSpec.sampleData` 와 코어 프리셋을 결합해 `SampleDataProvider` 인스턴스를
 * 메모이즈해 반환한다. `useLayoutDocument` 가 매 라우트 진입 시 새 인스턴스를
 * `DataSourceManager` 옵션으로 주입하므로 본 hook 의 반환값 안정성이 중요하다.
 *
 * @since engine-v1.50.0
 */

import { useMemo } from 'react';
import type { SampleDataProvider } from '../../DataSourceManager';
import type { EditorSampleDataSpec, EditorSpec } from '../spec/specTypes';
import {
  createSampleDataProvider,
  type SampleMatchInfo,
} from '../sample-data/sampleDataProvider';
import { trackSampleMatch } from '../devtools/editorTrackers';

export interface UseSampleDataOptions {
  /**
   * 편집 모드 활성 여부. false 면 hook 은 undefined 반환 — 일반 렌더 분기로 진입.
   */
  isEditMode: boolean;
  /**
   * 현재 활성 EditorSpec — `sampleData` 블록을 이 hook 이 활용.
   */
  editorSpec?: EditorSpec;
  /**
   * 활성 페이지 상태의 sampleData 오버라이드.
   * 매칭 시 base `sampleData` 보다 우선해 **통째 교체**한다. 미전달/undefined 면
   * base sampleData 만 사용(디그레이드 — states 미선언/scope 미매칭/기본 상태).
   */
  sampleOverride?: EditorSampleDataSpec;
}

/**
 * 편집 모드일 때 `SampleDataProvider` 를 반환, 일반 모드면 undefined.
 *
 * `useLayoutDocument` (또는 부팅 코드) 가 이 값을 `DataSourceManager` 옵션의
 * `sampleProvider` 로 전달. devtools 트래커 는 본 hook 이 만든
 * 프로바이더의 `onMatch` 콜백 안에서 자동 호출된다.
 */
export function useSampleData({
  isEditMode,
  editorSpec,
  sampleOverride,
}: UseSampleDataOptions): SampleDataProvider | undefined {
  const spec: EditorSampleDataSpec | undefined = editorSpec?.sampleData;

  return useMemo(() => {
    if (!isEditMode) {
      return undefined;
    }

    return createSampleDataProvider({
      spec,
      // 활성 페이지 상태의 오버라이드. resolve 시 base spec 보다
      // 우선해 통째 교체 매칭을 시도하고, 미매칭이면 base spec 폴백으로 진행한다.
      overrideSpec: sampleOverride,
      onMatch: (info: SampleMatchInfo) => {
        trackSampleMatch(info);
      },
    });
  }, [isEditMode, spec, sampleOverride]);
}
