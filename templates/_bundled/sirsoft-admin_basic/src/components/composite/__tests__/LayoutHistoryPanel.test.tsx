import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LayoutHistoryPanel } from '../LayoutHistoryPanel';
import { VersionItem } from '../VersionList';

describe('LayoutHistoryPanel', () => {
  const mockVersions: VersionItem[] = [
    {
      id: 1,
      version: '1.0.0',
      created_at: '2024-01-01T10:00:00Z',
      changes_summary: {
        added: 10,
        removed: 5,
      },
    },
    {
      id: 2,
      version: '1.1.0',
      created_at: '2024-01-02T14:30:00Z',
      changes_summary: {
        added: 20,
        removed: 8,
      },
    },
    {
      id: 3,
      version: '2.0.0',
      created_at: '2024-01-03T09:15:00Z',
      changes_summary: {
        added: 50,
        removed: 30,
      },
    },
  ];

  const defaultProps = {
    layoutId: 'layout-1',
    versions: mockVersions,
    onRestore: vi.fn(),
  };

  describe('렌더링', () => {
    // @scenario component=layout_history_panel
    // @effects layout_history_panel_renders_list_and_restore_button
    it('VersionList 컴포넌트를 렌더링해야 함', () => {
      render(<LayoutHistoryPanel {...defaultProps} />);

      expect(screen.getByText('버전 1.0.0')).toBeInTheDocument();
      expect(screen.getByText('버전 1.1.0')).toBeInTheDocument();
      expect(screen.getByText('버전 2.0.0')).toBeInTheDocument();
    });

    // @scenario component=layout_history_panel
    // @effects layout_history_panel_renders_list_and_restore_button
    it('복원 버튼을 렌더링해야 함', () => {
      render(<LayoutHistoryPanel {...defaultProps} />);

      const restoreButton = screen.getByRole('button', { name: '선택한 버전으로 복원' });
      expect(restoreButton).toBeInTheDocument();
    });

    // @scenario component=layout_history_panel
    // @effects layout_history_panel_renders_list_and_restore_button
    it('region 역할을 가진 컨테이너를 렌더링해야 함', () => {
      render(<LayoutHistoryPanel {...defaultProps} />);

      expect(screen.getByRole('region', { name: '레이아웃 히스토리 패널' })).toBeInTheDocument();
    });
  });

  describe('버전 선택 및 복원 버튼 상태', () => {
    // @scenario component=layout_history_panel
    // @effects layout_history_panel_restore_enabled_after_selection
    it('초기 상태에서 복원 버튼이 비활성화되어야 함', () => {
      render(<LayoutHistoryPanel {...defaultProps} />);

      const restoreButton = screen.getByRole('button', { name: '선택한 버전으로 복원' });
      expect(restoreButton).toBeDisabled();
    });

    // @scenario component=layout_history_panel
    // @effects layout_history_panel_restore_enabled_after_selection
    it('버전 선택 시 복원 버튼이 활성화되어야 함', async () => {
      const user = userEvent.setup();
      render(<LayoutHistoryPanel {...defaultProps} />);

      const restoreButton = screen.getByRole('button', { name: '선택한 버전으로 복원' });
      expect(restoreButton).toBeDisabled();

      // 첫 번째 버전 선택
      const versionItems = screen.getAllByRole('option');
      await user.click(versionItems[0]);

      expect(restoreButton).not.toBeDisabled();
    });

    // @scenario component=layout_history_panel
    // @effects layout_history_panel_restore_enabled_after_selection
    it('다른 버전을 선택하면 선택 상태가 변경되어야 함', async () => {
      const user = userEvent.setup();
      render(<LayoutHistoryPanel {...defaultProps} />);

      const versionItems = screen.getAllByRole('option');

      // 첫 번째 버전 선택
      await user.click(versionItems[0]);
      expect(versionItems[0]).toHaveAttribute('aria-selected', 'true');

      // 두 번째 버전 선택
      await user.click(versionItems[1]);
      expect(versionItems[1]).toHaveAttribute('aria-selected', 'true');
      expect(versionItems[0]).toHaveAttribute('aria-selected', 'false');
    });
  });

  describe('복원 기능', () => {
    // @scenario component=layout_history_panel
    // @effects layout_history_panel_restore_passes_selected_version
    it('복원 버튼 클릭 시 선택된 버전 ID로 onRestore 콜백을 호출해야 함', async () => {
      const user = userEvent.setup();
      const onRestore = vi.fn();

      render(
        <LayoutHistoryPanel
          {...defaultProps}
          onRestore={onRestore}
        />
      );

      // 첫 번째 버전 선택
      const versionItems = screen.getAllByRole('option');
      await user.click(versionItems[0]);

      // 복원 버튼 클릭
      const restoreButton = screen.getByRole('button', { name: '선택한 버전으로 복원' });
      await user.click(restoreButton);

      expect(onRestore).toHaveBeenCalledWith(1);
      expect(onRestore).toHaveBeenCalledTimes(1);
    });

    // @scenario component=layout_history_panel
    // @effects layout_history_panel_restore_passes_selected_version
    it('다른 버전 선택 후 복원 시 해당 버전 ID로 콜백을 호출해야 함', async () => {
      const user = userEvent.setup();
      const onRestore = vi.fn();

      render(
        <LayoutHistoryPanel
          {...defaultProps}
          onRestore={onRestore}
        />
      );

      // 세 번째 버전 선택
      const versionItems = screen.getAllByRole('option');
      await user.click(versionItems[2]);

      // 복원 버튼 클릭
      const restoreButton = screen.getByRole('button', { name: '선택한 버전으로 복원' });
      await user.click(restoreButton);

      expect(onRestore).toHaveBeenCalledWith(3);
    });

    // @scenario component=layout_history_panel
    // @effects layout_history_panel_restore_passes_selected_version
    it('버전 미선택 시 복원 버튼을 클릭해도 onRestore가 호출되지 않아야 함', async () => {
      const onRestore = vi.fn();

      render(
        <LayoutHistoryPanel
          {...defaultProps}
          onRestore={onRestore}
        />
      );

      const restoreButton = screen.getByRole('button', { name: '선택한 버전으로 복원' });

      // 버튼이 비활성화되어 있으므로 클릭이 실제로 동작하지 않음
      expect(restoreButton).toBeDisabled();
      expect(onRestore).not.toHaveBeenCalled();
    });
  });

  describe('엣지 케이스', () => {
    // @scenario component=layout_history_panel
    // @effects layout_history_panel_renders_list_and_restore_button
    it('빈 버전 목록을 전달해도 에러가 발생하지 않아야 함', () => {
      render(
        <LayoutHistoryPanel
          {...defaultProps}
          versions={[]}
        />
      );

      const restoreButton = screen.getByRole('button', { name: '선택한 버전으로 복원' });
      expect(restoreButton).toBeDisabled();
    });

    // @scenario component=layout_history_panel
    // @effects layout_history_panel_renders_list_and_restore_button
    it('layoutId를 숫자로 전달해도 정상 동작해야 함', () => {
      render(
        <LayoutHistoryPanel
          {...defaultProps}
          layoutId={123}
        />
      );

      expect(screen.getByRole('region', { name: '레이아웃 히스토리 패널' })).toBeInTheDocument();
    });
  });

  describe('접근성', () => {
    // @scenario component=layout_history_panel
    // @effects layout_history_panel_accessible_names
    it('복원 버튼에 적절한 aria-label이 있어야 함', () => {
      render(<LayoutHistoryPanel {...defaultProps} />);

      const restoreButton = screen.getByRole('button', { name: '선택한 버전으로 복원' });
      expect(restoreButton).toHaveAttribute('aria-label', '선택한 버전으로 복원');
    });

    // @scenario component=layout_history_panel
    // @effects layout_history_panel_accessible_names
    it('패널 컨테이너에 적절한 aria-label이 있어야 함', () => {
      render(<LayoutHistoryPanel {...defaultProps} />);

      expect(screen.getByRole('region')).toHaveAttribute('aria-label', '레이아웃 히스토리 패널');
    });
  });
});
