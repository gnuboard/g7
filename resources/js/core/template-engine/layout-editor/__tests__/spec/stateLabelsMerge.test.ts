/**
 * stateLabelsMerge.test.ts — stateLabels 카탈로그 병합 + 조회
 *
 * loadEditorSpecBundle 은 fetch 의존이라 본 단위는 조회 헬퍼(getStateLabelKey)의
 * 정확 일치 매칭만 직접 검증한다(병합 자체는 editorSpecLoader 내부 mergeStateLabels —
 * concat + key+scope dedup, fetch 통합 테스트는 editorSpecLoader.test.ts 범위).
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { getStateLabelKey } from '../../spec/editorSpecLoader';
import type { EditorSpec } from '../../spec/specTypes';

const spec: EditorSpec = {
  stateLabels: [
    { key: 'currentUser.data.name', scope: '_global', label_key: '$t:editor.state.user_name' },
    { key: 'q', scope: 'query', label_key: '$t:editor.state.search_q' },
  ],
};

describe('getStateLabelKey', () => {
  it('scope+key 정확 일치로 label_key 를 반환한다', () => {
    expect(getStateLabelKey(spec, '_global', 'currentUser.data.name')).toBe('$t:editor.state.user_name');
    expect(getStateLabelKey(spec, 'query', 'q')).toBe('$t:editor.state.search_q');
  });

  it('미매칭(다른 scope/접두사/미선언)은 null', () => {
    expect(getStateLabelKey(spec, '_local', 'currentUser.data.name')).toBeNull(); // 다른 scope
    expect(getStateLabelKey(spec, '_global', 'currentUser')).toBeNull(); // 접두사 매칭 아님
    expect(getStateLabelKey(spec, '_global', 'settings.siteName')).toBeNull(); // 미선언
    expect(getStateLabelKey(null, '_global', 'x')).toBeNull();
    expect(getStateLabelKey({}, '_global', 'x')).toBeNull();
  });
});
