/**
 * property-controls.test.tsx — 속성 컨트롤 컴포넌트 6종 RTL
 *
 * 계획서 이 요구한 컨트롤 컴포넌트 단위 RTL. PropertyEditorModal 통합 테스트가
 * 간접 커버하던 것을 컴포넌트별 props/이벤트/변경 콜백 단위로 분해 검증한다:
 *
 *  - ColorPickerControl — HEX 입력 / 프리셋 / 네이티브 피커 / `기본`(미적용) / 외부값 동기
 *  - ImagePickerControl — URL 입력 / 업로드(fetch mock) / 표시 방식 4종 / `기본`
 *  - TagInputControl — 후보 칩 추가, ✕ 제거, 후보 외 자유입력 비허용, 라벨=표시명·내부=키
 *  - CompositeSettingsForm — 그룹/필드 렌더, 필드 변경 → props 패치
 *  - AdvancedPropsForm — advanced 화이트리스트 노출, 권한 TagInput, 무손실 보존 목록, 디그레이드 안내
 *  - ControlRenderer — widget 디스패치, applyRecipe 패치, 미등록 위젯 폴백, group 충돌 배지
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, within, cleanup } from '@testing-library/react';

import { ColorPickerControl } from '../../components/property-controls/ColorPickerControl';
import { ImagePickerControl } from '../../components/property-controls/ImagePickerControl';
import { TagInputControl } from '../../components/property-controls/TagInputControl';
import { CompositeSettingsForm } from '../../components/property-controls/CompositeSettingsForm';
import { AdvancedPropsForm } from '../../components/property-controls/AdvancedPropsForm';
import { ControlRenderer } from '../../components/property-controls/ControlRenderer';
import { registerWidget, clearWidgetRegistry } from '../../spec/widgetRegistry';
import { registerCoreWidgets, resetCoreWidgetRegistration } from '../../spec/registerCoreWidgets';
import { LayoutEditorProvider } from '../../LayoutEditorContext';
import { EditorModalProvider } from '../../EditorModalContext';
import type { EditorControlSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { CompositeSettingsSpec } from '../../components/ComponentPalette';

const t = (k: string, params?: Record<string, string | number>) =>
  params ? `${k}(${JSON.stringify(params)})` : k;

afterEach(() => cleanup());

// ============================================================================
// ColorPickerControl
// ============================================================================
describe('ColorPickerControl', () => {
  const ctrl: EditorControlSpec = { widget: 'color' };

  it('HEX 입력 후 blur → onChange(정규화된 #값)', () => {
    const onChange = vi.fn();
    render(<ColorPickerControl control={ctrl} value={undefined} onChange={onChange} t={t} />);
    const hex = screen.getByTestId('g7le-color-hex');
    fireEvent.change(hex, { target: { value: '1a1a1a' } });
    fireEvent.blur(hex, { target: { value: '1a1a1a' } });
    expect(onChange).toHaveBeenCalledWith('#1a1a1a');
  });

  it('프리셋 색상 클릭 → onChange(프리셋 HEX)', () => {
    const onChange = vi.fn();
    render(<ColorPickerControl control={ctrl} value={undefined} onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-color-preset-#dc2626'));
    expect(onChange).toHaveBeenCalledWith('#dc2626');
  });

  it('`기본` 버튼 → onChange(undefined) (미적용)', () => {
    const onChange = vi.fn();
    render(<ColorPickerControl control={ctrl} value="#abcdef" onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-color-clear'));
    expect(onChange).toHaveBeenCalledWith(undefined);
  });

  it('현재값(외부 패치/역해석)이 스와치/입력에 동기된다', () => {
    render(<ColorPickerControl control={ctrl} value="#0f172a" onChange={vi.fn()} t={t} />);
    const hex = screen.getByTestId('g7le-color-hex') as HTMLInputElement;
    expect(hex.value).toBe('#0f172a');
    // 선택된 프리셋에 outline 강조
    const preset = screen.getByTestId('g7le-color-preset-#0f172a');
    expect(preset.getAttribute('style') ?? '').toContain('2563eb');
  });

  it('잘못된 HEX 입력은 onChange 를 발화하지 않는다', () => {
    const onChange = vi.fn();
    render(<ColorPickerControl control={ctrl} value={undefined} onChange={onChange} t={t} />);
    const hex = screen.getByTestId('g7le-color-hex');
    fireEvent.blur(hex, { target: { value: 'zzz' } });
    expect(onChange).not.toHaveBeenCalled();
  });

  // Tailwind 색 토큰 프리셋 + 자유 HEX 다크 게이트
  const tokenCtrl: EditorControlSpec = {
    widget: 'color',
    apply: { type: 'classToken', tokenTemplate: 'text-[{value}]' },
    options: [
      { value: 'text-gray-900', swatch: '#111827', apply: { type: 'classToken', tokens: ['text-gray-900'] } },
      { value: 'text-blue-600', swatch: '#2563eb', apply: { type: 'classToken', tokens: ['text-blue-600'] } },
    ],
  } as unknown as EditorControlSpec;

  it('토큰 프리셋 선언 시 토큰 스와치 렌더 + 클릭 → onChange(토큰)', () => {
    const onChange = vi.fn();
    render(<ColorPickerControl control={tokenCtrl} value={undefined} onChange={onChange} t={t} />);
    expect(screen.getByTestId('g7le-color-token-presets')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-color-token-text-blue-600'));
    expect(onChange).toHaveBeenCalledWith('text-blue-600');
  });

  it('현재 토큰값이 활성 토큰 스와치에 표시된다', () => {
    render(<ColorPickerControl control={tokenCtrl} value="text-gray-900" onChange={vi.fn()} t={t} />);
    const sw = screen.getByTestId('g7le-color-token-text-gray-900');
    expect(sw.getAttribute('data-active')).toBe('true');
  });

  it('자유 HEX 입력은 라이트에서 정상(token 컨트롤도 control-level tokenTemplate 으로 자유색 허용)', () => {
    const onChange = vi.fn();
    render(<ColorPickerControl control={tokenCtrl} value={undefined} onChange={onChange} t={t} />);
    const hex = screen.getByTestId('g7le-color-hex');
    fireEvent.blur(hex, { target: { value: '#3a7bd5' } });
    expect(onChange).toHaveBeenCalledWith('#3a7bd5');
  });

  it('freeValueDisabled(다크) → 자유 입력 비노출 + 프리셋만 + 안내', () => {
    const onChange = vi.fn();
    render(<ColorPickerControl control={tokenCtrl} value={undefined} onChange={onChange} t={t} freeValueDisabled />);
    // 자유 HEX 입력칸 비노출
    expect(screen.queryByTestId('g7le-color-hex')).not.toBeInTheDocument();
    // 안내 + 프리셋 토큰은 여전히 클릭 가능
    expect(screen.getByTestId('g7le-color-free-disabled')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-color-token-text-gray-900'));
    expect(onChange).toHaveBeenCalledWith('text-gray-900');
  });
});

// ============================================================================
// ImagePickerControl (useLayoutEditor 필요 → Provider 래핑)
// ============================================================================
describe('ImagePickerControl', () => {
  const ctrl: EditorControlSpec = { widget: 'image' };

  function renderImage(value: unknown, onChange = vi.fn()) {
    render(
      <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale="ko">
        <EditorModalProvider>
          <ImagePickerControl control={ctrl} value={value} onChange={onChange} t={t} />
        </EditorModalProvider>
      </LayoutEditorProvider>,
    );
    return onChange;
  }

  beforeEach(() => {
    // 마운트 시 인라인 미니 갤러리가 첨부 목록(GET)을 호출하므로 기본 fetch stub 제공.
    // 업로드/실패 테스트는 각자 fetch 를 재stub 한다.
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, data: [] }) }),
    );
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('URL 입력 후 blur → onChange({ url, size, repeat, position })', () => {
    const onChange = renderImage(undefined);
    const url = screen.getByTestId('g7le-image-url');
    fireEvent.change(url, { target: { value: 'https://x/a.png' } });
    fireEvent.blur(url, { target: { value: 'https://x/a.png' } });
    expect(onChange).toHaveBeenCalled();
    const v = onChange.mock.calls.at(-1)![0] as Record<string, unknown>;
    expect(v.url).toBe('https://x/a.png');
    expect(v.size).toBe('cover'); // 기본 fill 모드
  });

  it('표시 방식 변경(맞춤) → size:contain', () => {
    const onChange = renderImage({ url: 'https://x/a.png', size: 'cover', repeat: 'no-repeat' });
    fireEvent.click(screen.getByTestId('g7le-image-mode-fit'));
    const v = onChange.mock.calls.at(-1)![0] as Record<string, unknown>;
    expect(v.size).toBe('contain');
  });

  it('업로드 → fetch mock 응답의 data.url 을 값으로 설정', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ success: true, data: { url: '/storage/up.png' } }),
    });
    vi.stubGlobal('fetch', fetchMock);
    const onChange = renderImage(undefined);
    const file = new File(['x'], 'up.png', { type: 'image/png' });
    fireEvent.change(screen.getByTestId('g7le-image-file'), { target: { files: [file] } });
    await vi.waitFor(() => expect(onChange).toHaveBeenCalled());
    const v = onChange.mock.calls.at(-1)![0] as Record<string, unknown>;
    expect(v.url).toBe('/storage/up.png');
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining('/api/admin/templates/sirsoft-basic/layout-attachments'),
      expect.objectContaining({ method: 'POST' }),
    );
  });

  it('업로드 실패 → 에러 메시지 표시', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, json: async () => ({ success: false }) }));
    renderImage(undefined);
    const file = new File(['x'], 'up.png', { type: 'image/png' });
    fireEvent.change(screen.getByTestId('g7le-image-file'), { target: { files: [file] } });
    await vi.waitFor(() => expect(screen.getByTestId('g7le-image-error')).toBeTruthy());
  });

  it('`기본` → onChange(undefined)', () => {
    const onChange = renderImage({ url: 'https://x/a.png' });
    fireEvent.click(screen.getByTestId('g7le-image-clear'));
    expect(onChange).toHaveBeenCalledWith(undefined);
  });
});

// ============================================================================
// TagInputControl
// ============================================================================
describe('TagInputControl', () => {
  const ctrl: EditorControlSpec = { widget: 'tag-input' };
  const candidates = [
    { value: 'core.users.view', label: '회원 조회' },
    { value: 'core.posts.manage', label: '게시글 관리' },
  ];

  it('후보 목록에서 칩 추가 → onChange([키])', () => {
    const onChange = vi.fn();
    render(<TagInputControl control={ctrl} value={undefined} onChange={onChange} t={t} candidates={candidates} />);
    fireEvent.click(screen.getByTestId('g7le-tag-add'));
    fireEvent.click(screen.getByTestId('g7le-tag-candidate-core.users.view'));
    expect(onChange).toHaveBeenCalledWith(['core.users.view']);
  });

  it('칩 라벨은 친화 표시명, 내부 값은 키', () => {
    render(<TagInputControl control={ctrl} value={['core.users.view']} onChange={vi.fn()} t={t} candidates={candidates} />);
    const chip = screen.getByTestId('g7le-tag-chip-core.users.view');
    expect(within(chip).getByText('회원 조회')).toBeTruthy();
  });

  it('칩에 라벨과 식별자(키)를 함께 표시 (라벨≠키일 때)', () => {
    render(<TagInputControl control={ctrl} value={['core.users.view']} onChange={vi.fn()} t={t} candidates={candidates} />);
    const chip = screen.getByTestId('g7le-tag-chip-core.users.view');
    expect(within(chip).getByText('회원 조회')).toBeTruthy();
    expect(within(chip).getByTestId('g7le-tag-chip-id-core.users.view').textContent).toBe('core.users.view');
  });

  it('후보 목록에도 식별자(키)를 함께 표시', () => {
    render(<TagInputControl control={ctrl} value={undefined} onChange={vi.fn()} t={t} candidates={candidates} />);
    fireEvent.click(screen.getByTestId('g7le-tag-add'));
    expect(screen.getByTestId('g7le-tag-candidate-id-core.users.view').textContent).toBe('core.users.view');
  });

  it('라벨이 키와 동일하면 식별자 중복 표시 안 함', () => {
    const sameKeyCandidates = [{ value: 'plain', label: 'plain' }];
    render(<TagInputControl control={ctrl} value={['plain']} onChange={vi.fn()} t={t} candidates={sameKeyCandidates} />);
    expect(screen.queryByTestId('g7le-tag-chip-id-plain')).toBeNull();
  });

  it('칩 ✕ → onChange(나머지) / 마지막 제거 시 undefined', () => {
    const onChange = vi.fn();
    render(<TagInputControl control={ctrl} value={['core.users.view']} onChange={onChange} t={t} candidates={candidates} />);
    fireEvent.click(screen.getByTestId('g7le-tag-remove-core.users.view'));
    expect(onChange).toHaveBeenCalledWith(undefined);
  });

  it('이미 선택된 항목은 후보 목록에서 제외 (후보 외 자유입력 불가 — 실재 후보만)', () => {
    render(<TagInputControl control={ctrl} value={['core.users.view']} onChange={vi.fn()} t={t} candidates={candidates} />);
    fireEvent.click(screen.getByTestId('g7le-tag-add'));
    expect(screen.queryByTestId('g7le-tag-candidate-core.users.view')).toBeNull();
    expect(screen.getByTestId('g7le-tag-candidate-core.posts.manage')).toBeTruthy();
  });

  it('후보가 모두 선택되면 추가 버튼 비활성', () => {
    render(
      <TagInputControl
        control={ctrl}
        value={['core.users.view', 'core.posts.manage']}
        onChange={vi.fn()}
        t={t}
        candidates={candidates}
      />,
    );
    expect((screen.getByTestId('g7le-tag-add') as HTMLButtonElement).disabled).toBe(true);
  });
});

// ============================================================================
// CompositeSettingsForm
// ============================================================================
describe('CompositeSettingsForm', () => {
  const spec: CompositeSettingsSpec = {
    groups: [
      {
        label: '$t:editor.component.recent_posts.group.binding',
        fields: [
          { key: 'count', label: '표시 개수', type: 'number', default: 6, min: 1, max: 20 },
          {
            key: 'listType',
            label: '유형',
            type: 'select',
            default: 'recent',
            options: [
              { value: 'recent', label: '최신글' },
              { value: 'popular', label: '인기글' },
            ],
          },
          {
            key: 'showFields',
            label: '표시 항목',
            type: 'checkbox-group',
            default: ['title'],
            options: [
              { value: 'title', label: '제목' },
              { value: 'author', label: '글쓴이' },
            ],
          },
        ],
      },
    ],
  };

  it('그룹·필드를 친화 폼으로 렌더한다', () => {
    render(<CompositeSettingsForm spec={spec} node={{ name: 'RecentPosts' }} t={t} onPatchProp={vi.fn()} />);
    expect(screen.getByTestId('g7le-composite-settings')).toBeTruthy();
    expect(screen.getByTestId('g7le-setting-count')).toBeTruthy();
    expect(screen.getByTestId('g7le-setting-listType')).toBeTruthy();
    expect(screen.getByTestId('g7le-setting-showFields')).toBeTruthy();
  });

  it('필드 기본값(default)이 입력에 반영된다 (스펙이 기본값 SSoT)', () => {
    render(<CompositeSettingsForm spec={spec} node={{ name: 'RecentPosts' }} t={t} onPatchProp={vi.fn()} />);
    const count = screen.getByTestId('g7le-setting-input-count') as HTMLInputElement;
    expect(count.value).toBe('6');
  });

  it('number 필드 변경 → onPatchProp(key, number)', () => {
    const onPatchProp = vi.fn();
    render(<CompositeSettingsForm spec={spec} node={{ name: 'RecentPosts' }} t={t} onPatchProp={onPatchProp} />);
    fireEvent.change(screen.getByTestId('g7le-setting-input-count'), { target: { value: '10' } });
    expect(onPatchProp).toHaveBeenCalledWith('count', 10);
  });

  it('select 필드 변경 → onPatchProp(key, optionValue)', () => {
    const onPatchProp = vi.fn();
    render(<CompositeSettingsForm spec={spec} node={{ name: 'RecentPosts' }} t={t} onPatchProp={onPatchProp} />);
    fireEvent.change(screen.getByTestId('g7le-setting-input-listType'), { target: { value: 'popular' } });
    expect(onPatchProp).toHaveBeenCalledWith('listType', 'popular');
  });

  it('checkbox-group 체크 → onPatchProp(key, 배열)', () => {
    const onPatchProp = vi.fn();
    render(
      <CompositeSettingsForm
        spec={spec}
        node={{ name: 'RecentPosts', props: { showFields: ['title'] } }}
        t={t}
        onPatchProp={onPatchProp}
      />,
    );
    const group = screen.getByTestId('g7le-setting-input-showFields');
    const authorCb = within(group).getAllByRole('checkbox')[1];
    fireEvent.click(authorCb);
    expect(onPatchProp).toHaveBeenCalledWith('showFields', ['title', 'author']);
  });

  it('인스턴스 props 값이 default 보다 우선한다', () => {
    render(
      <CompositeSettingsForm
        spec={spec}
        node={{ name: 'RecentPosts', props: { count: 12 } }}
        t={t}
        onPatchProp={vi.fn()}
      />,
    );
    expect((screen.getByTestId('g7le-setting-input-count') as HTMLInputElement).value).toBe('12');
  });
});

// ============================================================================
// AdvancedPropsForm
// ============================================================================
describe('AdvancedPropsForm', () => {
  const permissionCandidates = [{ value: 'core.users.view', label: '회원 조회' }];

  it('advanced 화이트리스트의 permissions → TagInput 노출', () => {
    render(
      <AdvancedPropsForm
        node={{ name: 'Div' }}
        advanced={['permissions']}
        t={t}
        onPatch={vi.fn()}
        permissionCandidates={permissionCandidates}
      />,
    );
    expect(screen.getByTestId('g7le-advanced-permissions')).toBeTruthy();
  });

  it('권한 칩 추가 → onPatch(node.permissions)', () => {
    const onPatch = vi.fn();
    render(
      <AdvancedPropsForm
        node={{ name: 'Div' }}
        advanced={['permissions']}
        t={t}
        onPatch={onPatch}
        permissionCandidates={permissionCandidates}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-tag-add'));
    fireEvent.click(screen.getByTestId('g7le-tag-candidate-core.users.view'));
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    expect(patched.permissions).toEqual(['core.users.view']);
  });

  it('blur_until_loaded 토글 → onPatch(true)', () => {
    const onPatch = vi.fn();
    render(<AdvancedPropsForm node={{ name: 'Div' }} advanced={['blur_until_loaded']} t={t} onPatch={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-advanced-blur-toggle'));
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    expect(patched.blur_until_loaded).toBe(true);
  });

  it('comment 입력 → onPatch(comment)', () => {
    const onPatch = vi.fn();
    render(<AdvancedPropsForm node={{ name: 'Div' }} advanced={['comment']} t={t} onPatch={onPatch} />);
    fireEvent.change(screen.getByTestId('g7le-advanced-comment-input'), { target: { value: '메모' } });
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    expect(patched.comment).toBe('메모');
  });

  it('Phase 4 미지원 고급 속성(lifecycle 등)은 "추후 지원" 안내로 디그레이드', () => {
    render(<AdvancedPropsForm node={{ name: 'Div' }} advanced={['onMount']} t={t} onPatch={vi.fn()} />);
    expect(screen.getByTestId('g7le-advanced-deferred-onMount')).toBeTruthy();
  });

  it('개발자 속성/복잡 표현식은 읽기 전용 보존 목록에 표시 (무손실)', () => {
    render(
      <AdvancedPropsForm
        node={{ name: 'Div', props: { data_binding: { source: 'x' }, title: '{{user.name}}' } }}
        advanced={[]}
        t={t}
        onPatch={vi.fn()}
      />,
    );
    const box = screen.getByTestId('g7le-advanced-preserved');
    expect(within(box).getByText(/data_binding/)).toBeTruthy();
    expect(within(box).getByText(/props\.title/)).toBeTruthy();
  });
});

// ============================================================================
// ControlRenderer
// ============================================================================
describe('ControlRenderer', () => {
  beforeAll(() => registerCoreWidgets());

  const textAlign: EditorControlSpec = {
    widget: 'segmented',
    label: '$t:editor.control.text_align',
    group: 'text-align',
    options: [
      { value: 'left', apply: { type: 'classToken', tokens: ['text-left'] } },
      { value: 'center', apply: { type: 'classToken', tokens: ['text-center'] } },
    ],
  };

  it('control.widget → 위젯 디스패치 + 라벨 $t: 해석', () => {
    render(<ControlRenderer controlKey="textAlign" control={textAlign} node={{ name: 'H1' }} t={t} onPatch={vi.fn()} />);
    expect(screen.getByTestId('g7le-control-textAlign')).toBeTruthy();
    expect(screen.getByText('editor.control.text_align')).toBeTruthy();
    expect(screen.getByTestId('g7le-widget-segmented')).toBeTruthy();
  });

  it('컨트롤 행/위젯 컨테이너에 minWidth:0 가드 — 위젯 min-content 가 본문보다 넓어져 가로 스크롤 만드는 것 차단', () => {
    render(<ControlRenderer controlKey="textAlign" control={textAlign} node={{ name: 'H1' }} t={t} onPatch={vi.fn()} />);
    const row = screen.getByTestId('g7le-control-textAlign');
    // 행이 부모(본문) 폭 안에서 줄어들 수 있어야 한다(systemic 가드). jsdom 은 단위없는 0 을 "0" 으로 렌더.
    expect(['0', '0px']).toContain(row.style.minWidth);
    // 위젯 컨테이너(flex:1)도 minWidth:0 — 위젯이 min-content 로 행을 밀지 않게.
    const widgetBox = row.querySelector('.g7le-control-widget') as HTMLElement;
    expect(['0', '0px']).toContain(widgetBox.style.minWidth);
  });

  it('값 변경 → applyRecipe → onPatch(패치된 노드)', () => {
    const onPatch = vi.fn();
    render(<ControlRenderer controlKey="textAlign" control={textAlign} node={{ name: 'H1' }} t={t} onPatch={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-segment-center'));
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    expect(patched.props?.className).toBe('text-center');
  });

  it('현재값(reverseResolve)을 위젯에 반영한다', () => {
    render(
      <ControlRenderer
        controlKey="textAlign"
        control={textAlign}
        node={{ name: 'H1', props: { className: 'text-center' } }}
        t={t}
        onPatch={vi.fn()}
      />,
    );
    // center 세그먼트가 활성 상태로 표시 (data-active)
    const seg = screen.getByTestId('g7le-segment-center');
    expect(seg.getAttribute('data-active')).toBe('true');
  });

  it('같은 group 토큰 충돌 시 conflict 배지 표시', () => {
    render(
      <ControlRenderer
        controlKey="textAlign"
        control={textAlign}
        node={{ name: 'H1', props: { className: 'text-left text-center' } }}
        t={t}
        onPatch={vi.fn()}
      />,
    );
    expect(screen.getByTestId('g7le-control-conflict-textAlign')).toBeTruthy();
  });

  // scope 전달 + 다크 읽기전용 게이트 + D6 placeholder.
  // (clearWidgetRegistry 를 호출하는 '미등록 위젯' 테스트보다 **앞**에 둔다 — 그 테스트
  //  뒤에서는 registerCoreWidgets 가 멱등 no-op 이라 위젯 레지스트리가 비어 widget 디스패치가
  //  실패한다. SpacingWidget describe 가 resetCoreWidgetRegistration 로 복구.)
  const textColor: EditorControlSpec = {
    widget: 'color',
    label: 'color',
    group: 'text-color',
    apply: { type: 'styleProp', prop: 'color' },
  };

  it('다크 scope + styleProp(인라인) 컨트롤 → 읽기전용 안내 + 패치 미발생 (D4)', () => {
    const onPatch = vi.fn();
    render(
      <ControlRenderer
        controlKey="textColor"
        control={textColor}
        node={{ name: 'Div', props: { style: { color: 'red' } } }}
        t={t}
        onPatch={onPatch}
        scope={{ colorScheme: 'dark', breakpoint: 'base' }}
      />,
    );
    expect(screen.getByTestId('g7le-control-dark-readonly-textColor')).toBeInTheDocument();
    expect(onPatch).not.toHaveBeenCalled();
  });

  it('다크 scope + classToken 컨트롤 → 편집 가능(읽기전용 아님)', () => {
    render(
      <ControlRenderer
        controlKey="textAlign"
        control={textAlign}
        node={{ name: 'H1' }}
        t={t}
        onPatch={vi.fn()}
        scope={{ colorScheme: 'dark', breakpoint: 'base' }}
      />,
    );
    expect(screen.queryByTestId('g7le-control-dark-readonly-textAlign')).toBeNull();
    expect(screen.getByTestId('g7le-widget-segmented')).toBeTruthy();
  });

  it('tablet scope + base 상속값만 → placeholder(흐릿) 표시 (D6)', () => {
    render(
      <ControlRenderer
        controlKey="textAlign"
        control={textAlign}
        node={{ name: 'H1', props: { className: 'text-center' } }}
        t={t}
        onPatch={vi.fn()}
        scope={{ colorScheme: 'base', breakpoint: 'tablet' }}
      />,
    );
    const widget = screen.getByTestId('g7le-control-textAlign').querySelector('.g7le-control-widget');
    expect(widget?.getAttribute('data-placeholder')).toBe('true');
  });

  it('tablet scope 값 변경 → applyRecipe(tablet scope) → responsive.tablet.props', () => {
    const onPatch = vi.fn();
    render(
      <ControlRenderer
        controlKey="textAlign"
        control={textAlign}
        node={{ name: 'H1' }}
        t={t}
        onPatch={onPatch}
        scope={{ colorScheme: 'base', breakpoint: 'tablet' }}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-segment-center'));
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    expect(patched.responsive?.tablet?.props?.className).toBe('text-center');
    expect(patched.props?.className).toBeUndefined();
  });

  // 레지스트리를 비우는 테스트는 같은 describe 의 **마지막**에 둔다(다른 테스트 오염 방지).
  it('미등록 위젯 → "지원하지 않는 위젯" 폴백', () => {
    clearWidgetRegistry(); // 레지스트리 비우기
    const ghost: EditorControlSpec = { widget: 'nonexistent-widget' };
    render(<ControlRenderer controlKey="ghost" control={ghost} node={{ name: 'X' }} t={t} onPatch={vi.fn()} />);
    expect(screen.getByTestId('g7le-control-unsupported-ghost')).toBeTruthy();
    registerCoreWidgets(); // 후속 테스트 위해 복구
  });
});

// §항목B — 안쪽/바깥쪽 여백 방향 지정(spacing 위젯). 종전 paddingAll/marginAll 은
// 전 방향 단일 슬라이더만 지원해 좌/우/상/하·가로/세로를 못 정했다.
describe('SpacingWidget (여백 방향+크기)', () => {
  // 앞선 '미등록 위젯' 테스트가 clearWidgetRegistry() 후 (멱등) registerCoreWidgets()
  // 를 호출해 no-op 이 되므로, 등록 플래그를 리셋해 spacing 위젯을 확실히 재등록한다.
  beforeAll(() => {
    resetCoreWidgetRegistration();
    registerCoreWidgets();
  });

  const padding: EditorControlSpec = {
    widget: 'spacing',
    label: '$t:editor.control.padding.label',
    group: 'padding',
    spacingPrefix: 'p',
    scale: ['0', '1', '2', '3', '4', '6', '8'],
    apply: { type: 'classToken', tokenTemplate: '{value}' },
    groupPrefixes: ['p-', 'px-', 'py-', 'pt-', 'pr-', 'pb-', 'pl-'],
  } as unknown as EditorControlSpec;

  it('일괄/개별 모드 토글 렌더 + 일괄 슬라이더', () => {
    render(<ControlRenderer controlKey="paddingAll" control={padding} node={{ name: 'Div' }} t={t} onPatch={vi.fn()} />);
    expect(screen.getByTestId('g7le-widget-spacing')).toBeTruthy();
    expect(screen.getByTestId('g7le-spacing-mode-all')).toBeTruthy();
    expect(screen.getByTestId('g7le-spacing-mode-sides')).toBeTruthy();
    // 기본 일괄 모드 — all 슬라이더 노출
    expect(screen.getByTestId('g7le-spacing-all-range')).toBeTruthy();
  });

  it('일괄 사용 체크 → 전체 토큰(p-4) 적용', () => {
    const onPatch = vi.fn();
    render(<ControlRenderer controlKey="paddingAll" control={padding} node={{ name: 'Div' }} t={t} onPatch={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-spacing-all-enabled'));
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    expect(patched.props?.className).toBe('p-4'); // 기본 step index 4
  });

  // 상/우/하/좌를 각각 다른 값으로 줄 수 있어야 한다.
  it('개별 모드 — 상/우/하/좌 슬라이더 4개 노출', () => {
    render(<ControlRenderer controlKey="paddingAll" control={padding} node={{ name: 'Div' }} t={t} onPatch={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-spacing-mode-sides'));
    for (const s of ['t', 'r', 'b', 'l']) {
      expect(screen.getByTestId(`g7le-spacing-side-${s}`)).toBeTruthy();
      expect(screen.getByTestId(`g7le-spacing-side-${s}-range`)).toBeTruthy();
    }
  });

  it('개별 측값 공존 — pt-4 와 pl-2 가 동시에 유지된다', () => {
    // 시작: 상=pt-4 이미 적용. 좌 슬라이더를 켜면 pl-* 가 추가되며 pt-4 는 보존.
    const onPatch = vi.fn();
    render(
      <ControlRenderer
        controlKey="paddingAll"
        control={padding}
        node={{ name: 'Div', props: { className: 'w-full pt-4' } }}
        t={t}
        onPatch={onPatch}
      />,
    );
    // 이미 측값(pt-4) 보유 → 개별 모드 자동. 좌(l) 사용 체크.
    fireEvent.click(screen.getByTestId('g7le-spacing-side-l-enabled'));
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    const tokens = String(patched.props?.className).split(/\s+/);
    expect(tokens).toContain('w-full');
    expect(tokens).toContain('pt-4'); // 상 보존
    expect(tokens.some((x) => x.startsWith('pl-'))).toBe(true); // 좌 추가 공존
  });

  it('개별 측값 — 상=4 / 좌=2 서로 다른 값 동시 적용', () => {
    // pt-4 pl-2 를 가진 노드 → 상 슬라이더는 step(4), 좌 슬라이더는 step(2)로 역해석.
    render(
      <ControlRenderer
        controlKey="paddingAll"
        control={padding}
        node={{ name: 'Div', props: { className: 'pt-4 pl-2' } }}
        t={t}
        onPatch={vi.fn()}
      />,
    );
    // 개별 모드(측값 보유)에서 각 측 value 표시 확인
    expect(screen.getByTestId('g7le-spacing-side-t-value').textContent).toBe('4');
    expect(screen.getByTestId('g7le-spacing-side-l-value').textContent).toBe('2');
    // 우/하는 미설정(–)
    expect(screen.getByTestId('g7le-spacing-side-r-value').textContent).toBe('–');
  });

  it('현재값 역해석 — px-2 는 좌/우 양측에 반영', () => {
    render(<ControlRenderer controlKey="paddingAll" control={padding} node={{ name: 'Div', props: { className: 'px-2' } }} t={t} onPatch={vi.fn()} />);
    expect(screen.getByTestId('g7le-spacing-side-l-value').textContent).toBe('2');
    expect(screen.getByTestId('g7le-spacing-side-r-value').textContent).toBe('2');
  });

  it('무관 토큰(w-full)만 있으면 미적용 — 일괄 모드 + all 미설정', () => {
    render(<ControlRenderer controlKey="paddingAll" control={padding} node={{ name: 'Div', props: { className: 'w-full max-w-md' } }} t={t} onPatch={vi.fn()} />);
    expect((screen.getByTestId('g7le-spacing-all-enabled') as HTMLInputElement).checked).toBe(false);
  });
});
