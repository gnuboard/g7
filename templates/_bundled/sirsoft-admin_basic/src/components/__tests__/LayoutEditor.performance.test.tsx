import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, cleanup } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { readFileSync } from 'fs';
import { join } from 'path';
import { CodeEditor } from '../composite/CodeEditor';
import { LayoutFileList, LayoutFileItem } from '../composite/LayoutFileList';
import { VersionList, VersionItem } from '../composite/VersionList';

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
 * 픽스처 파일에서 테스트 데이터 로드
 */
const loadFixture = (filename: string): string => {
  const fixturePath = join(__dirname, '__fixtures__', filename);
  return readFileSync(fixturePath, 'utf-8');
};

/**
 * 대량의 파일 목록 생성
 */
const generateLargeFileList = (count: number): LayoutFileItem[] => {
  return Array.from({ length: count }, (_, i) => ({
    id: i + 1,
    name: `layout-file-${i + 1}.json`,
    updated_at: new Date(Date.now() - i * 3600000).toISOString(),
  }));
};

/**
 * 대량의 버전 목록 생성
 */
const generateLargeVersionList = (count: number): VersionItem[] => {
  return Array.from({ length: count }, (_, i) => ({
    id: i + 1,
    version: `v${Math.floor(i / 100)}.${Math.floor((i % 100) / 10)}.${i % 10}`,
    created_at: new Date(Date.now() - i * 3600000).toISOString(),
    changes_summary: {
      added: Math.floor(Math.random() * 20) + 1,
      removed: Math.floor(Math.random() * 10),
    },
  }));
};

describe('레이아웃 에디터 성능 테스트', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    cleanup();
  });

  describe('CodeEditor 대용량 JSON 처리', () => {
    it('1MB JSON 파일을 500ms 이내에 렌더링한다', async () => {
      const largeJSON = loadFixture('test-1mb.json');

      const startTime = performance.now();
      render(<CodeEditor value={largeJSON} />);
      const endTime = performance.now();

      const renderTime = endTime - startTime;
      expect(renderTime).toBeLessThan(500);
      expect(screen.getByTestId('monaco-editor')).toBeInTheDocument();
    });

    it('5MB JSON 파일을 2초 이내에 렌더링한다', async () => {
      const largeJSON = loadFixture('test-5mb.json');

      const startTime = performance.now();
      render(<CodeEditor value={largeJSON} />);
      const endTime = performance.now();

      const renderTime = endTime - startTime;
      expect(renderTime).toBeLessThan(2000);
      expect(screen.getByTestId('monaco-editor')).toBeInTheDocument();
    });

    it('10MB JSON 파일을 5초 이내에 렌더링한다', async () => {
      const largeJSON = loadFixture('test-10mb.json');

      const startTime = performance.now();
      render(<CodeEditor value={largeJSON} />);
      const endTime = performance.now();

      const renderTime = endTime - startTime;
      expect(renderTime).toBeLessThan(5000);
      expect(screen.getByTestId('monaco-editor')).toBeInTheDocument();
    });

    it('대용량 JSON 변경 시 onChange 콜백이 정상 작동한다', async () => {
      const largeJSON = loadFixture('test-1mb.json');
      const handleChange = vi.fn();
      const user = userEvent.setup();

      render(<CodeEditor value={largeJSON} onChange={handleChange} />);

      const editor = screen.getByTestId('editor-textarea');
      await user.type(editor, 'test');

      await waitFor(() => {
        expect(handleChange).toHaveBeenCalled();
      });
    });
  });

  describe('LayoutFileList 대량 데이터 렌더링', () => {
    it('100개 파일을 300ms 이내에 렌더링한다', () => {
      const files = generateLargeFileList(100);

      const startTime = performance.now();
      render(<LayoutFileList files={files} selectedId={1} onSelect={vi.fn()} />);
      const endTime = performance.now();

      const renderTime = endTime - startTime;
      expect(renderTime).toBeLessThan(300);
      expect(screen.getByText('layout-file-1.json')).toBeInTheDocument();
    });

    it('500개 파일을 1초 이내에 렌더링한다', () => {
      const files = generateLargeFileList(500);

      const startTime = performance.now();
      render(<LayoutFileList files={files} selectedId={1} onSelect={vi.fn()} />);
      const endTime = performance.now();

      const renderTime = endTime - startTime;
      expect(renderTime).toBeLessThan(1000);
      expect(screen.getByText('layout-file-1.json')).toBeInTheDocument();
    });

    it('대량 파일 목록에서 파일 선택이 빠르게 동작한다', async () => {
      const files = generateLargeFileList(500);
      const handleSelect = vi.fn();
      const user = userEvent.setup();

      render(<LayoutFileList files={files} selectedId={1} onSelect={handleSelect} />);

      const file = screen.getByText('layout-file-250.json');

      const startTime = performance.now();
      await user.click(file);
      const endTime = performance.now();

      const selectTime = endTime - startTime;
      expect(selectTime).toBeLessThan(500);
      expect(handleSelect).toHaveBeenCalledWith(250);
    });
  });

  describe('VersionList 대량 데이터 렌더링', () => {
    it('100개 버전을 300ms 이내에 렌더링한다', () => {
      const versions = generateLargeVersionList(100);

      const startTime = performance.now();
      render(<VersionList versions={versions} onSelect={vi.fn()} />);
      const endTime = performance.now();

      const renderTime = endTime - startTime;
      expect(renderTime).toBeLessThan(300);
      expect(screen.getByText(/버전 v0\.0\.0/)).toBeInTheDocument();
    });

    it('500개 버전을 1초 이내에 렌더링한다', () => {
      const versions = generateLargeVersionList(500);

      const startTime = performance.now();
      render(<VersionList versions={versions} onSelect={vi.fn()} />);
      const endTime = performance.now();

      const renderTime = endTime - startTime;
      expect(renderTime).toBeLessThan(1000);
      expect(screen.getByText(/버전 v0\.0\.0/)).toBeInTheDocument();
    });

    it('대량 버전 목록에서 버전 선택이 빠르게 동작한다', async () => {
      const versions = generateLargeVersionList(500);
      const handleSelect = vi.fn();
      const user = userEvent.setup();

      render(<VersionList versions={versions} selectedId={1} onSelect={handleSelect} />);

      const version = screen.getByText(/버전 v2\.5\.0/);

      const startTime = performance.now();
      await user.click(version);
      const endTime = performance.now();

      const selectTime = endTime - startTime;
      expect(selectTime).toBeLessThan(500);
      expect(handleSelect).toHaveBeenCalledWith(251); // ID는 1부터 시작하므로 251번째
    });
  });

  describe('빈번한 리렌더링 성능', () => {
    it('CodeEditor가 빠르게 리렌더링된다', () => {
      const { rerender } = render(<CodeEditor value="test1" />);

      const startTime = performance.now();
      for (let i = 0; i < 10; i++) {
        rerender(<CodeEditor value={`test${i}`} />);
      }
      const endTime = performance.now();

      const totalRerenderTime = endTime - startTime;
      expect(totalRerenderTime).toBeLessThan(100);
    });

    it('LayoutFileList가 선택 변경 시 빠르게 리렌더링된다', () => {
      const files = generateLargeFileList(100);
      const { rerender } = render(
        <LayoutFileList files={files} selectedId={1} onSelect={vi.fn()} />
      );

      const startTime = performance.now();
      for (let i = 1; i <= 10; i++) {
        rerender(
          <LayoutFileList files={files} selectedId={i} onSelect={vi.fn()} />
        );
      }
      const endTime = performance.now();

      const totalRerenderTime = endTime - startTime;
      expect(totalRerenderTime).toBeLessThan(500);
    });

    it('VersionList가 선택 변경 시 빠르게 리렌더링된다', () => {
      const versions = generateLargeVersionList(100);
      const { rerender } = render(
        <VersionList versions={versions} selectedId={1} onSelect={vi.fn()} />
      );

      const startTime = performance.now();
      for (let i = 1; i <= 10; i++) {
        rerender(
          <VersionList versions={versions} selectedId={i} onSelect={vi.fn()} />
        );
      }

      const endTime = performance.now();
      const rerenderTime = endTime - startTime;

      // CI 환경 및 테스트 환경에 따라 성능이 다를 수 있으므로 여유롭게 설정
      expect(rerenderTime).toBeLessThan(500);
    });
  });
});
