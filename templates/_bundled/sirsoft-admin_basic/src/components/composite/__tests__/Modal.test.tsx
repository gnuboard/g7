import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Modal, MODAL_SIZE_WIDTHS } from '../Modal';

/**
 * 번들 전체(템플릿·모듈·플러그인 layouts·extensions)가 쓰는 창 size 값 → 처음 발견한 파일.
 * 번들 전수 읽기라 수집 단계에서 한 번 한다(시험 한도 밖) — 동시 실행 부하에서 시험 안 훑기가 느려졌다.
 *
 * @returns size 값 → 파일
 */
function collectBundledModalSizes(): Map<string, string> {
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

  return found;
}

const BUNDLED_MODAL_SIZES = collectBundledModalSizes();

/** 번들 전체 정적 검사의 시험 한도 — 전역 한도는 그대로 두고 이 시험에만 명시한다 */
const STATIC_SCAN_TIMEOUT_MS = 60_000;

describe('Modal', () => {
  const mockProps = {
    isOpen: true,
    onClose: vi.fn(),
    title: '테스트 모달',
    children: <div>모달 내용</div>,
  };

  // @scenario area=open_close
  // @effects modal_renders_only_when_open
  it('컴포넌트가 렌더링됨', () => {
    render(<Modal {...mockProps} />);

    expect(screen.getByText('테스트 모달')).toBeInTheDocument();
    expect(screen.getByText('모달 내용')).toBeInTheDocument();
  });

  // @scenario area=open_close
  // @effects modal_renders_only_when_open
  it('isOpen이 false일 때 렌더링되지 않음', () => {
    render(<Modal {...mockProps} isOpen={false} />);

    expect(screen.queryByText('테스트 모달')).not.toBeInTheDocument();
  });

  // @scenario area=open_close
  // @effects modal_closes_via_button_overlay_or_escape_only
  it('닫기 버튼 클릭 시 onClose가 호출됨', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();

    render(<Modal {...mockProps} onClose={onClose} />);

    const closeButton = screen.getByLabelText('모달 닫기');
    await user.click(closeButton);

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  // @scenario area=open_close
  // @effects modal_closes_via_button_overlay_or_escape_only
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

  // @scenario area=open_close
  // @effects modal_closes_via_button_overlay_or_escape_only
  it('모달 내용 클릭 시 onClose가 호출되지 않음', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();

    render(<Modal {...mockProps} onClose={onClose} />);

    const modalContent = screen.getByRole('dialog');
    await user.click(modalContent);

    // 모달 내용 클릭 시 닫히지 않아야 함
    expect(onClose).not.toHaveBeenCalled();
  });

  // @scenario area=open_close
  // @effects modal_closes_via_button_overlay_or_escape_only
  it('ESC 키를 누르면 onClose가 호출됨', () => {
    const onClose = vi.fn();

    render(<Modal {...mockProps} onClose={onClose} />);

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  // @scenario area=sizing
  // @effects modal_width_resolved_from_width_or_size
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
    // @scenario area=sizing
    // @effects modal_width_resolved_from_width_or_size
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

    // @scenario area=sizing
    // @effects modal_width_resolved_from_width_or_size
    it('width 를 명시하면 size 보다 우선한다 (기존 width 사용처 무영향)', () => {
      render(<Modal {...mockProps} size="sm" width="920px" />);

      expect(screen.getByRole('dialog')).toHaveStyle({ width: '920px' });
    });

    // @scenario area=sizing
    // @effects modal_width_resolved_from_width_or_size
    it('size 가 없으면 종전 기본값 500px 을 유지한다 (미지정 모달 회귀 방어)', () => {
      render(<Modal {...mockProps} />);

      expect(screen.getByRole('dialog')).toHaveStyle({ width: '500px' });
    });

    // @scenario area=sizing
    // @effects modal_width_resolved_from_width_or_size
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
    // @scenario area=sizing
    // @effects bundled_modal_sizes_all_mapped
    it('번들 레이아웃이 쓰는 size 값이 전부 매핑에 있다', () => {
      const found = BUNDLED_MODAL_SIZES;

      // 코퍼스를 실제로 훑었는지부터 확인 — 0건이면 이 가드가 조용히 무력해진 것이다.
      expect(found.size).toBeGreaterThan(0);

      const unknown = [...found.entries()].filter(([size]) => !(size in MODAL_SIZE_WIDTHS));
      expect(unknown.map(([size, file]) => `${size} (${file})`)).toEqual([]);
    }, STATIC_SCAN_TIMEOUT_MS);
  });

  // @scenario area=open_close
  // @effects modal_renders_only_when_open
  it('className prop이 적용됨', () => {
    render(<Modal {...mockProps} className="custom-modal" />);

    const modal = screen.getByRole('dialog');
    expect(modal).toHaveClass('custom-modal');
  });

  // @scenario area=open_close
  // @effects modal_renders_only_when_open
  it('title이 없어도 렌더링됨', () => {
    const { title, ...propsWithoutTitle } = mockProps;

    render(<Modal {...propsWithoutTitle} />);

    expect(screen.getByText('모달 내용')).toBeInTheDocument();
  });

  // @scenario area=body_scroll
  // @effects modal_locks_and_restores_body_scroll
  it('모달이 열릴 때 body 스크롤이 방지됨', () => {
    render(<Modal {...mockProps} />);

    expect(document.body.style.overflow).toBe('hidden');
  });

  // @scenario area=body_scroll
  // @effects modal_locks_and_restores_body_scroll
  it('모달이 닫힐 때 body 스크롤이 복원됨', () => {
    const { rerender } = render(<Modal {...mockProps} />);

    expect(document.body.style.overflow).toBe('hidden');

    rerender(<Modal {...mockProps} isOpen={false} />);

    expect(document.body.style.overflow).toBe('');
  });

  describe('titlePrefix/titleSuffix', () => {
    // @scenario area=title_affix
    // @effects modal_title_affix_optional_and_safe_without_core
    it('titlePrefix 없이도 기존 동작이 유지됨', () => {
      render(<Modal {...mockProps} />);

      expect(screen.getByText('테스트 모달')).toBeInTheDocument();
    });

    // @scenario area=title_affix
    // @effects modal_title_affix_optional_and_safe_without_core
    it('titleSuffix 없이도 기존 동작이 유지됨', () => {
      render(<Modal {...mockProps} />);

      expect(screen.getByText('테스트 모달')).toBeInTheDocument();
    });

    // @scenario area=title_affix
    // @effects modal_title_affix_optional_and_safe_without_core
    it('titlePrefix와 titleSuffix가 빈 배열일 때 기존 동작이 유지됨', () => {
      render(<Modal {...mockProps} titlePrefix={[]} titleSuffix={[]} />);

      expect(screen.getByText('테스트 모달')).toBeInTheDocument();
    });

    // @scenario area=title_affix
    // @effects modal_title_affix_optional_and_safe_without_core
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

    // @scenario area=title_affix
    // @effects modal_title_affix_optional_and_safe_without_core
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

    // @scenario area=footer
    // @effects modal_footer_rendered_outside_scroll_area
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

    // @scenario area=footer
    // @effects modal_footer_rendered_outside_scroll_area
    it('footer 는 본문 스크롤 영역 밖에 렌더링됨', () => {
      const restore = installFakeG7Core();

      render(<Modal {...mockProps} footer={[{ type: 'basic', name: 'Button', text: '확인' }]} />);

      // 본문(.modal-body-content) 안에 있으면 내용이 길 때 버튼이 함께 스크롤되어 밀린다.
      const body = document.querySelector('.modal-body-content');
      expect(body).not.toBeNull();
      expect(body!.textContent).not.toContain('확인');

      const footerRow = document.querySelector('.modal-footer-bar');
      expect(footerRow).not.toBeNull();
      expect(footerRow!.textContent).toContain('확인');

      restore();
    });

    // @scenario area=footer
    // @effects modal_footer_rendered_outside_scroll_area
    it('footer 가 없거나 빈 배열이면 하단 영역 자체가 렌더링되지 않음', () => {
      const { rerender } = render(<Modal {...mockProps} />);
      expect(document.querySelector('.modal-footer-bar')).toBeNull();

      rerender(<Modal {...mockProps} footer={[]} />);
      expect(document.querySelector('.modal-footer-bar')).toBeNull();
    });

    /**
     * footer 는 본문과 같은 데이터 컨텍스트로 그린다.
     *
     * 엔진은 Modal 에 `__dataContext` 를 넣지 않는다. 종전에는 footer 를 빈 객체로 그려
     * `{{_global.isDeleting}}`·`{{_local.form.x}}` 같은 렌더 바인딩이 늘 비어 있었고, 그래서 지우는 중
     * 비활성·진행 표시나 방금 친 값을 읽는 단추를 둔 창은 버튼을 본문 맨 아래 줄로 옮겨 그렸다.
     * 엔진이 본문 자식을 그릴 때처럼 Modal 노드의 확장 컨텍스트(`parentDataContext`)와 컴포넌트
     * 컨텍스트를 그대로 넘긴다.
     */
    // @scenario area=footer
    // @effects modal_footer_reads_modal_node_context
    it('footer 는 Modal 노드의 데이터 컨텍스트와 컴포넌트 컨텍스트로 그린다', () => {
      const original = (window as any).G7Core;
      const seen: any[] = [];
      (window as any).G7Core = {
        getDynamicRenderer: () => (props: any) => {
          seen.push(props);
          return <button>{props.componentDef.text}</button>;
        },
        getComponentRegistry: () => ({}),
        getDataBindingEngine: () => ({}),
        getTranslationEngine: () => ({}),
        getActionDispatcher: () => ({}),
      };

      const nodeContext = { _global: { isDeleting: true }, _local: { form: { name: '방금 친 값' } } };
      const componentContext = { state: {}, setState: vi.fn(), parentDataContext: nodeContext };

      render(
        <Modal
          {...mockProps}
          footer={[{ type: 'basic', name: 'Button', text: '삭제' }]}
          __componentContext={componentContext as any}
        />
      );

      expect(seen.length).toBeGreaterThan(0);
      expect(seen[0].dataContext, 'footer 가 빈 컨텍스트로 그려졌습니다').toBe(nodeContext);
      expect(seen[0].parentComponentContext, 'footer 가 Modal 의 상태 범위를 받지 못했습니다').toBe(componentContext);

      (window as any).G7Core = original;
    });

    /**
     * 고정 하단 단추가 조건으로 모두 빠지면 바닥 줄(테두리·여백)도 보이지 않는다.
     * 단추는 각자 `if` 로 그려지지 않을 수 있어 Modal 이 미리 알 수 없다 — 비어 있으면 숨기는 상태 변형(`empty:hidden`)을 단다.
     */
    // @scenario area=footer
    // @effects modal_footer_hidden_when_all_buttons_filtered
    it('고정 하단 단추가 모두 그려지지 않으면 바닥 줄이 비고 숨김 규칙을 갖는다', () => {
      const original = (window as any).G7Core;
      (window as any).G7Core = {
        getDynamicRenderer: () => () => null,
        getComponentRegistry: () => ({}),
        getDataBindingEngine: () => ({}),
        getTranslationEngine: () => ({}),
        getActionDispatcher: () => ({}),
      };

      render(<Modal {...mockProps} footer={[{ type: 'basic', name: 'Button', text: '숨은 단추', if: '{{false}}' }]} />);

      const bar = document.querySelector('.modal-footer-bar') as HTMLElement;
      expect(bar).not.toBeNull();
      expect(bar.matches(':empty')).toBe(true);
      expect(bar.className.split(/\s+/), '빈 바닥 줄을 숨기는 규칙이 없습니다').toContain('empty:hidden');

      (window as any).G7Core = original;
    });

    // @scenario area=footer
    // @effects modal_footer_rendered_outside_scroll_area
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
