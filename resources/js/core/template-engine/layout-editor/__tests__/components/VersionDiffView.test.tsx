/**
 * VersionDiffView.test.tsx — (버전 비교 Unified diff 뷰)
 *
 * 검증 대상: VersionDiffView
 *  - 변경 있는 두 content → hunk + add/remove 라인 렌더
 *  - 동일 content → identical 안내
 *  - 과대 content → too_large 안내
 *  - back/close 콜백
 *  - 제목에 old/new 버전 번호
 *
 * @effects version_compare_first_version_diffs_against_empty_baseline, version_compare_identical_shows_identical_notice,
 *   version_compare_too_large_shows_guard_notice
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { VersionDiffView } from '../../components/VersionDiffView';
import { DIFF_MAX_LINES } from '../../utils/lineDiff';

const t = (k: string, params?: Record<string, string | number>) =>
  params ? `${k}(${JSON.stringify(params)})` : k;

afterEach(() => cleanup());

function renderDiff(overrides: Partial<React.ComponentProps<typeof VersionDiffView>> = {}) {
  const onBack = overrides.onBack ?? vi.fn();
  const onClose = overrides.onClose ?? vi.fn();
  render(
    <VersionDiffView
      oldVersion={1}
      newVersion={2}
      oldContent={{ components: [{ type: 'Div' }] }}
      newContent={{ components: [{ type: 'Section' }, { type: 'Button' }] }}
      t={t}
      onBack={onBack}
      onClose={onClose}
      {...overrides}
    />,
  );
  return { onBack, onClose };
}

describe('VersionDiffView', () => {
  it('제목에 old/new 버전 번호 + hunk/diff 라인 렌더', () => {
    renderDiff();
    expect(screen.getByTestId('g7le-version-diff-title').textContent).toContain('"old":1');
    expect(screen.getByTestId('g7le-version-diff-title').textContent).toContain('"new":2');
    expect(screen.getByTestId('g7le-version-diff-hunk-0')).toBeInTheDocument();
    // add/remove 라인이 최소 1개씩
    const lines = screen.getAllByTestId(/g7le-version-diff-line-/);
    const kinds = lines.map((el) => el.getAttribute('data-kind'));
    expect(kinds).toContain('add');
    expect(kinds).toContain('remove');
  });

  it('동일 content → identical 안내', () => {
    const same = { components: [{ type: 'Div' }] };
    renderDiff({ oldContent: same, newContent: { components: [{ type: 'Div' }] } });
    expect(screen.getByTestId('g7le-version-diff-identical')).toBeInTheDocument();
  });

  it('과대 content → too_large 안내', () => {
    const big = { lines: Array.from({ length: DIFF_MAX_LINES + 10 }, (_, i) => `v${i}`) };
    renderDiff({ oldContent: {}, newContent: big });
    expect(screen.getByTestId('g7le-version-diff-too-large')).toBeInTheDocument();
  });

  it('back / close 콜백', () => {
    const { onBack, onClose } = renderDiff();
    fireEvent.click(screen.getByTestId('g7le-version-diff-back'));
    expect(onBack).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByTestId('g7le-version-diff-close'));
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
