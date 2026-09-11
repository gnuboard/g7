import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Modal, MODAL_SIZE_WIDTHS } from '../Modal';

describe('Modal', () => {
  const mockProps = {
    isOpen: true,
    onClose: vi.fn(),
    title: '테스트 모달',
    children: <div>모달 내용</div>,
  };

  it('컴포넌트가 렌더링됨', () => {
    render(<Modal {...mockProps} />);

    expect(screen.getByText('테스트 모달')).toBeInTheDocument();
    expect(screen.getByText('모달 내용')).toBeInTheDocument();
  });

  it('isOpen이 false일 때 렌더링되지 않음', () => {
    render(<Modal {...mockProps} isOpen={false} />);

    expect(screen.queryByText('테스트 모달')).not.toBeInTheDocument();
  });

  it('닫기 버튼 클릭 시 onClose가 호출됨', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();

    render(<Modal {...mockProps} onClose={onClose} />);

    const closeButton = screen.getByLabelText('모달 닫기');
    await user.click(closeButton);

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('오버레이 클릭 시 onClose가 호출됨', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();

    render(<Modal {...mockProps} onClose={onClose} />);

    // 오버레이를 찾아서 클릭
    const overlay = screen.getByRole('dialog').parentElement;
    if (overlay) {
      await user.click(overlay);
    }

    expect(onClose).toHaveBeenCalled();
  });

  it('모달 내용 클릭 시 onClose가 호출되지 않음', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();

    render(<Modal {...mockProps} onClose={onClose} />);

    const modalContent = screen.getByRole('dialog');
    await user.click(modalContent);

    // 모달 내용 클릭 시 닫히지 않아야 함
    expect(onClose).not.toHaveBeenCalled();
  });

  it('ESC 키를 누르면 onClose가 호출됨', () => {
    const onClose = vi.fn();

    render(<Modal {...mockProps} onClose={onClose} />);

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('width prop이 적용됨', () => {
    render(<Modal {...mockProps} width="800px" />);

    const modal = screen.getByRole('dialog');
    expect(modal).toHaveStyle({ width: '800px' });
  });

  /**
   * size prop — 소스 코퍼스가 216건 쓰고 있었으나 컴포넌트가 받지 않아 전량 소멸하던 표면.
   *
   * 이관 12차 검증에서 검출: `<modal size="sm">` 216건 / 199파일이 지정 크기와 무관하게
   * 기본 500px 로 렌더됐다. 아래 케이스가 그 회귀를 잠근다.
   * `.modal-container` 는 CSS 로 `max-w-[calc(100vw-2rem)]` 를 이미 갖고 있어 모바일 넘침은
   * 스타일 계층이 막는다 — 그래서 매핑은 단순 px 로 둔다.
   */
  describe('size prop', () => {
    it.each([
      ['sm', '400px'],
      ['small', '400px'],
      ['md', '500px'],
      ['medium', '500px'],
      ['lg', '720px'],
      ['large', '720px'],
      ['xl', '900px'],
      ['2xl', '1120px'],
    ])('size="%s" 는 width %s 로 해석됨', (size, expected) => {
      render(<Modal {...mockProps} size={size as any} />);

      expect(screen.getByRole('dialog')).toHaveStyle({ width: expected });
    });

    it('width 를 명시하면 size 보다 우선한다 (기존 width 사용처 무영향)', () => {
      render(<Modal {...mockProps} size="sm" width="920px" />);

      expect(screen.getByRole('dialog')).toHaveStyle({ width: '920px' });
    });

    it('size 가 없으면 종전 기본값 500px 을 유지한다 (미지정 모달 회귀 방어)', () => {
      render(<Modal {...mockProps} />);

      expect(screen.getByRole('dialog')).toHaveStyle({ width: '500px' });
    });

    it('알 수 없는 size 값은 기본값으로 떨어진다 (작성 오타가 화면을 깨뜨리지 않음)', () => {
      render(<Modal {...mockProps} size={'huge' as any} />);

      expect(screen.getByRole('dialog')).toHaveStyle({ width: '500px' });
    });

    /**
     * 코퍼스 가드 — 번들 전체가 쓰는 `size` 값이 매핑에 전부 있는지.
     *
     * 두 템플릿 `Modal` 이 같은 매핑을 공유하므로 스캔은 여기 한 곳에서 전 코퍼스를 훑는다.
     * 매핑에 없는 값이 작성되면 그 모달만 조용히 기본 500px 로 떨어지는데, 12차 이전에는
     * **전량이** 그 상태였고 아무도 몰랐다. 새 오타가 같은 방식으로 묻히지 않도록 잠근다.
     */
    it('번들 레이아웃이 쓰는 size 값이 전부 매핑에 있다', () => {
      const repoRoot = path.resolve(__dirname, '../../../../../../..');
      const roots = [
        'templates/_bundled',
        'modules/_bundled',
        'plugins/_bundled',
      ].map((r) => path.join(repoRoot, r));

      const found = new Map<string, string>(); // size 값 → 처음 발견한 파일
      const visit = (node: any, file: string): void => {
        if (Array.isArray(node)) return node.forEach((n) => visit(n, file));
        if (!node || typeof node !== 'object') return;
        if (node.name === 'Modal' && node.props && typeof node.props.size === 'string') {
          if (!found.has(node.props.size)) found.set(node.props.size, file);
        }
        Object.values(node).forEach((v) => visit(v, file));
      };

      const walk = (dir: string): void => {
        let entries: fs.Dirent[];
        try {
          entries = fs.readdirSync(dir, { withFileTypes: true });
        } catch {
          return;
        }
        for (const e of entries) {
          const p = path.join(dir, e.name);
          if (e.isDirectory()) {
            if (e.name === 'node_modules' || e.name === 'vendor' || e.name === 'dist') continue;
            walk(p);
          } else if (e.name.endsWith('.json') && /[\\/](layouts|extensions)[\\/]/.test(p)) {
            try {
              visit(JSON.parse(fs.readFileSync(p, 'utf8')), path.relative(repoRoot, p));
            } catch {
              /* 레이아웃이 아닌 JSON 은 건너뛴다 */
            }
          }
        }
      };
      roots.forEach(walk);

      // 코퍼스를 실제로 훑었는지부터 확인 — 0건이면 이 가드가 조용히 무력해진 것이다.
      expect(found.size).toBeGreaterThan(0);

      const unknown = [...found.entries()].filter(([size]) => !(size in MODAL_SIZE_WIDTHS));
      expect(unknown.map(([size, file]) => `${size} (${file})`)).toEqual([]);
    });
  });

  it('className prop이 적용됨', () => {
    render(<Modal {...mockProps} className="custom-modal" />);

    const modal = screen.getByRole('dialog');
    expect(modal).toHaveClass('custom-modal');
  });

  it('title이 없어도 렌더링됨', () => {
    const { title, ...propsWithoutTitle } = mockProps;

    render(<Modal {...propsWithoutTitle} />);

    expect(screen.getByText('모달 내용')).toBeInTheDocument();
  });

  it('모달이 열릴 때 body 스크롤이 방지됨', () => {
    render(<Modal {...mockProps} />);

    expect(document.body.style.overflow).toBe('hidden');
  });

  it('모달이 닫힐 때 body 스크롤이 복원됨', () => {
    const { rerender } = render(<Modal {...mockProps} />);

    expect(document.body.style.overflow).toBe('hidden');

    rerender(<Modal {...mockProps} isOpen={false} />);

    expect(document.body.style.overflow).toBe('');
  });

  describe('titlePrefix/titleSuffix', () => {
    it('titlePrefix 없이도 기존 동작이 유지됨', () => {
      render(<Modal {...mockProps} />);

      expect(screen.getByText('테스트 모달')).toBeInTheDocument();
    });

    it('titleSuffix 없이도 기존 동작이 유지됨', () => {
      render(<Modal {...mockProps} />);

      expect(screen.getByText('테스트 모달')).toBeInTheDocument();
    });

    it('titlePrefix와 titleSuffix가 빈 배열일 때 기존 동작이 유지됨', () => {
      render(<Modal {...mockProps} titlePrefix={[]} titleSuffix={[]} />);

      expect(screen.getByText('테스트 모달')).toBeInTheDocument();
    });

    it('G7Core가 없을 때 titlePrefix/titleSuffix가 무시됨 (에러 없이 렌더링)', () => {
      // G7Core가 window에 없는 상태에서 테스트
      const originalG7Core = (window as any).G7Core;
      delete (window as any).G7Core;

      const mockTitlePrefix = [{ type: 'basic', name: 'Div', props: {} }];

      // 에러 없이 렌더링되어야 함
      expect(() => {
        render(<Modal {...mockProps} titlePrefix={mockTitlePrefix} />);
      }).not.toThrow();

      expect(screen.getByText('테스트 모달')).toBeInTheDocument();

      // 원복
      (window as any).G7Core = originalG7Core;
    });

    it('title 없이 titlePrefix만 있어도 렌더링됨', () => {
      const { title, ...propsWithoutTitle } = mockProps;

      render(<Modal {...propsWithoutTitle} titlePrefix={[]} />);

      expect(screen.getByText('모달 내용')).toBeInTheDocument();
    });
  });

  /**
   * footer — S16 F5 회귀 게이트.
   *
   * 이 prop 이 없던 동안 12개 모달의 취소/확인 버튼이 통째로 렌더되지 않았다. 레이아웃은
   * 버튼을 노드 레벨 `footer` 에 적어 두었는데 `Modal` 이 그것을 읽지 않아, 사용자에게는
   * 헤더의 X 만 남고 삭제·상태변경 확인이 불가능했다.
   */
  describe('footer', () => {
    /** DynamicRenderer 를 흉내내는 최소 G7Core — 넘어온 componentDef 의 text 를 그대로 그린다. */
    const installFakeG7Core = () => {
      const original = (window as any).G7Core;

      (window as any).G7Core = {
        getDynamicRenderer: () => ({ componentDef }: any) => <button>{componentDef.text}</button>,
        getComponentRegistry: () => ({}),
        getDataBindingEngine: () => ({}),
        getTranslationEngine: () => ({}),
        getActionDispatcher: () => ({}),
      };

      return () => {
        (window as any).G7Core = original;
      };
    };

    it('footer 컴포넌트가 렌더링됨', () => {
      const restore = installFakeG7Core();

      render(
        <Modal
          {...mockProps}
          footer={[
            { type: 'basic', name: 'Button', text: '취소' },
            { type: 'basic', name: 'Button', text: '삭제' },
          ]}
        />
      );

      expect(screen.getByText('취소')).toBeInTheDocument();
      expect(screen.getByText('삭제')).toBeInTheDocument();

      restore();
    });

    it('footer 는 본문 스크롤 영역 밖에 렌더링됨', () => {
      const restore = installFakeG7Core();

      render(<Modal {...mockProps} footer={[{ type: 'basic', name: 'Button', text: '확인' }]} />);

      // 본문(.modal-body-content) 안에 있으면 내용이 길 때 버튼이 함께 스크롤되어 밀린다.
      const body = document.querySelector('.modal-body-content');
      expect(body).not.toBeNull();
      expect(body!.textContent).not.toContain('확인');

      const footerRow = document.querySelector('.modal-footer-row');
      expect(footerRow).not.toBeNull();
      expect(footerRow!.textContent).toContain('확인');

      restore();
    });

    it('footer 가 없거나 빈 배열이면 하단 영역 자체가 렌더링되지 않음', () => {
      const { rerender } = render(<Modal {...mockProps} />);
      expect(document.querySelector('.modal-footer-row')).toBeNull();

      rerender(<Modal {...mockProps} footer={[]} />);
      expect(document.querySelector('.modal-footer-row')).toBeNull();
    });

    it('G7Core 가 없어도 footer 때문에 렌더링이 깨지지 않음', () => {
      const original = (window as any).G7Core;
      delete (window as any).G7Core;

      expect(() => {
        render(<Modal {...mockProps} footer={[{ type: 'basic', name: 'Button', text: '확인' }]} />);
      }).not.toThrow();

      expect(screen.getByText('모달 내용')).toBeInTheDocument();

      (window as any).G7Core = original;
    });
  });
});
