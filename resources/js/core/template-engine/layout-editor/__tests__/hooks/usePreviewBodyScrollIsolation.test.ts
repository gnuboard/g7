/**
 * usePreviewBodyScrollIsolation 회귀 테스트
 *
 * 편집기 프리뷰는 동일 문서 렌더라, 모달 편집 모드에서 Modal composite(isOpen=true 강제)의
 * `document.body.style.overflow='hidden'` 스크롤 락이 편집기 페이지(=캔버스) 스크롤바를
 * 제거한다. 본 hook 은 편집기 생존 동안 body 인라인 overflow:hidden 을 무력화한다.
 *
 * 검증: (1) 마운트 시 기존 락 해제 (2) 마운트 후 늦게 걸린 락도 해제(MutationObserver —
 * Modal 의 isOpen effect 는 캔버스 렌더보다 늦게 실행됨) (3) hidden 이외 값은 보존
 * (4) 언마운트 후에는 더 이상 개입하지 않음.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { usePreviewBodyScrollIsolation } from '../../hooks/usePreviewBodyScrollIsolation';

describe('usePreviewBodyScrollIsolation', () => {
  beforeEach(() => {
    document.body.style.overflow = '';
    document.body.style.overflowY = '';
  });
  afterEach(() => {
    document.body.style.overflow = '';
    document.body.style.overflowY = '';
  });

  it('마운트 시 이미 걸려 있던 body overflow:hidden 락을 해제한다', () => {
    document.body.style.overflow = 'hidden';

    renderHook(() => usePreviewBodyScrollIsolation());

    expect(document.body.style.overflow).toBe('');
  });

  it('마운트 후 늦게 걸린 락도 해제한다 (Modal composite isOpen effect — MutationObserver)', async () => {
    renderHook(() => usePreviewBodyScrollIsolation());

    // 모달 편집 모드 진입 → Modal composite 가 늦게 body 락을 건다
    document.body.style.overflow = 'hidden';
    await waitFor(() => {
      expect(document.body.style.overflow).toBe('');
    });

    // overflowY 단독 락도 동일 해제
    document.body.style.overflowY = 'hidden';
    await waitFor(() => {
      expect(document.body.style.overflowY).toBe('');
    });
  });

  it('hidden 이외의 body overflow 값은 건드리지 않는다', async () => {
    renderHook(() => usePreviewBodyScrollIsolation());

    document.body.style.overflow = 'auto';
    // observer 가 발화할 시간을 주되 값은 보존되어야 한다
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(document.body.style.overflow).toBe('auto');
  });

  it('언마운트 후에는 더 이상 개입하지 않는다 (observer disconnect)', async () => {
    const { unmount } = renderHook(() => usePreviewBodyScrollIsolation());
    unmount();

    document.body.style.overflow = 'hidden';
    await new Promise((resolve) => setTimeout(resolve, 20));
    // 편집기 밖(런타임)에서는 모달 스크롤 락이 정상 동작해야 한다
    expect(document.body.style.overflow).toBe('hidden');
  });
});
