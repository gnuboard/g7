// e2e:allow [스크롤] 폼 단위(RTL) — 칸별 상속/지정 표시·own 패치 모양. 저장 왕복은 page-settings-roundtrip·LayoutControllerTest 가 잠근다.
/**
 * ScrollRestorationForm.test.tsx — 페이지 설정 [스크롤] 탭 폼 RTL (meta.scroll_restoration)
 *
 * 검증:
 *  ① 칸마다 출처 배지 — 이 레이아웃 / 공통 레이아웃 / 템플릿 설정 / 엔진 기본값 (좁은 쪽 우선)
 *  ② 칸을 고치면 own 에 그 키만, 비우면 키를 지워 상속 (snapshot 하위 키도 같음)
 *  ③ 돌아왔을 때 동작 — 고정 3종 + 특정 요소(선택자 입력, 빈 선택자는 저장 안 함)
 *  ④ containers — 상속 목록 읽기 전용 → 「따로 정하기」로 복사 후 추가·삭제 → 되돌리기
 *  ⑤ 숫자 칸 범위 고정(최대 대기 0~60000, 유효 시간 0~86400)
 *  ⑥ 베이스 레이아웃 안내 / 모두 지우기
 *  ⑦ 순수 함수 — normalizeScrollOwn · mergeScrollLayers · deriveInheritedScroll
 *
 * @since engine-v1.85.0
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ScrollRestorationForm } from '../../../components/page-settings/ScrollRestorationForm';
import {
  normalizeScrollOwn,
  mergeScrollLayers,
  deriveInheritedScroll,
} from '../../../components/page-settings/scrollRestorationValue';

const t = (k: string, p?: Record<string, string | number>) => (p ? `${k}:${JSON.stringify(p)}` : k);

function source(key: string): string | null {
  return screen.getByTestId(`g7le-scroll-source-${key}`).getAttribute('data-source');
}

beforeEach(() => {
  cleanup();
});

describe('ScrollRestorationForm — 칸별 상속 표시', () => {
  /**
   * @effects scroll_form_field_source_badge_own_base_template_engine
   */
  it('① 출처는 이 레이아웃 > 공통 레이아웃 > 템플릿 설정 > 엔진 기본값 순서로 고른다', () => {
    render(
      <ScrollRestorationForm
        own={{ wait: 'data' }}
        baseValue={{ mode: 'top', snapshot: { ttl_seconds: 60 } }}
        templateValue={{ mode: 'none', timeout_ms: 7000, snapshot: { item_key: 'uuid', ttl_seconds: 120 } }}
        onChange={vi.fn()}
        t={t}
      />,
    );
    expect(source('wait')).toBe('own');
    expect(source('mode')).toBe('base');
    expect(source('timeout_ms')).toBe('template');
    expect(source('on_reload')).toBe('engine');
    expect(source('snapshot_ttl_seconds')).toBe('base');
    expect(source('snapshot_item_key')).toBe('template');
    expect(source('snapshot_enabled')).toBe('engine');
    // 상속 칸은 비어 있고 상속값을 안내한다(placeholder·「상속 (값)」 선택지)
    expect((screen.getByTestId('g7le-scroll-timeout') as HTMLInputElement).value).toBe('');
    expect((screen.getByTestId('g7le-scroll-timeout') as HTMLInputElement).placeholder).toBe('7000');
    expect((screen.getByTestId('g7le-scroll-snapshot-ttl') as HTMLInputElement).placeholder).toBe('60');
    const modeSelect = screen.getByTestId('g7le-scroll-mode') as HTMLSelectElement;
    expect(modeSelect.value).toBe('');
    expect(modeSelect.options[0].textContent).toContain('layout_editor.scroll_restoration.mode_top');
  });

  /**
   * @effects scroll_form_field_source_badge_own_base_template_engine
   */
  it('① 아무 값도 없으면 전부 엔진 기본값(복원·새로고침 켬·높이·5000ms·스냅샷 켬 600초)', () => {
    render(<ScrollRestorationForm own={undefined} onChange={vi.fn()} t={t} />);
    for (const key of ['mode', 'on_reload', 'wait', 'timeout_ms', 'containers', 'snapshot_enabled', 'snapshot_ttl_seconds', 'snapshot_revalidate', 'snapshot_item_key', 'snapshot_state']) {
      expect(source(key)).toBe('engine');
    }
    expect((screen.getByTestId('g7le-scroll-timeout') as HTMLInputElement).placeholder).toBe('5000');
    expect((screen.getByTestId('g7le-scroll-snapshot-ttl') as HTMLInputElement).placeholder).toBe('600');
    expect((screen.getByTestId('g7le-scroll-snapshot-item-key') as HTMLInputElement).placeholder).toBe('id');
    expect((screen.getByTestId('g7le-scroll-mode') as HTMLSelectElement).options[0].textContent).toContain('mode_restore');
    // 지정한 값이 없으면 「모두 지우기」도 없다
    expect(screen.queryByTestId('g7le-scroll-reset-all')).not.toBeInTheDocument();
  });
});

describe('ScrollRestorationForm — own 패치', () => {
  /**
   * @effects scroll_form_empty_field_removes_key_to_inherit
   */
  it('② 고치면 그 키만, 비우면 키를 지운다(남은 키가 없으면 undefined = 전부 상속)', () => {
    const onChange = vi.fn();
    const { rerender } = render(<ScrollRestorationForm own={undefined} onChange={onChange} t={t} />);

    fireEvent.change(screen.getByTestId('g7le-scroll-on-reload'), { target: { value: 'false' } });
    expect(onChange).toHaveBeenLastCalledWith({ on_reload: false });

    rerender(<ScrollRestorationForm own={{ on_reload: false }} onChange={onChange} t={t} />);
    expect(source('on_reload')).toBe('own');
    fireEvent.change(screen.getByTestId('g7le-scroll-wait'), { target: { value: 'data' } });
    expect(onChange).toHaveBeenLastCalledWith({ on_reload: false, wait: 'data' });

    fireEvent.change(screen.getByTestId('g7le-scroll-on-reload'), { target: { value: '' } });
    expect(onChange).toHaveBeenLastCalledWith(undefined);
  });

  /**
   * @effects scroll_form_empty_field_removes_key_to_inherit
   */
  it('② snapshot 하위 키도 키 단위 — 다른 하위 키는 건드리지 않고, 비면 snapshot 자체를 지운다', () => {
    const onChange = vi.fn();
    const { rerender } = render(
      <ScrollRestorationForm own={{ mode: 'top', snapshot: { enabled: false } }} onChange={onChange} t={t} />,
    );
    fireEvent.change(screen.getByTestId('g7le-scroll-snapshot-item-key'), { target: { value: ' code ' } });
    expect(onChange).toHaveBeenLastCalledWith({ mode: 'top', snapshot: { enabled: false, item_key: 'code' } });

    rerender(<ScrollRestorationForm own={{ mode: 'top', snapshot: { enabled: false } }} onChange={onChange} t={t} />);
    fireEvent.change(screen.getByTestId('g7le-scroll-snapshot-enabled'), { target: { value: '' } });
    expect(onChange).toHaveBeenLastCalledWith({ mode: 'top' });

    fireEvent.change(screen.getByTestId('g7le-scroll-snapshot-revalidate'), { target: { value: 'false' } });
    expect(onChange).toHaveBeenLastCalledWith({ mode: 'top', snapshot: { enabled: false, revalidate: false } });
  });

  /**
   * @effects scroll_form_selector_mode_saved_only_when_selector_typed
   */
  it('③ 특정 요소로 이동 — 선택자를 입력해야 mode 가 저장되고, 고정 모드로 바꾸면 그 값으로', () => {
    const onChange = vi.fn();
    const { rerender } = render(<ScrollRestorationForm own={undefined} onChange={onChange} t={t} />);
    fireEvent.change(screen.getByTestId('g7le-scroll-mode'), { target: { value: '__selector__' } });
    // 빈 선택자는 저장하지 않는다(입력칸만 열림)
    expect(onChange).not.toHaveBeenCalled();
    fireEvent.change(screen.getByTestId('g7le-scroll-mode-selector'), { target: { value: '#product-list' } });
    expect(onChange).toHaveBeenLastCalledWith({ mode: '#product-list' });

    rerender(<ScrollRestorationForm own={{ mode: '#product-list' }} onChange={onChange} t={t} />);
    expect((screen.getByTestId('g7le-scroll-mode') as HTMLSelectElement).value).toBe('__selector__');
    expect((screen.getByTestId('g7le-scroll-mode-selector') as HTMLInputElement).value).toBe('#product-list');

    fireEvent.change(screen.getByTestId('g7le-scroll-mode'), { target: { value: 'none' } });
    expect(onChange).toHaveBeenLastCalledWith({ mode: 'none' });
  });

  /**
   * @effects scroll_form_containers_override_add_remove_revert
   */
  it('④ containers — 상속 목록은 읽기 전용, 「따로 정하기」로 복사한 뒤 추가·삭제·되돌리기', () => {
    const onChange = vi.fn();
    const { rerender } = render(
      <ScrollRestorationForm own={undefined} baseValue={{ containers: ['#admin-main'] }} onChange={onChange} t={t} />,
    );
    expect(source('containers')).toBe('base');
    expect(screen.getByTestId('g7le-scroll-container-0')).toHaveTextContent('#admin-main');
    expect(screen.queryByTestId('g7le-scroll-container-remove-0')).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-scroll-containers-override'));
    expect(onChange).toHaveBeenLastCalledWith({ containers: ['#admin-main'] });

    rerender(
      <ScrollRestorationForm own={{ containers: ['#admin-main'] }} baseValue={{ containers: ['#admin-main'] }} onChange={onChange} t={t} />,
    );
    fireEvent.change(screen.getByTestId('g7le-scroll-container-input'), { target: { value: '.side-panel' } });
    fireEvent.click(screen.getByTestId('g7le-scroll-container-add'));
    expect(onChange).toHaveBeenLastCalledWith({ containers: ['#admin-main', '.side-panel'] });

    fireEvent.click(screen.getByTestId('g7le-scroll-container-remove-0'));
    // 빈 목록도 「이 화면은 내부 영역 없음」이라는 지정이다(키 유지)
    expect(onChange).toHaveBeenLastCalledWith({ containers: [] });

    fireEvent.click(screen.getByTestId('g7le-scroll-containers-revert'));
    expect(onChange).toHaveBeenLastCalledWith(undefined);
  });

  /**
   * @effects scroll_form_snapshot_state_list_accepts_only_global_or_local_state_paths
   */
  it('④ snapshot.state — 상속 목록 읽기 전용 → 따로 정하기 → 상태 경로만 추가(형식 밖은 오류) → 되돌리기', () => {
    const onChange = vi.fn();
    const { rerender } = render(
      <ScrollRestorationForm
        own={{ mode: 'top' }}
        templateValue={{ snapshot: { state: ['_global.infiniteScroll'] } }}
        onChange={onChange}
        t={t}
      />,
    );
    expect(source('snapshot_state')).toBe('template');
    expect(screen.getByTestId('g7le-scroll-state-0')).toHaveTextContent('_global.infiniteScroll');
    expect(screen.queryByTestId('g7le-scroll-state-remove-0')).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-scroll-states-override'));
    expect(onChange).toHaveBeenLastCalledWith({ mode: 'top', snapshot: { state: ['_global.infiniteScroll'] } });

    rerender(
      <ScrollRestorationForm
        own={{ mode: 'top', snapshot: { state: ['_global.infiniteScroll'] } }}
        templateValue={{ snapshot: { state: ['_global.infiniteScroll'] } }}
        onChange={onChange}
        t={t}
      />,
    );
    expect(source('snapshot_state')).toBe('own');
    onChange.mockClear();
    // 형식 밖(_global./_local. 로 시작하지 않음)은 추가하지 않고 오류를 보인다
    fireEvent.change(screen.getByTestId('g7le-scroll-state-input'), { target: { value: 'window.page' } });
    fireEvent.click(screen.getByTestId('g7le-scroll-state-add'));
    expect(onChange).not.toHaveBeenCalled();
    expect(screen.getByTestId('g7le-scroll-state-error')).toHaveTextContent('snapshot_state_invalid');

    fireEvent.change(screen.getByTestId('g7le-scroll-state-input'), { target: { value: ' _local.page ' } });
    expect(screen.queryByTestId('g7le-scroll-state-error')).not.toBeInTheDocument();
    fireEvent.keyDown(screen.getByTestId('g7le-scroll-state-input'), { key: 'Enter' });
    expect(onChange).toHaveBeenLastCalledWith({ mode: 'top', snapshot: { state: ['_global.infiniteScroll', '_local.page'] } });

    fireEvent.click(screen.getByTestId('g7le-scroll-states-revert'));
    expect(onChange).toHaveBeenLastCalledWith({ mode: 'top' });
  });

  /**
   * @effects scroll_form_numbers_clamped_to_server_rule_range
   */
  it('⑤ 숫자 칸은 범위로 고정하고 숫자가 아니면 무시한다', () => {
    const onChange = vi.fn();
    render(<ScrollRestorationForm own={undefined} onChange={onChange} t={t} />);
    fireEvent.change(screen.getByTestId('g7le-scroll-timeout'), { target: { value: '999999' } });
    expect(onChange).toHaveBeenLastCalledWith({ timeout_ms: 60000 });
    fireEvent.change(screen.getByTestId('g7le-scroll-snapshot-ttl'), { target: { value: '100000' } });
    expect(onChange).toHaveBeenLastCalledWith({ snapshot: { ttl_seconds: 86400 } });
    onChange.mockClear();
    fireEvent.change(screen.getByTestId('g7le-scroll-timeout'), { target: { value: 'abc' } });
    expect(onChange).not.toHaveBeenCalled();
  });

  /**
   * @effects scroll_form_base_layout_banner_marks_template_default
   */
  it('⑥ 베이스 레이아웃이면 템플릿 기본값 안내·배지, 「모두 지우기」는 undefined', () => {
    const onChange = vi.fn();
    render(<ScrollRestorationForm own={{ mode: 'top' }} isBaseLayout onChange={onChange} t={t} />);
    expect(screen.getByTestId('g7le-scroll-banner-base')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-scroll-banner-route')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-scroll-source-mode')).toHaveTextContent('own_badge_base');
    fireEvent.click(screen.getByTestId('g7le-scroll-reset-all'));
    expect(onChange).toHaveBeenLastCalledWith(undefined);
  });

  /**
   * @effects scroll_form_base_layout_banner_marks_template_default
   */
  it('⑥ 화면 레이아웃이면 상속 순서 안내', () => {
    render(<ScrollRestorationForm own={undefined} onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-scroll-banner-route')).toBeInTheDocument();
  });
});

describe('scrollRestorationValue — 순수 함수 (서버 mergeScrollRestoration 과 같은 규칙)', () => {
  /**
   * @effects scroll_form_empty_field_removes_key_to_inherit
   */
  it('normalizeScrollOwn — undefined 키·빈 snapshot 제거, 남은 게 없으면 undefined', () => {
    expect(normalizeScrollOwn({ mode: undefined, snapshot: {} })).toBeUndefined();
    expect(normalizeScrollOwn({ mode: 'top', snapshot: { enabled: undefined, ttl_seconds: 0 } })).toEqual({
      mode: 'top',
      snapshot: { ttl_seconds: 0 },
    });
    expect(normalizeScrollOwn({ containers: [] })).toEqual({ containers: [] });
  });

  /**
   * @effects scroll_restoration_meta_saved_and_deep_merged_base_to_route
   */
  it('mergeScrollLayers — snapshot.state 도 목록이라 통째로 바꾼다', () => {
    expect(
      mergeScrollLayers(
        { snapshot: { ttl_seconds: 300, state: ['_global.infiniteScroll', '_local.page'] } },
        { snapshot: { state: ['_local.cursor'] } },
      ),
    ).toEqual({ snapshot: { ttl_seconds: 300, state: ['_local.cursor'] } });
  });

  /**
   * @effects scroll_restoration_meta_saved_and_deep_merged_base_to_route
   */
  it('mergeScrollLayers — 최상위·snapshot 은 키 단위, containers 는 통째로', () => {
    expect(
      mergeScrollLayers(
        { mode: 'top', containers: ['#a', '#b'], snapshot: { enabled: true, ttl_seconds: 300 } },
        { containers: ['#z'], snapshot: { ttl_seconds: 60 } },
      ),
    ).toEqual({ mode: 'top', containers: ['#z'], snapshot: { enabled: true, ttl_seconds: 60 } });
    expect(mergeScrollLayers(undefined, undefined)).toBeUndefined();
    expect(mergeScrollLayers({ mode: 'top' }, undefined)).toEqual({ mode: 'top' });
  });

  /**
   * @effects shell_scroll_tab_derives_base_as_merged_minus_own_per_snapshot_key
   */
  it('deriveInheritedScroll — 병합본에서 own 이 적은 키(snapshot 은 하위 키)를 뺀다', () => {
    expect(
      deriveInheritedScroll(
        { mode: 'top', wait: 'data', snapshot: { enabled: false, ttl_seconds: 60 } },
        { wait: 'data', snapshot: { ttl_seconds: 60 } },
      ),
    ).toEqual({ mode: 'top', snapshot: { enabled: false } });
    expect(deriveInheritedScroll({ mode: 'top' }, { mode: 'none' })).toBeUndefined();
    expect(deriveInheritedScroll(undefined, { mode: 'none' })).toBeUndefined();
  });
});
