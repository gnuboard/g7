import React, { useState } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { ThreeColumnLayout } from '../layout/ThreeColumnLayout';
import { LayoutEditorHeader } from '../composite/LayoutEditorHeader';
import { LayoutFileList, LayoutFileItem } from '../composite/LayoutFileList';
import { CodeEditor } from '../composite/CodeEditor';
import { LayoutHistoryPanel } from '../composite/LayoutHistoryPanel';
import { VersionItem } from '../composite/VersionList';
import { Alert } from '../composite/Alert';

// Monaco Editor mock
vi.mock('@monaco-editor/react', () => ({
  default: ({ value, onChange, onMount }: any) => {
    React.useEffect(() => {
      if (onMount) {
        const mockEditor = {
          updateOptions: vi.fn(),
          getValue: () => value,
        };
        const mockMonaco = {
          languages: {
            json: {
              jsonDefaults: {
                setDiagnosticsOptions: vi.fn(),
              },
            },
          },
        };
        onMount(mockEditor, mockMonaco);
      }
    }, [onMount]);

    return (
      <div data-testid="monaco-editor">
        <textarea
          data-testid="editor-textarea"
          value={value}
          onChange={(e) => onChange?.(e.target.value)}
        />
      </div>
    );
  },
}));

/**
 * 통합 레이아웃 에디터 컴포넌트
 * 실제 사용 시나리오를 시뮬레이션하기 위한 통합 컴포넌트
 */
const IntegratedLayoutEditor: React.FC = () => {
  const [files] = useState<LayoutFileItem[]>([
    { id: 1, name: 'main-layout.json', updated_at: '2024-01-01T12:00:00Z' },
    { id: 2, name: 'admin-layout.json', updated_at: '2024-01-02T15:30:00Z' },
    { id: 3, name: 'dashboard.json', updated_at: '2024-01-03T09:15:00Z' },
  ]);

  const [versions] = useState<VersionItem[]>([
    { id: 1, version: 'v1.0.0', created_at: '2024-01-01T10:00:00Z', changes_summary: { added: 5, removed: 2 } },
    { id: 2, version: 'v1.1.0', created_at: '2024-01-02T11:00:00Z', changes_summary: { added: 3, removed: 1 } },
    { id: 3, version: 'v2.0.0', created_at: '2024-01-03T08:00:00Z', changes_summary: { added: 10, removed: 5 } },
  ]);

  const [selectedFileId, setSelectedFileId] = useState<string | number>(1);
  const [editorContent, setEditorContent] = useState<string>(
    '{"name": "main-layout", "version": "1.0.0"}'
  );
  const [showAlert, setShowAlert] = useState<boolean>(false);
  const [alertMessage, setAlertMessage] = useState<string>('');

  const handleSave = () => {
    setShowAlert(true);
    setAlertMessage('레이아웃이 저장되었습니다.');
    setTimeout(() => setShowAlert(false), 3000);
  };

  const handleFileSelect = (fileId: string | number) => {
    setSelectedFileId(fileId);
    const file = files.find((f) => f.id === fileId);
    if (file) {
      setEditorContent(`{"name": "${file.name}", "id": ${fileId}}`);
    }
  };

  const handleRestore = (versionId: string | number) => {
    const version = versions.find((v) => v.id === versionId);
    if (version) {
      setEditorContent(`{"restored": true, "version": "${version.version}"}`);
      setShowAlert(true);
      setAlertMessage(`버전 ${version.version}으로 복원되었습니다.`);
      setTimeout(() => setShowAlert(false), 3000);
    }
  };

  return (
    <div className="h-screen flex flex-col">
      {/* 헤더 */}
      <LayoutEditorHeader
        layoutName="레이아웃 편집기"
        onBack={() => console.log('Back')}
        onPreview={() => console.log('Preview')}
        onSave={handleSave}
      />

      {/* 알림 */}
      {showAlert && (
        <Alert
          type="success"
          message={alertMessage}
          dismissible
          onDismiss={() => setShowAlert(false)}
        />
      )}

      {/* 3단 레이아웃 */}
      <ThreeColumnLayout
        leftWidth="250px"
        rightWidth="300px"
        leftSlot={
          <LayoutFileList
            files={files}
            selectedId={selectedFileId}
            onSelect={handleFileSelect}
          />
        }
        centerSlot={
          <CodeEditor
            value={editorContent}
            onChange={(value) => setEditorContent(value || '')}
            language="json"
          />
        }
        rightSlot={
          <LayoutHistoryPanel
            layoutId={selectedFileId}
            versions={versions}
            onRestore={handleRestore}
          />
        }
      />
    </div>
  );
};

describe('레이아웃 에디터 통합 테스트', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('전체 플로우 테스트', () => {
    it('파일 선택 → 편집 → 저장 시나리오가 정상 작동한다', async () => {
      const user = userEvent.setup();
      render(<IntegratedLayoutEditor />);

      // 1. 초기 렌더링 확인
      expect(screen.getByText('레이아웃 편집기')).toBeInTheDocument();
      expect(screen.getByText('main-layout.json')).toBeInTheDocument();

      // 2. 파일 선택
      const secondFile = screen.getByText('admin-layout.json');
      await user.click(secondFile);

      // 3. 에디터 내용 변경 확인
      await waitFor(() => {
        const editor = screen.getByTestId('editor-textarea');
        const value = (editor as HTMLTextAreaElement).value;
        expect(value).toContain('admin-layout.json');
        expect(value).toContain('2');
      });

      // 4. 저장 버튼 클릭
      const saveButton = screen.getByRole('button', { name: /저장/i });
      await user.click(saveButton);

      // 5. 성공 알림 확인
      await waitFor(() => {
        expect(screen.getByText('레이아웃이 저장되었습니다.')).toBeInTheDocument();
      });
    });

    it('버전 선택 → 복원 시나리오가 정상 작동한다', async () => {
      const user = userEvent.setup();
      render(<IntegratedLayoutEditor />);

      // 1. 버전 목록에서 버전 선택
      const versionItem = screen.getByText(/버전 v2\.0\.0/);
      await user.click(versionItem);

      // 2. 복원 버튼 클릭
      const restoreButton = screen.getByRole('button', { name: /복원/i });
      await user.click(restoreButton);

      // 3. 복원 확인
      await waitFor(() => {
        expect(screen.getByText(/버전 v2.0.0으로 복원되었습니다/)).toBeInTheDocument();
      });

      // 4. 에디터 내용이 복원된 버전으로 변경되었는지 확인
      await waitFor(() => {
        const editor = screen.getByTestId('editor-textarea');
        const value = (editor as HTMLTextAreaElement).value;
        expect(value).toContain('restored');
        expect(value).toContain('v2.0.0');
      });
    });

    it('파일 변경 → 버전 복원 → 저장 복합 시나리오가 정상 작동한다', async () => {
      const user = userEvent.setup();
      render(<IntegratedLayoutEditor />);

      // 1. 다른 파일 선택
      const thirdFile = screen.getByText('dashboard.json');
      await user.click(thirdFile);

      await waitFor(() => {
        const editor = screen.getByTestId('editor-textarea');
        const value = (editor as HTMLTextAreaElement).value;
        expect(value).toContain('dashboard.json');
        expect(value).toContain('3');
      });

      // 2. 버전 선택
      const versionItem = screen.getByText(/버전 v1\.1\.0/);
      await user.click(versionItem);

      // 3. 복원
      const restoreButton = screen.getByRole('button', { name: /복원/i });
      await user.click(restoreButton);

      await waitFor(() => {
        expect(screen.getByText(/버전 v1.1.0으로 복원되었습니다/)).toBeInTheDocument();
      });

      // 4. 알림 사라질 때까지 대기
      await waitFor(
        () => {
          expect(screen.queryByText(/버전 v1.1.0으로 복원되었습니다/)).not.toBeInTheDocument();
        },
        { timeout: 4000 }
      );

      // 5. 저장
      const saveButton = screen.getByRole('button', { name: /저장/i });
      await user.click(saveButton);

      await waitFor(() => {
        expect(screen.getByText('레이아웃이 저장되었습니다.')).toBeInTheDocument();
      });
    });
  });

  describe('컴포넌트 간 상호작용 테스트', () => {
    it('파일 선택 시 에디터 내용이 변경된다', async () => {
      const user = userEvent.setup();
      render(<IntegratedLayoutEditor />);

      const files = ['main-layout.json', 'admin-layout.json', 'dashboard.json'];

      for (let i = 0; i < files.length; i++) {
        const fileElement = screen.getByText(files[i]);
        await user.click(fileElement);

        await waitFor(() => {
          const editor = screen.getByTestId('editor-textarea');
          const value = (editor as HTMLTextAreaElement).value;
          expect(value).toContain(files[i]);
          expect(value).toContain(String(i + 1));
        });
      }
    });

    it('버전 선택 없이 복원 버튼은 비활성화된다', () => {
      render(<IntegratedLayoutEditor />);

      const restoreButton = screen.getByRole('button', { name: /복원/i });
      expect(restoreButton).toBeDisabled();
    });

    it('버전 선택 시 복원 버튼이 활성화된다', async () => {
      const user = userEvent.setup();
      render(<IntegratedLayoutEditor />);

      const restoreButton = screen.getByRole('button', { name: /복원/i });
      expect(restoreButton).toBeDisabled();

      const versionItem = screen.getByText(/버전 v1\.0\.0/);
      await user.click(versionItem);

      expect(restoreButton).not.toBeDisabled();
    });
  });

  describe('접근성 테스트', () => {
    it('전체 레이아웃이 적절한 ARIA 속성을 가진다', () => {
      render(<IntegratedLayoutEditor />);

      const historyPanel = screen.getByRole('region', { name: '레이아웃 히스토리 패널' });
      expect(historyPanel).toBeInTheDocument();

      const fileList = screen.getByRole('listbox', { name: '레이아웃 파일 목록' });
      expect(fileList).toBeInTheDocument();
    });

    it('LayoutEditorHeader가 적절한 button 역할을 가진다', () => {
      render(
        <LayoutEditorHeader
          layoutName="테스트 헤더"
          onBack={vi.fn()}
          onPreview={vi.fn()}
          onSave={vi.fn()}
        />
      );

      const saveButton = screen.getByRole('button', { name: /저장/i });
      expect(saveButton).toBeInTheDocument();

      const backButton = screen.getByRole('button', { name: /뒤로가기/i });
      expect(backButton).toBeInTheDocument();

      const previewButton = screen.getByRole('button', { name: /미리보기/i });
      expect(previewButton).toBeInTheDocument();
    });

    it('LayoutFileList가 listbox 역할과 option을 가진다', () => {
      const files: LayoutFileItem[] = [
        { id: 1, name: 'file1.json', updated_at: '2024-01-01T10:00:00Z' },
        { id: 2, name: 'file2.json', updated_at: '2024-01-02T11:00:00Z' },
      ];

      render(<LayoutFileList files={files} selectedId={1} onSelect={vi.fn()} />);

      const listbox = screen.getByRole('listbox');
      expect(listbox).toBeInTheDocument();

      const options = screen.getAllByRole('option');
      expect(options).toHaveLength(2);
      expect(options[0]).toHaveAttribute('aria-selected', 'true');
    });

    it('LayoutHistoryPanel이 region 역할을 가진다', () => {
      const versions: VersionItem[] = [
        { id: 1, version: 'v1.0.0', created_at: '2024-01-01T10:00:00Z', changes_summary: { added: 5, removed: 2 } },
      ];

      render(<LayoutHistoryPanel layoutId={1} versions={versions} onRestore={vi.fn()} />);

      const panel = screen.getByRole('region', { name: '레이아웃 히스토리 패널' });
      expect(panel).toBeInTheDocument();
    });

    it('Alert 컴포넌트가 alert 역할을 가진다', () => {
      render(
        <Alert
          type="success"
          message="테스트 메시지"
          dismissible
          onDismiss={vi.fn()}
        />
      );

      const alert = screen.getByRole('alert');
      expect(alert).toBeInTheDocument();
      expect(alert).toHaveTextContent('테스트 메시지');
    });
  });

  describe('키보드 네비게이션 테스트', () => {
    it('Enter 키로 파일을 선택할 수 있다', async () => {
      const user = userEvent.setup();
      render(<IntegratedLayoutEditor />);

      const secondFile = screen.getByText('admin-layout.json').closest('li');
      if (secondFile) {
        secondFile.focus();
        await user.keyboard('{Enter}');

        await waitFor(() => {
          const editor = screen.getByTestId('editor-textarea');
          const value = (editor as HTMLTextAreaElement).value;
          expect(value).toContain('admin-layout.json');
          expect(value).toContain('2');
        });
      }
    });
  });

  describe('UI 상태 테스트', () => {
    it('파일 선택 시 선택된 항목이 하이라이트된다', async () => {
      const user = userEvent.setup();
      render(<IntegratedLayoutEditor />);

      const secondFile = screen.getByText('admin-layout.json').closest('li');
      await user.click(secondFile!);

      await waitFor(() => {
        expect(secondFile).toHaveClass('bg-blue-100');
      });
    });

    it('버전 선택 시 선택된 항목이 하이라이트된다', async () => {
      const user = userEvent.setup();
      render(<IntegratedLayoutEditor />);

      const versionItem = screen.getByText(/버전 v2\.0\.0/).closest('li');
      await user.click(versionItem!);

      await waitFor(() => {
        expect(versionItem).toHaveClass('bg-blue-50');
      });
    });

    it('저장 후 알림이 표시되고 자동으로 사라진다', async () => {
      const user = userEvent.setup();
      render(<IntegratedLayoutEditor />);

      const saveButton = screen.getByRole('button', { name: /저장/i });
      await user.click(saveButton);

      // 알림 표시 확인
      await waitFor(() => {
        expect(screen.getByText('레이아웃이 저장되었습니다.')).toBeInTheDocument();
      });

      // 알림 자동 사라짐 확인
      await waitFor(
        () => {
          expect(screen.queryByText('레이아웃이 저장되었습니다.')).not.toBeInTheDocument();
        },
        { timeout: 4000 }
      );
    });
  });

  describe('엣지 케이스 테스트', () => {
    it('빈 파일 목록을 처리할 수 있다', () => {
      render(
        <LayoutFileList
          files={[]}
          onSelect={vi.fn()}
        />
      );

      expect(screen.getByText('파일이 없습니다.')).toBeInTheDocument();
    });

    it('빈 버전 목록을 처리할 수 있다', () => {
      render(
        <LayoutHistoryPanel
          layoutId={1}
          versions={[]}
          onRestore={vi.fn()}
        />
      );

      expect(screen.getByText('버전이 없습니다.')).toBeInTheDocument();
    });

    it('잘못된 날짜 형식을 처리할 수 있다', () => {
      const files: LayoutFileItem[] = [
        { id: 1, name: 'file1.json', updated_at: 'invalid-date' },
      ];

      render(<LayoutFileList files={files} selectedId={1} onSelect={vi.fn()} />);

      expect(screen.getByText('invalid-date')).toBeInTheDocument();
    });
  });
});
