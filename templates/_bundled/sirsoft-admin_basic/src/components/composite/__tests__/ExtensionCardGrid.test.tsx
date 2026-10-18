/**
 * ExtensionCardGrid — 확장 목록 전용 그리드. [case:components-misc-card-cell-has-no-global]
 *
 * 이 컴포넌트가 생긴 이유가 곧 검사 대상이다. 종전 방식(CardGrid + 카드 셀 안의
 * `_global` 조건)은 **카드 셀 렌더 컨텍스트에 전역 상태가 없어서** 보기 분기가 아예
 * 평가되지 않았다. 그래서 여기서는 다음을 잠근다:
 *
 *  - 갤러리와 목록이 **서로 다른 DOM** 을 그린다(클래스 차이가 아니다)
 *  - 열 수는 중단점이 아니라 **컨테이너 실측 폭**에서 나오고 **간격을 뺀** 뒤 나눈다
 *  - 아이콘은 **정사각형**이고 이미지가 오기 전에도 자리를 지킨다
 *  - 작성자·의존성이 갤러리에서는 **각각 제 줄**을 차지한다
 *  - 콜백은 `(값, 원본 행)` 으로 원본 행을 되돌려 준다(data-grid 규약)
 */
import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, within, act } from '@testing-library/react';
import { ExtensionCardGrid, type ExtensionCardItem } from '../ExtensionCardGrid';

/** ResizeObserver 스텁 — 관찰 대상을 붙잡아 두고 테스트가 직접 발화시킨다. */
class ResizeObserverStub {
  static instances: ResizeObserverStub[] = [];
  callback: ResizeObserverCallback;
  targets: Element[] = [];

  constructor(callback: ResizeObserverCallback) {
    this.callback = callback;
    ResizeObserverStub.instances.push(this);
  }

  observe(target: Element): void {
    this.targets.push(target);
  }

  disconnect(): void {
    this.targets = [];
  }

  unobserve(): void {
    /* 테스트에서 쓰지 않는다 */
  }

  /** 관찰 중인 요소가 바뀐 것처럼 콜백을 깨운다. */
  fire(): void {
    this.callback([] as unknown as ResizeObserverEntry[], this as unknown as ResizeObserver);
  }
}

/** 컨테이너 폭을 고정한다 — jsdom 은 레이아웃을 계산하지 않으므로 직접 심는다. */
function setContainerWidth(width: number): void {
  Object.defineProperty(HTMLElement.prototype, 'clientWidth', {
    configurable: true,
    get() {
      return width;
    },
  });
}

const baseItem = (over: Partial<ExtensionCardItem> = {}): ExtensionCardItem => ({
  id: 'sirsoft-ckeditor5',
  row: { identifier: 'sirsoft-ckeditor5', status: 'active' },
  name: 'CKEditor 5',
  version: '1.1.0',
  description: 'WYSIWYG 에디터',
  vendor: 'sirsoft',
  iconUrl: '/api/extensions/plugin/sirsoft-ckeditor5/icon',
  fallbackIcon: 'puzzle-piece',
  dependencies: [],
  badges: [],
  ...over,
});

beforeEach(() => {
  ResizeObserverStub.instances = [];
  (globalThis as any).ResizeObserver = ResizeObserverStub;
  (window as any).G7Core = {
    t: (key: string, params?: Record<string, string | number>) =>
      params?.name ? `${params.name} ${key}` : key,
  };
});

afterEach(() => {
  vi.restoreAllMocks();
  delete (globalThis as any).ResizeObserver;
});

describe('ExtensionCardGrid — 보기 방식', () => {
  // @scenario view=gallery, icon_state=present
  // @effects gallery_and_list_render_separate_dom, grid_columns_follow_view_mode
  it('갤러리와 목록이 서로 다른 DOM 을 그린다', () => {
    setContainerWidth(1072);

    const { container: gallery, unmount } = render(
      <ExtensionCardGrid items={[baseItem()]} viewMode="gallery" />
    );
    const galleryGrid = gallery.querySelector('[data-view-mode]');
    expect(galleryGrid?.getAttribute('data-view-mode')).toBe('gallery');
    expect((galleryGrid as HTMLElement).style.display).toBe('grid');
    unmount();

    const { container: list } = render(<ExtensionCardGrid items={[baseItem()]} viewMode="list" />);
    const listGrid = list.querySelector('[data-view-mode]');
    expect(listGrid?.getAttribute('data-view-mode')).toBe('list');
    // 목록은 grid 가 아니라 세로 흐름이다 — 클래스만 바꾼 것이 아님을 여기서 잠근다.
    expect((listGrid as HTMLElement).style.display).toBe('flex');
    expect((listGrid as HTMLElement).style.flexDirection).toBe('column');
  });

  // @scenario view=gallery
  // @effects default_is_gallery
  it('viewMode 를 주지 않으면 갤러리가 기본이다', () => {
    setContainerWidth(1072);
    const { container } = render(<ExtensionCardGrid items={[baseItem()]} />);
    expect(container.querySelector('[data-view-mode]')?.getAttribute('data-view-mode')).toBe(
      'gallery'
    );
  });

  // @scenario view=list, device=desktop_1440
  // @effects grid_columns_follow_view_mode, list_card_keeps_current_layout
  it('목록형은 폭과 무관하게 한 줄에 하나다', () => {
    setContainerWidth(1920);
    const { container } = render(<ExtensionCardGrid items={[baseItem()]} viewMode="list" />);
    expect(container.querySelector('[data-view-mode]')?.getAttribute('data-columns')).toBe('1');
  });
});

describe('ExtensionCardGrid — 열 수는 컨테이너 폭에서 나온다', () => {
  /**
   * 간격을 뺀 뒤 나눈다. 폭만 minCardWidth 로 나누면 간격이 없는 셈이 되어 실제 카드가
   * 최소 폭보다 좁아진다(1232px 에서 5열이 되고 카드가 234px 로 쪼그라들던 결함).
   */
  // @scenario view=gallery, device=desktop_1440
  // @effects columns_follow_container_width, columns_subtract_gap_before_dividing
  it.each([
    [1552, 250, 5],
    [1232, 250, 4],
    [1072, 250, 4],
    [912, 250, 3],
    [732, 250, 2],
    [466, 250, 1],
    [200, 250, 1],
  ])('컨테이너 %ipx · 최소 %ipx → %i열', (width, minCardWidth, expected) => {
    setContainerWidth(width);
    const { container } = render(
      <ExtensionCardGrid items={[baseItem()]} viewMode="gallery" minCardWidth={minCardWidth} />
    );
    expect(container.querySelector('[data-view-mode]')?.getAttribute('data-columns')).toBe(
      String(expected)
    );
  });

  // @scenario view=gallery
  // @effects columns_respect_max_columns
  it('maxColumns 를 넘지 않는다', () => {
    setContainerWidth(4000);
    const { container } = render(
      <ExtensionCardGrid items={[baseItem()]} minCardWidth={200} maxColumns={3} />
    );
    expect(container.querySelector('[data-view-mode]')?.getAttribute('data-columns')).toBe('3');
  });

  // @scenario view=gallery
  // @effects columns_follow_container_width
  it('컨테이너가 넓어지면 열 수가 따라 늘어난다', () => {
    setContainerWidth(600);
    const { container } = render(<ExtensionCardGrid items={[baseItem()]} minCardWidth={250} />);
    expect(container.querySelector('[data-view-mode]')?.getAttribute('data-columns')).toBe('2');

    // ResizeObserver 콜백은 React 밖에서 울린다 — act 로 감싸야 상태 갱신이 반영된다.
    setContainerWidth(1552);
    act(() => {
      ResizeObserverStub.instances.forEach((o) => o.fire());
    });
    expect(container.querySelector('[data-view-mode]')?.getAttribute('data-columns')).toBe('5');
  });
});

describe('ExtensionCardGrid — 아이콘', () => {
  // @scenario icon_state=present
  // @effects icon_box_is_square, icon_rendered_from_icon_url
  it('정사각형 상자에 그리고 글리프를 늘 깔아 둔다', () => {
    setContainerWidth(1072);
    const { container } = render(<ExtensionCardGrid items={[baseItem()]} />);
    const box = container.querySelector('[data-ref="ext_icon"]') as HTMLElement;

    expect(box).toBeTruthy();
    expect(box.className).toContain('aspect-square');
    // 글리프가 이미지와 함께 깔려 있다 — 이미지가 늦게 와도 자리가 비지 않는다.
    expect(box.querySelector('i,svg')).toBeTruthy();
    expect(box.querySelector('img')).toBeTruthy();
  });

  // @scenario icon_state=absent
  // @effects fallback_glyph_when_icon_absent
  it('아이콘이 없으면 글리프만 남는다', () => {
    setContainerWidth(1072);
    const { container } = render(
      <ExtensionCardGrid items={[baseItem({ iconUrl: undefined })]} />
    );
    const box = container.querySelector('[data-ref="ext_icon"]') as HTMLElement;
    expect(box.querySelector('img')).toBeNull();
    expect(box.querySelector('i,svg')).toBeTruthy();
  });

  // @scenario icon_state=lp_target_composite
  // @effects language_pack_composite_badge
  it('언어팩은 빌려 온 아이콘 위에 로케일 배지를 얹는다', () => {
    setContainerWidth(1072);
    const { container } = render(
      <ExtensionCardGrid items={[baseItem({ iconBadge: { text: 'ja', srLabel: '日本語 언어팩' } })]} />
    );
    const badge = container.querySelector('[data-ref="ext_icon_badge"]') as HTMLElement;

    // 언어팩 카드는 대상 확장의 그림을 빌려 쓴다 — 무엇의 어떤 언어인지 이 표식으로만 갈린다.
    expect(badge).toBeTruthy();
    expect(badge.textContent).toBe('ja');
    expect(badge.getAttribute('aria-label')).toBe('日本語 언어팩');
    // 다크에서도 파랑을 유지해야 흰 글자와의 대비가 남는다(§7.4).
    expect(badge.className).toContain('bg-blue-600');
    expect(badge.className).toContain('dark:bg-blue-500');
  });

  // @scenario icon_state=lp_manifest
  // @effects language_pack_composite_badge
  it('자기 아이콘을 담은 언어팩에는 배지를 붙이지 않는다', () => {
    setContainerWidth(1072);
    const { container } = render(<ExtensionCardGrid items={[baseItem()]} />);

    // 제 그림을 담았으면 로케일은 이름 옆 배지가 이미 말해 준다 — 그림 위에 또 얹지 않는다.
    expect(container.querySelector('[data-ref="ext_icon_badge"]')).toBeNull();
  });

  // @scenario icon_state=lp_glyph_composite
  // @effects language_pack_composite_badge, fallback_glyph_when_icon_absent
  it('빌려 올 아이콘조차 없으면 글리프 위에 배지를 얹는다', () => {
    setContainerWidth(1072);
    const { container } = render(
      <ExtensionCardGrid
        items={[baseItem({ iconUrl: undefined, iconBadge: { text: 'ja', srLabel: '日本語 언어팩' } })]}
      />
    );
    const box = container.querySelector('[data-ref="ext_icon"]') as HTMLElement;

    expect(box.querySelector('img')).toBeNull();
    expect(box.querySelector('i,svg')).toBeTruthy();
    expect(box.querySelector('[data-ref="ext_icon_badge"]')?.textContent).toBe('ja');
  });

  // @scenario icon_state=broken_url
  // @effects fallback_glyph_when_icon_broken, broken_icon_is_scoped_to_one_card
  it('이미지가 깨지면 그 카드만 글리프로 돌아간다', () => {
    setContainerWidth(1072);
    const { container } = render(
      <ExtensionCardGrid items={[baseItem(), baseItem({ id: 'other', name: '다른 확장' })]} />
    );
    const boxes = container.querySelectorAll('[data-ref="ext_icon"]');
    expect(boxes[0].querySelector('img')).toBeTruthy();

    fireEvent.error(boxes[0].querySelector('img') as HTMLImageElement);

    expect(boxes[0].querySelector('img')).toBeNull();
    // 옆 카드는 그대로다 — 깨짐이 전역으로 번지지 않는다.
    expect(boxes[1].querySelector('img')).toBeTruthy();
  });

  // @scenario view=gallery, icon_state=present
  // @effects icon_box_is_square
  it('아이콘이 카드 폭을 넘지 않는다', () => {
    // 아주 좁은 카드에서 아이콘이 제 크기를 고집하면 이름·설명이 남는 폭에 밀려
    // 한 글자씩 끊긴다(실측 2026-09-15). 상한을 두어 카드에 비례해 줄인다.
    setContainerWidth(1072);
    const { container } = render(<ExtensionCardGrid items={[baseItem()]} viewMode="gallery" />);
    const box = container.querySelector('[data-ref="ext_icon"]') as HTMLElement;

    expect(box.className).toMatch(/max-w-\[\d+%\]/);
    expect(box.className).toContain('aspect-square');
  });

  // @scenario view=gallery, icon_state=present
  // @effects icon_box_is_square, gallery_card_stacks_vertically
  it('갤러리 아이콘이 목록 아이콘보다 크다', () => {
    setContainerWidth(1072);
    const { container: g, unmount } = render(
      <ExtensionCardGrid items={[baseItem()]} viewMode="gallery" />
    );
    const galleryClass = (g.querySelector('[data-ref="ext_icon"]') as HTMLElement).className;
    unmount();

    const { container: l } = render(<ExtensionCardGrid items={[baseItem()]} viewMode="list" />);
    const listClass = (l.querySelector('[data-ref="ext_icon"]') as HTMLElement).className;

    expect(galleryClass).toContain('w-14');
    expect(listClass).toContain('w-12');
  });
});

describe('ExtensionCardGrid — 조판', () => {
  // @scenario view=gallery
  // @effects gallery_meta_splits_vendor_and_dependencies, gallery_card_stacks_vertically
  it('갤러리는 작성자와 의존성을 각각 제 줄에 둔다', () => {
    setContainerWidth(1072);
    const { container } = render(<ExtensionCardGrid items={[baseItem()]} viewMode="gallery" />);
    const meta = container.querySelector('[data-ref="ext_meta"]') as HTMLElement;

    expect(meta.className).toContain('flex-col');
    expect(meta.querySelector('[data-ref="ext_vendor"]')).toBeTruthy();
    expect(meta.querySelector('[data-ref="ext_deps"]')).toBeTruthy();
  });

  // @scenario view=list
  // @effects list_card_keeps_current_layout
  it('목록은 작성자와 의존성을 한 줄에 둔다', () => {
    setContainerWidth(1072);
    const { container } = render(<ExtensionCardGrid items={[baseItem()]} viewMode="list" />);
    const meta = container.querySelector('[data-ref="ext_meta"]') as HTMLElement;

    expect(meta.className).toContain('items-center');
    expect(meta.className).not.toContain('flex-col');
  });

  // @scenario view=gallery
  // @effects card_name_is_left_aligned
  it('이름을 가운데 정렬하지 않는다', () => {
    setContainerWidth(1072);
    const { container } = render(<ExtensionCardGrid items={[baseItem()]} viewMode="gallery" />);
    const card = container.querySelector('[data-ref="ext_card"]') as HTMLElement;

    expect(card.className).toContain('text-left');
    expect(card.className).not.toContain('text-center');
    expect(card.className).not.toContain('items-center');
  });

  // @scenario view=gallery
  // @effects gallery_meta_splits_vendor_and_dependencies
  it('의존성이 없으면 라벨을 두 번 쓰지 않는다', () => {
    setContainerWidth(1072);
    (window as any).G7Core.t = (key: string) =>
      key === 'admin.plugins.no_dependencies'
        ? '의존성: 없음'
        : key === 'admin.plugins.dependencies_label'
          ? '의존성:'
          : key;

    const { container } = render(<ExtensionCardGrid items={[baseItem({ dependencies: [] })]} />);
    const deps = container.querySelector('[data-ref="ext_deps"]') as HTMLElement;

    expect(deps.textContent).toBe('의존성: 없음');
  });

  // @scenario view=gallery
  // @effects control_buttons_do_not_wrap_per_character
  it('버튼 글자가 세로로 쪼개지지 않게 nowrap 을 준다', () => {
    setContainerWidth(1072);
    const { container } = render(
      <ExtensionCardGrid
        items={[baseItem({ primaryAction: { label: '레이아웃 편집', variant: 'outline' } })]}
      />
    );
    const button = container.querySelector('[data-ref="ext_controls"] button') as HTMLElement;

    expect(button.className).toContain('whitespace-nowrap');
  });
});

describe('ExtensionCardGrid — 콜백은 원본 행을 되돌려 준다', () => {
  // @scenario view=gallery, install_state=active
  // @effects callbacks_return_source_row, gallery_actions_parity_with_list
  it('카드 본문 클릭이 원본 행을 넘긴다', () => {
    setContainerWidth(1072);
    const onItemClick = vi.fn();
    const item = baseItem();
    const { container } = render(<ExtensionCardGrid items={[item]} onItemClick={onItemClick} />);

    fireEvent.click(container.querySelector('[data-ref="ext_card"]') as HTMLElement);

    expect(onItemClick).toHaveBeenCalledWith(item.row);
  });

  // @scenario view=gallery, install_state=active
  // @effects callbacks_return_source_row, gallery_actions_parity_with_list
  it('토글이 (다음값, 원본 행) 으로 알린다', () => {
    setContainerWidth(1072);
    const onToggle = vi.fn();
    const item = baseItem({ toggle: { checked: false, label: 'CKEditor 5' } });
    const { container } = render(<ExtensionCardGrid items={[item]} onToggle={onToggle} />);

    const checkbox = container.querySelector('input[type="checkbox"]') as HTMLInputElement;
    fireEvent.click(checkbox);

    expect(onToggle).toHaveBeenCalled();
    expect(onToggle.mock.calls[0][1]).toBe(item.row);
  });

  // 스위치를 누르면 먼저 뒤집혀 보이지만, 서버가 거절해(실패·의존 충돌 창 취소) 값이 그대로인 채 화면이
  // 재동기 신호를 올리면 서버 값으로 돌아와야 한다 — 받은 checked 가 그대로라 스위치가 꺼진 채 남던 문제(#703 실측).
  // @scenario view=gallery, install_state=active
  // @effects toggle_resyncs_to_server_value_on_refetch
  it('서버 값이 그대로인 채 재동기 신호가 오르면 스위치가 서버 값으로 돌아온다', () => {
    setContainerWidth(1072);
    const make = () => baseItem({ row: { identifier: 'sirsoft-ckeditor5', status: 'active' }, toggle: { checked: true, label: 'CKEditor 5' } });
    const { container, rerender } = render(<ExtensionCardGrid items={[make()]} onToggle={vi.fn()} resyncKey={0} />);

    const switchEl = () => container.querySelector('[role="switch"]') as HTMLElement;
    fireEvent.click(container.querySelector('input[type="checkbox"]') as HTMLInputElement);
    expect(switchEl().getAttribute('aria-checked')).toBe('false');

    rerender(<ExtensionCardGrid items={[make()]} onToggle={vi.fn()} resyncKey={1} />);
    expect(switchEl().getAttribute('aria-checked')).toBe('true');
  });

  // 요청이 도는 동안 화면이 다시 그려져도 누른 값이 유지된다 — 엔진은 렌더마다 카드 항목과 원본 행을
  // 새 객체로 만든다. 그때마다 버려지면 누른 반응이 요청이 끝날 때까지(수 초) 안 보여 두 번 누르게 된다.
  // @scenario view=gallery, install_state=active
  // @effects toggle_resyncs_to_server_value_on_refetch
  it('행 객체가 새로 만들어지는 다시 그리기에서는 누른 값이 유지된다', () => {
    setContainerWidth(1072);
    const make = () => baseItem({ row: { identifier: 'sirsoft-ckeditor5', status: 'active' }, toggle: { checked: true, label: 'CKEditor 5' } });
    const { container, rerender } = render(<ExtensionCardGrid items={[make()]} onToggle={vi.fn()} resyncKey={0} />);

    const switchEl = () => container.querySelector('[role="switch"]') as HTMLElement;
    fireEvent.click(container.querySelector('input[type="checkbox"]') as HTMLInputElement);
    rerender(<ExtensionCardGrid items={[make()]} onToggle={vi.fn()} resyncKey={0} />);
    rerender(<ExtensionCardGrid items={[make()]} onToggle={vi.fn()} resyncKey={0} />);
    expect(switchEl().getAttribute('aria-checked')).toBe('false');
  });

  // 서버 값이 바뀌면(요청 성공 후 재조회) 누른 값을 버리고 서버를 따른다 — 이후 다른 곳에서 값이 다시 바뀌어도
  // 옛 대기 값이 가리지 않는다.
  // @scenario view=gallery, install_state=active
  // @effects toggle_resyncs_to_server_value_on_refetch
  it('서버 값이 바뀌면 대기 값을 버리고 이후 서버 값을 그대로 따른다', () => {
    setContainerWidth(1072);
    const make = (checked: boolean) => baseItem({ row: { identifier: 'sirsoft-ckeditor5' }, toggle: { checked, label: 'CKEditor 5' } });
    const { container, rerender } = render(<ExtensionCardGrid items={[make(true)]} onToggle={vi.fn()} />);

    const switchEl = () => container.querySelector('[role="switch"]') as HTMLElement;
    fireEvent.click(container.querySelector('input[type="checkbox"]') as HTMLInputElement);
    rerender(<ExtensionCardGrid items={[make(false)]} onToggle={vi.fn()} />);
    expect(switchEl().getAttribute('aria-checked')).toBe('false');

    rerender(<ExtensionCardGrid items={[make(true)]} onToggle={vi.fn()} />);
    expect(switchEl().getAttribute('aria-checked')).toBe('true');
  });

  // @scenario view=gallery, install_state=uninstalled
  // @effects callbacks_return_source_row, gallery_actions_parity_with_list
  it('주 버튼이 원본 행을 넘긴다', () => {
    setContainerWidth(1072);
    const onPrimaryAction = vi.fn();
    const item = baseItem({ primaryAction: { label: '설치', variant: 'solid' } });
    const { container } = render(
      <ExtensionCardGrid items={[item]} onPrimaryAction={onPrimaryAction} />
    );

    fireEvent.click(container.querySelector('[data-ref="ext_controls"] button') as HTMLElement);

    expect(onPrimaryAction).toHaveBeenCalledWith(item.row);
  });

  // @scenario view=gallery, install_state=uninstalled
  // @effects gallery_actions_parity_with_list
  it('제어부 클릭이 카드 본문 열기로 번지지 않는다', () => {
    setContainerWidth(1072);
    const onItemClick = vi.fn();
    const onPrimaryAction = vi.fn();
    const item = baseItem({ primaryAction: { label: '설치', variant: 'solid' } });
    const { container } = render(
      <ExtensionCardGrid
        items={[item]}
        onItemClick={onItemClick}
        onPrimaryAction={onPrimaryAction}
      />
    );

    fireEvent.click(container.querySelector('[data-ref="ext_controls"] button') as HTMLElement);

    expect(onPrimaryAction).toHaveBeenCalledTimes(1);
    expect(onItemClick).not.toHaveBeenCalled();
  });

  // @scenario view=gallery, install_state=active
  // @effects callbacks_return_source_row
  it('키보드로도 카드를 열 수 있다', () => {
    setContainerWidth(1072);
    const onItemClick = vi.fn();
    const item = baseItem();
    const { container } = render(<ExtensionCardGrid items={[item]} onItemClick={onItemClick} />);

    fireEvent.keyDown(container.querySelector('[data-ref="ext_card"]') as HTMLElement, {
      key: 'Enter',
    });

    expect(onItemClick).toHaveBeenCalledWith(item.row);
  });
});

describe('ExtensionCardGrid — 상태 표시', () => {
  // @scenario view=gallery
  // @effects gallery_card_stacks_vertically
  it('로딩 중이고 줄이 아직 없으면 자리표시자를 그린다', () => {
    setContainerWidth(1072);
    const { container } = render(<ExtensionCardGrid loading={true} skeletonCount={4} />);

    expect(container.querySelectorAll('[data-ref="ext_skeleton"]').length).toBe(4);
    expect(container.querySelectorAll('[data-ref="ext_card"]').length).toBe(0);
  });

  // @scenario view=gallery
  // @effects gallery_card_stacks_vertically
  it('받아 둔 줄이 있으면 loading 이 켜져 있어도 그 줄을 그린다', () => {
    // 데이터소스를 부모 화면이 들고 조각이 값만 빌려 쓰면 로딩 깃발이 내려오지 않는다 —
    // 그때 자리표시자만 남으면 목록이 영영 비어 보인다(템플릿 탭 실측 2026-09-15).
    setContainerWidth(1072);
    const { container } = render(
      <ExtensionCardGrid items={[baseItem()]} loading={true} skeletonCount={4} />
    );

    expect(container.querySelectorAll('[data-ref="ext_card"]').length).toBe(1);
    expect(container.querySelectorAll('[data-ref="ext_skeleton"]').length).toBe(0);
  });

  // @scenario view=gallery
  // @effects gallery_card_stacks_vertically
  it('items 가 아직 없으면 자리표시자를 그린다', () => {
    setContainerWidth(1072);
    const { container } = render(<ExtensionCardGrid skeletonCount={3} />);
    expect(container.querySelectorAll('[data-ref="ext_skeleton"]').length).toBe(3);
  });

  // @scenario view=gallery, device=desktop_1440
  // @effects columns_follow_container_width
  it('개수를 주지 않으면 갤러리 자리표시자가 열 수를 따른다', () => {
    // 고정값을 쓰면 넓은 화면에서는 한 줄이 덜 차고 좁은 화면에서는 넘친다.
    setContainerWidth(1072);
    const { container } = render(<ExtensionCardGrid loading={true} />);
    const columns = container.querySelectorAll('[data-ref="ext_skeleton"]').length;

    expect(columns).toBeGreaterThan(1);

    // 폭을 좁히면 열이 줄고 자리표시자도 같이 줄어야 한다.
    setContainerWidth(400);
    const narrow = render(<ExtensionCardGrid loading={true} />);
    expect(narrow.container.querySelectorAll('[data-ref="ext_skeleton"]').length).toBeLessThan(columns);
  });

  // @scenario view=list
  // @effects columns_follow_container_width
  it('목록 보기는 개수를 주지 않으면 자리표시자가 하나다', () => {
    // 목록에서 여러 장이 깔리면 로딩 중 화면이 갤러리처럼 보인다(§7.4 로딩 행).
    setContainerWidth(1072);
    const { container } = render(<ExtensionCardGrid viewMode="list" loading={true} />);
    expect(container.querySelectorAll('[data-ref="ext_skeleton"]').length).toBe(1);
  });

  // @scenario view=gallery
  // @effects gallery_card_stacks_vertically
  it('0건이면 빈 안내를 보여 준다', () => {
    setContainerWidth(1072);
    render(<ExtensionCardGrid items={[]} emptyMessage="확장이 없습니다" />);
    expect(screen.getByText('확장이 없습니다')).toBeTruthy();
  });

  // @scenario view=gallery
  // @effects gallery_card_stacks_vertically
  it('0건이면 페이지네이션을 그리지 않는다', () => {
    setContainerWidth(1072);
    const { container } = render(
      <ExtensionCardGrid items={[]} pagination={true} alwaysShowPagination={true} />
    );
    expect(container.querySelector('nav')).toBeNull();
  });

  // @scenario view=gallery, install_state=active
  // @effects gallery_card_stacks_vertically
  it('배지와 버전을 이름 옆에 붙인다', () => {
    setContainerWidth(1072);
    render(
      <ExtensionCardGrid
        items={[baseItem({ badges: [{ label: '활성화', tone: 'success' }] })]}
      />
    );

    expect(screen.getByText('v1.1.0')).toBeTruthy();
    expect(screen.getByText('활성화')).toBeTruthy();
  });

  // @scenario view=gallery
  // @effects gallery_meta_splits_vendor_and_dependencies
  it('의존성을 종류 아이콘과 함께 늘어놓는다', () => {
    setContainerWidth(1072);
    const { container } = render(
      <ExtensionCardGrid
        items={[
          baseItem({
            dependencies: [
              { type: 'module', name: '이커머스' },
              { type: 'plugin', name: 'Daum 우편번호' },
            ],
          }),
        ]}
      />
    );
    const deps = container.querySelector('[data-ref="ext_deps"]') as HTMLElement;

    expect(within(deps).getByText('이커머스')).toBeTruthy();
    expect(within(deps).getByText('Daum 우편번호')).toBeTruthy();
  });
});

describe('ExtensionCardGrid — 켜기 스위치의 접근 가능한 이름 (O-Q2-1)', () => {
  // 업데이트·레이아웃 편집 단추가 스위치 바로 앞에 서면, 이름 없는 스위치가 곁 글자를 찾아 그 단추 글자를
  // 이름으로 집었다 — 화면 읽기에서 모듈·플러그인 카드 스위치가 「업데이트」, 템플릿 카드는 「레이아웃 편집」.
  // @scenario control=extension_card_switch
  // @effects extension_card_switch_named_by_extension
  it('업데이트 단추가 있는 카드의 스위치는 확장 이름으로 읽힌다', () => {
    setContainerWidth(1072);
    render(
      <ExtensionCardGrid
        items={[baseItem({ primaryAction: { label: '업데이트', variant: 'solid' }, toggle: { checked: true, label: 'CKEditor 5' } })]}
        onToggle={vi.fn()}
      />
    );

    expect(screen.getByRole('switch', { name: 'CKEditor 5' })).toBeTruthy();
    expect(screen.queryAllByRole('switch', { name: '업데이트' })).toHaveLength(0);
  });

  // @scenario control=extension_card_switch
  // @effects extension_card_switch_named_by_extension
  it('레이아웃 편집 단추가 있는 템플릿 카드의 스위치도 확장 이름으로 읽힌다(목록 보기 포함)', () => {
    setContainerWidth(1072);
    render(
      <ExtensionCardGrid
        viewMode="list"
        items={[baseItem({ name: '관리자 기본', secondaryAction: { label: '레이아웃 편집' }, toggle: { checked: true } })]}
        onToggle={vi.fn()}
      />
    );

    expect(screen.getByRole('switch', { name: '관리자 기본' })).toBeTruthy();
  });
});
