/**
 * SaveFeedbackBanner 컴포넌트 테스트
 *
 * useLayoutDocument.save 가 반환하는 SaveResult 6종 kind 별로:
 *  - DOM 마운트 여부 / data-testid 매칭 / 표시 메시지 / 액션 콜백 호출 / 자동 dismiss
 *  - concurrent_modification 모달의 3개 버튼 분기 (load_latest / view_my_changes / cancel)
 *  - validation_failed 의 errors 필드별 메시지 렌더
 *  - blocked_inactive_extension 의 blockedPaths 목록 렌더
 *  - network_error 의 message 렌더
 *  - 자동 dismiss 타이머 (5초) 동작 — concurrent / validation 은 자동 dismiss 없음
 *  - result=null 일 때 미렌더 (조기 반환)
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { SaveFeedbackBanner } from '../../components/SaveFeedbackBanner';
import type { SaveResult } from '../../hooks/useLayoutDocument';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

function withTranslation(node: React.ReactElement): React.ReactElement {
  const engine = new TranslationEngine();
  return (
    <TranslationProvider
      translationEngine={engine}
      translationContext={{ templateId: 'test', locale: 'ko' }}
    >
      {node}
    </TranslationProvider>
  );
}

describe('SaveFeedbackBanner — result=null', () => {
  it('result 가 null 이면 아무것도 렌더하지 않는다 (조기 반환)', () => {
    const { container } = render(
      withTranslation(<SaveFeedbackBanner result={null} onDismiss={vi.fn()} />)
    );
    expect(container.firstChild).toBeNull();
  });
});

describe('SaveFeedbackBanner — success', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('success kind → success banner 마운트 + 닫기 버튼 노출', () => {
    const result: SaveResult = { kind: 'success', newLockVersion: 5 };
    render(withTranslation(<SaveFeedbackBanner result={result} onDismiss={vi.fn()} />));
    expect(screen.getByTestId('g7le-save-banner-success')).toBeTruthy();
    expect(screen.getByTestId('g7le-save-banner-success-close')).toBeTruthy();
  });

  it('success kind → 닫기 버튼 클릭 시 onDismiss 호출', () => {
    const onDismiss = vi.fn();
    render(
      withTranslation(
        <SaveFeedbackBanner result={{ kind: 'success', newLockVersion: 5 }} onDismiss={onDismiss} />
      )
    );
    fireEvent.click(screen.getByTestId('g7le-save-banner-success-close'));
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it('success kind → 5초 후 자동 dismiss', () => {
    const onDismiss = vi.fn();
    render(
      withTranslation(
        <SaveFeedbackBanner result={{ kind: 'success', newLockVersion: 5 }} onDismiss={onDismiss} />
      )
    );
    expect(onDismiss).not.toHaveBeenCalled();
    act(() => {
      vi.advanceTimersByTime(5000);
    });
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });
});

describe('SaveFeedbackBanner — validation_failed', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('validation_failed kind → 에러 배너 마운트 + 각 필드 메시지 표시', () => {
    const result: SaveResult = {
      kind: 'validation_failed',
      status: 422,
      errors: {
        content: ['content 형식이 올바르지 않습니다'],
        'content.components.0.name': ['name 은 필수입니다'],
      },
    };
    render(withTranslation(<SaveFeedbackBanner result={result} onDismiss={vi.fn()} />));
    expect(screen.getByTestId('g7le-save-banner-validation')).toBeTruthy();
    const list = screen.getByTestId('g7le-save-banner-validation-errors');
    expect(list.textContent).toContain('content 형식이 올바르지 않습니다');
    expect(list.textContent).toContain('name 은 필수입니다');
    expect(list.textContent).toContain('content.components.0.name');
  });

  it('validation_failed → errors=null 이어도 배너 마운트 (목록만 미표시)', () => {
    const result: SaveResult = {
      kind: 'validation_failed',
      status: 422,
      errors: null,
    };
    render(withTranslation(<SaveFeedbackBanner result={result} onDismiss={vi.fn()} />));
    expect(screen.getByTestId('g7le-save-banner-validation')).toBeTruthy();
    expect(screen.queryByTestId('g7le-save-banner-validation-errors')).toBeNull();
  });

  it('validation_failed → 자동 dismiss 없음 (사용자 명시 닫기 필요)', () => {
    const onDismiss = vi.fn();
    render(
      withTranslation(
        <SaveFeedbackBanner
          result={{ kind: 'validation_failed', status: 422, errors: {} }}
          onDismiss={onDismiss}
        />
      )
    );
    act(() => {
      vi.advanceTimersByTime(30000);
    });
    expect(onDismiss).not.toHaveBeenCalled();
  });
});

describe('SaveFeedbackBanner — concurrent_modification', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('concurrent kind → 모달 마운트 + 3개 버튼 노출 + 버전 정보 키 사용', () => {
    const result: SaveResult = { kind: 'concurrent_modification', currentVersion: 7, yourVersion: 5 };
    render(withTranslation(<SaveFeedbackBanner result={result} onDismiss={vi.fn()} />));
    const modal = screen.getByTestId('g7le-save-banner-concurrent');
    expect(modal).toBeTruthy();
    expect(modal.getAttribute('role')).toBe('dialog');
    expect(screen.getByTestId('g7le-save-banner-concurrent-load-latest')).toBeTruthy();
    expect(screen.getByTestId('g7le-save-banner-concurrent-keep-mine')).toBeTruthy();
    expect(screen.getByTestId('g7le-save-banner-concurrent-cancel')).toBeTruthy();
    // jsdom TranslationEngine 폴백은 키 자체를 반환하므로 보간 값(7/5) 대신
    // version_info 키 호출 자체를 검증 — 키가 텍스트에 노출되어 있다는 것은 t() 호출 경로가 정상이라는 신호.
    expect(modal.textContent).toContain('layout_editor.save.concurrent.version_info');
  });

  it('concurrent → "최신 불러오기" 클릭 → onLoadLatest + onDismiss 호출', () => {
    const onLoadLatest = vi.fn();
    const onDismiss = vi.fn();
    render(
      withTranslation(
        <SaveFeedbackBanner
          result={{ kind: 'concurrent_modification', currentVersion: 7, yourVersion: 5 }}
          onDismiss={onDismiss}
          onLoadLatest={onLoadLatest}
        />
      )
    );
    fireEvent.click(screen.getByTestId('g7le-save-banner-concurrent-load-latest'));
    expect(onLoadLatest).toHaveBeenCalledTimes(1);
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it('concurrent → "내 변경 내용 보기" 클릭 → onKeepMyChanges + onDismiss 호출', () => {
    const onKeepMyChanges = vi.fn();
    const onDismiss = vi.fn();
    render(
      withTranslation(
        <SaveFeedbackBanner
          result={{ kind: 'concurrent_modification', currentVersion: 7, yourVersion: 5 }}
          onDismiss={onDismiss}
          onKeepMyChanges={onKeepMyChanges}
        />
      )
    );
    fireEvent.click(screen.getByTestId('g7le-save-banner-concurrent-keep-mine'));
    expect(onKeepMyChanges).toHaveBeenCalledTimes(1);
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it('concurrent → "취소" 클릭 → onDismiss 만 호출 (다른 콜백 미호출)', () => {
    const onLoadLatest = vi.fn();
    const onKeepMyChanges = vi.fn();
    const onDismiss = vi.fn();
    render(
      withTranslation(
        <SaveFeedbackBanner
          result={{ kind: 'concurrent_modification', currentVersion: 7, yourVersion: 5 }}
          onDismiss={onDismiss}
          onLoadLatest={onLoadLatest}
          onKeepMyChanges={onKeepMyChanges}
        />
      )
    );
    fireEvent.click(screen.getByTestId('g7le-save-banner-concurrent-cancel'));
    expect(onDismiss).toHaveBeenCalledTimes(1);
    expect(onLoadLatest).not.toHaveBeenCalled();
    expect(onKeepMyChanges).not.toHaveBeenCalled();
  });

  it('concurrent → 자동 dismiss 없음 (사용자 명시 결정 필요)', () => {
    const onDismiss = vi.fn();
    render(
      withTranslation(
        <SaveFeedbackBanner
          result={{ kind: 'concurrent_modification', currentVersion: 7, yourVersion: 5 }}
          onDismiss={onDismiss}
        />
      )
    );
    act(() => {
      vi.advanceTimersByTime(30000);
    });
    expect(onDismiss).not.toHaveBeenCalled();
  });

  it('concurrent → onLoadLatest 콜백 미제공 시에도 "최신 불러오기" 가 오류 없이 동작 + onDismiss 호출', () => {
    const onDismiss = vi.fn();
    render(
      withTranslation(
        <SaveFeedbackBanner
          result={{ kind: 'concurrent_modification', currentVersion: 7, yourVersion: 5 }}
          onDismiss={onDismiss}
        />
      )
    );
    fireEvent.click(screen.getByTestId('g7le-save-banner-concurrent-load-latest'));
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });
});

describe('SaveFeedbackBanner — blocked_inactive_extension', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('blocked kind → 경고 배너 + blockedPaths 목록 렌더', () => {
    const result: SaveResult = {
      kind: 'blocked_inactive_extension',
      blockedPaths: ['2.children.1', '4.children.0.children.2'],
    };
    render(withTranslation(<SaveFeedbackBanner result={result} onDismiss={vi.fn()} />));
    expect(screen.getByTestId('g7le-save-banner-blocked')).toBeTruthy();
    const list = screen.getByTestId('g7le-save-banner-blocked-paths');
    expect(list.textContent).toContain('2.children.1');
    expect(list.textContent).toContain('4.children.0.children.2');
  });

  it('blocked → blockedPaths=[] 이면 배너만 표시 (목록 미표시)', () => {
    const result: SaveResult = { kind: 'blocked_inactive_extension', blockedPaths: [] };
    render(withTranslation(<SaveFeedbackBanner result={result} onDismiss={vi.fn()} />));
    expect(screen.getByTestId('g7le-save-banner-blocked')).toBeTruthy();
    expect(screen.queryByTestId('g7le-save-banner-blocked-paths')).toBeNull();
  });

  it('blocked → 5초 후 자동 dismiss', () => {
    const onDismiss = vi.fn();
    render(
      withTranslation(
        <SaveFeedbackBanner
          result={{ kind: 'blocked_inactive_extension', blockedPaths: ['a'] }}
          onDismiss={onDismiss}
        />
      )
    );
    act(() => {
      vi.advanceTimersByTime(5000);
    });
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });
});

describe('SaveFeedbackBanner — network_error', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('network_error kind → 에러 배너 + 메시지 표시', () => {
    const result: SaveResult = { kind: 'network_error', message: 'Failed to fetch' };
    render(withTranslation(<SaveFeedbackBanner result={result} onDismiss={vi.fn()} />));
    const banner = screen.getByTestId('g7le-save-banner-network');
    expect(banner).toBeTruthy();
    expect(banner.textContent).toContain('Failed to fetch');
  });

  it('network_error → 5초 후 자동 dismiss', () => {
    const onDismiss = vi.fn();
    render(
      withTranslation(
        <SaveFeedbackBanner
          result={{ kind: 'network_error', message: 'timeout' }}
          onDismiss={onDismiss}
        />
      )
    );
    act(() => {
      vi.advanceTimersByTime(5000);
    });
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });
});

describe('SaveFeedbackBanner — guard_no_document', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('guard_no_document kind → neutral 배너 마운트', () => {
    render(
      withTranslation(
        <SaveFeedbackBanner result={{ kind: 'guard_no_document' }} onDismiss={vi.fn()} />
      )
    );
    expect(screen.getByTestId('g7le-save-banner-guard-no-document')).toBeTruthy();
  });

  it('guard_no_document → 5초 후 자동 dismiss', () => {
    const onDismiss = vi.fn();
    render(
      withTranslation(
        <SaveFeedbackBanner result={{ kind: 'guard_no_document' }} onDismiss={onDismiss} />
      )
    );
    act(() => {
      vi.advanceTimersByTime(5000);
    });
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });
});

describe('SaveFeedbackBanner — result 변경 시 dismiss 타이머 재설정', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('success → 4초 경과 후 새 success 도착 → 누적 9초 시점에도 첫 onDismiss 미호출 (타이머 재설정)', () => {
    const onDismiss = vi.fn();
    const { rerender } = render(
      withTranslation(
        <SaveFeedbackBanner result={{ kind: 'success', newLockVersion: 1 }} onDismiss={onDismiss} />
      )
    );
    act(() => {
      vi.advanceTimersByTime(4000);
    });
    rerender(
      withTranslation(
        <SaveFeedbackBanner result={{ kind: 'success', newLockVersion: 2 }} onDismiss={onDismiss} />
      )
    );
    act(() => {
      vi.advanceTimersByTime(4000);
    });
    // 두 번째 success 의 5초 타이머가 아직 만료 안 됨
    expect(onDismiss).not.toHaveBeenCalled();
    act(() => {
      vi.advanceTimersByTime(1000);
    });
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });
});
