import React from 'react';
import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest';
import { render, screen, waitFor, cleanup } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { readFileSync, writeFileSync, existsSync, mkdirSync, unlinkSync, rmdirSync } from 'fs';
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

const FIXTURES_DIR = join(__dirname, '__fixtures__');
const FIXTURE_FILES = [
  { size: 1, filename: 'test-1mb.json' },
  { size: 5, filename: 'test-5mb.json' },
  { size: 10, filename: 'test-10mb.json' },
];

/**
 * 대용량 JSON 데이터 생성 (generate-test-fixtures.ts와 동일 로직)
 */
const generateLargeJSON = (sizeInMB: number): string => {
  const targetSize = sizeInMB * 1024 * 1024;
  const baseObject = {
    id: 1,
    name: 'test-layout',
    version: '1.0.0',
    components: [] as any[],
  };

  const sampleComponent = {
    id: 'component-0',
    type: 'div',
    className: 'container flex flex-col items-center justify-center p-4 m-2',
    children: [
      { id: 'child-0-1', type: 'h1', className: 'text-2xl font-bold', content: 'Title Component' },
      { id: 'child-0-2', type: 'p', className: 'text-base', content: 'Description text for the component' },
    ],
    metadata: {
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
      author: 'system',
      tags: ['component', 'layout', 'container', 'responsive'],
    },
  };

  const sampleJSON = JSON.stringify({ components: [sampleComponent] }, null, 2);
  const componentSize = sampleJSON.length - JSON.stringify({ components: [] }, null, 2).length;
  const baseSize = JSON.stringify(baseObject).length;
  const componentsNeeded = Math.ceil((targetSize - baseSize) / componentSize * 1.1);

  for (let i = 0; i < componentsNeeded; i++) {
    baseObject.components.push({
      id: `component-${i}`,
      type: 'div',
      className: 'container flex flex-col items-center justify-center p-4 m-2',
      children: [
        { id: `child-${i}-1`, type: 'h1', className: 'text-2xl font-bold', content: `Title Component ${i}` },
        { id: `child-${i}-2`, type: 'p', className: 'text-base', content: `Description text for the component ${i}` },
      ],
      metadata: {
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
        author: 'system',
        tags: ['component', 'layout', 'container', 'responsive'],
      },
    });
  }

  return JSON.stringify(baseObject, null, 2);
};

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
  beforeAll(() => {
    // fixture 디렉토리 생성
    if (!existsSync(FIXTURES_DIR)) {
      mkdirSync(FIXTURES_DIR, { recursive: true });
    }
    // fixture 파일 생성
    for (const { size, filename } of FIXTURE_FILES) {
      const filePath = join(FIXTURES_DIR, filename);
      if (!existsSync(filePath)) {
        const data = generateLargeJSON(size);
        writeFileSync(filePath, data, 'utf-8');
      }
    }
  });

  afterAll(() => {
    // fixture 파일 삭제
    for (const { filename } of FIXTURE_FILES) {
      const filePath = join(FIXTURES_DIR, filename);
      if (existsSync(filePath)) {
        unlinkSync(filePath);
      }
    }
    // fixture 디렉토리 삭제 (비어있을 때만)
    if (existsSync(FIXTURES_DIR)) {
      try { rmdirSync(FIXTURES_DIR); } catch { /* 디렉토리가 비어있지 않으면 무시 */ }
    }
  });

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
      // 안정 onSelect — 재렌더 간 동일 참조여야 React.memo 가 효과 발휘
      const stableOnSelect = vi.fn();
      const { rerender } = render(
        <VersionList versions={versions} selectedId={1} onSelect={stableOnSelect} />
      );

      const startTime = performance.now();
      for (let i = 1; i <= 10; i++) {
        rerender(
          <VersionList versions={versions} selectedId={i} onSelect={stableOnSelect} />
        );
      }

      const endTime = performance.now();
      const rerenderTime = endTime - startTime;

      // VersionListItem 이 React.memo 적용 + 안정 onSelect → 선택 변경 시
      // 영향받는 2개 항목(이전 선택/새 선택)만 재렌더. 100 항목 풀 렌더 회피.
      // 임계 300ms 로 강화 (이전 1000ms 완화 → memoization 회귀 감지 효용 회복).
      expect(rerenderTime).toBeLessThan(300);
    });
  });
});
