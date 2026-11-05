/**
 * @file TemplateSettingsFields.test.tsx
 * @description 템플릿 설정 공용 화면의 칸 그리기를 잠근다 (#434).
 *
 * - 섹션마다 카드 하나, 칸 종류별 입력, 값이 바뀌면 폼 전체(섹션 중첩)를 올린다
 * - 업로더 콜백이 연달아 와도 앞 결과를 덮지 않는다(최신 값 이어 붙이기)
 * - 목록 칸 추가·삭제·이동·최대 개수, 보임 조건, 읽기 전용, 서버 오류 표시
 * - 첨부 칸은 응답의 컬렉션·형식·개수와 공통 업로드 신호를 업로더에 넘긴다
 * - 칸 종류별 부품: text 여러 줄, color 고르개+16진 글자, 선택지 5개 이상 목록 상자, email·url 입력 형식, boolean 스위치
 * - 다국어 칸은 언어 탭 — 탭을 바꾸면 그 언어 값을 보이고, 입력은 그 언어 키에만 쓴다
 *
 * @scenario case=settings_layout
 *
 * @effects settings_layout_saves_template_settings
 * @effects field_type_controls_rendered
 * @effects multilingual_field_language_tabs
 */

import React from 'react';
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';

const uploaderProps: Record<string, any>[] = [];

vi.mock('../../components/composite/FileUploader', () => ({
  FileUploader: (props: Record<string, any>) => {
    uploaderProps.push(props);

    return <div data-testid={`uploader-${props.collection}`} />;
  },
}));

// 언어 탭 시험만 실제 다국어 입력을 쓴다 — 나머지는 한 칸짜리 대역으로 둔다
const multilingualMode = vi.hoisted(() => ({ real: false }));

vi.mock('../../components/composite/MultilingualInput', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../components/composite/MultilingualInput')>();

  return {
    ...actual,
    MultilingualInput: (props: Record<string, any>) =>
      multilingualMode.real ? (
        <actual.MultilingualInput {...(props as any)} />
      ) : (
        <input
          data-testid="multilingual"
          aria-label={props.ariaLabel}
          value={props.value?.ko ?? ''}
          disabled={props.disabled}
          onChange={(e) => props.onChange?.({ target: { value: { ...(props.value ?? {}), ko: e.target.value } } })}
        />
      ),
  };
});

import { TemplateSettingsFields, type TemplateSettingSection } from '../TemplateSettingsFields';

const sections: TemplateSettingSection[] = [
  {
    key: 'branding',
    label: '브랜딩',
    hint: '비운 칸은 기본값',
    fields: [
      { key: 'favicon', type: 'attachments', label: '파비콘', collection: 'template_settings.qa.branding.favicon', max_files: 1, accept: '.ico,.png', max_size_kb: 1024 },
      { key: 'accent', type: 'enum', label: '포인트 색', options: [{ value: 'blue', label: '파랑' }, { value: 'green', label: '초록' }] },
      { key: 'logo_dark', type: 'image', label: '다크 로고', collection: 'template_settings.qa.branding.logo_dark', max_files: 1 },
    ],
  },
  {
    key: 'header',
    label: '헤더',
    fields: [
      { key: 'max_boards', type: 'integer', label: '게시판 개수', min: 1, max: 10 },
      { key: 'show_theme_toggle', type: 'boolean', label: '테마 버튼' },
      { key: 'note', type: 'string', label: '메모', visible_if: { field: 'show_theme_toggle', equals: true } },
      {
        key: 'links',
        type: 'list',
        label: '바로가기',
        max_items: 2,
        item_fields: [
          { key: 'label', type: 'multilingual', label: '이름', required: true },
          { key: 'url', type: 'link', label: '주소', required: true },
        ],
      },
    ],
  },
];

const value = {
  branding: { favicon: [], accent: 'blue', logo_dark: [] },
  header: { max_boards: 5, show_theme_toggle: false, note: '', links: [{ label: { ko: '공지' }, url: '/notice' }] },
};

describe('TemplateSettingsFields (#434)', () => {
  beforeEach(() => {
    uploaderProps.length = 0;
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings
  it('섹션마다 카드·라벨·안내를 그리고, 바뀐 칸을 섹션 중첩 폼 전체로 올린다', () => {
    const onChange = vi.fn();
    const { container } = render(<TemplateSettingsFields sections={sections} value={value} onChange={onChange} />);

    expect(screen.getByText('브랜딩')).toBeTruthy();
    expect(screen.getByText('헤더')).toBeTruthy();
    // 카드·칸 틀은 id 로도 찾는다(E2E 는 `#template_settings_section_{섹션}`·`#template_settings_field_{섹션}_{키}`)
    expect(container.querySelector('#template_settings_section_branding')?.className).toContain('admin-card');
    expect(container.querySelector('#template_settings_field_header_max_boards')).not.toBeNull();
    expect(screen.getByText('비운 칸은 기본값')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('게시판 개수'), { target: { value: '7' } });
    expect(onChange).toHaveBeenLastCalledWith({ ...value, header: { ...value.header, max_boards: 7 } });

    fireEvent.click(screen.getByLabelText('초록'));
    expect(onChange.mock.lastCall[0].branding.accent).toBe('green');
    // 앞서 바꾼 칸이 그대로 이어진다(최신 값 위에 쌓는다)
    expect(onChange.mock.lastCall[0].header.max_boards).toBe(7);
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings
  it('첨부 칸은 응답의 컬렉션·형식·개수·공통 업로드 신호로 지연 업로드하고, 연달아 끝난 업로드가 서로 덮지 않는다', () => {
    const onChange = vi.fn();
    render(<TemplateSettingsFields sections={sections} value={value} onChange={onChange} uploadTriggerEvent="upload:template_settings" />);

    const favicon = uploaderProps.find((p) => p.collection === 'template_settings.qa.branding.favicon')!;
    const logo = uploaderProps.find((p) => p.collection === 'template_settings.qa.branding.logo_dark')!;

    expect(favicon).toMatchObject({ maxFiles: 1, accept: '.ico,.png', autoUpload: false, uploadTriggerEvent: 'upload:template_settings', maxSize: 1 });
    expect(favicon.imageCompression).toBeUndefined();

    favicon.onUploadComplete([{ id: 11, hash: 'a' }]);
    logo.onUploadComplete([{ id: 12, hash: 'b' }]);

    expect(onChange.mock.lastCall[0].branding.favicon).toEqual([{ id: 11, hash: 'a' }]);
    expect(onChange.mock.lastCall[0].branding.logo_dark).toEqual([{ id: 12, hash: 'b' }]);
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings
  it('파일만 고르면 onDirty 로 알린다', () => {
    const onDirty = vi.fn();
    render(<TemplateSettingsFields sections={sections} value={value} onDirty={onDirty} />);

    uploaderProps[0].onFilesChange([{ id: 'p1' }]);
    expect(onDirty).toHaveBeenCalledTimes(1);
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings
  it('목록 칸은 추가·삭제·이동하고 최대 개수에서 추가를 막는다', () => {
    const onChange = vi.fn();
    const { rerender } = render(<TemplateSettingsFields sections={sections} value={value} onChange={onChange} />);

    fireEvent.click(screen.getByText(/settings.screen.fields.add_item|Add/));
    const added = onChange.mock.lastCall[0].header.links;
    expect(added).toHaveLength(2);
    expect(added[1]).toEqual({ label: {}, url: '' });

    const two = { ...value, header: { ...value.header, links: [{ label: { ko: 'A' }, url: '/a' }, { label: { ko: 'B' }, url: '/b' }] } };
    rerender(<TemplateSettingsFields sections={sections} value={two} onChange={onChange} />);

    expect((screen.getByText(/settings.screen.fields.add_item|Add/).closest('button') as HTMLButtonElement).disabled).toBe(true);

    fireEvent.click(screen.getAllByLabelText('Move down')[0]);
    expect(onChange.mock.lastCall[0].header.links.map((row: any) => row.url)).toEqual(['/b', '/a']);

    fireEvent.click(screen.getAllByLabelText('Remove')[1]);
    expect(onChange.mock.lastCall[0].header.links.map((row: any) => row.url)).toEqual(['/a']);
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings
  it('보임 조건이 거짓이면 그 칸을 숨긴다', () => {
    const { rerender } = render(<TemplateSettingsFields sections={sections} value={value} />);
    expect(screen.queryByLabelText('메모')).toBeNull();

    rerender(<TemplateSettingsFields sections={sections} value={{ ...value, header: { ...value.header, show_theme_toggle: true } }} />);
    expect(screen.getByLabelText('메모')).toBeTruthy();
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings
  it('읽기 전용이면 입력·업로더가 잠기고 목록 편집 단추가 없다', () => {
    render(<TemplateSettingsFields sections={sections} value={value} disabled />);

    expect((screen.getByLabelText('게시판 개수') as HTMLInputElement).disabled).toBe(true);
    expect(uploaderProps.every((p) => p.disabled === true)).toBe(true);
    expect(screen.queryByLabelText('Remove')).toBeNull();
    expect(screen.queryByText(/settings.screen.fields.add_item|Add/)).toBeNull();
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings
  it('서버 검증 오류를 칸 아래에 보인다(목록 행 칸 포함)', () => {
    render(
      <TemplateSettingsFields
        sections={sections}
        value={value}
        errors={{ 'header.max_boards': ['10 이하여야 합니다'], 'header.links.0.url': ['주소가 올바르지 않습니다'] }}
      />,
    );

    expect(screen.getByText('10 이하여야 합니다')).toBeTruthy();
    expect(screen.getByText('주소가 올바르지 않습니다')).toBeTruthy();
  });
});

const typedSections: TemplateSettingSection[] = [
  {
    key: 'profile',
    label: '소개',
    fields: [
      { key: 'intro', type: 'text', label: '소개 글', max_length: 500 },
      { key: 'brand_color', type: 'color', label: '브랜드 색' },
      {
        key: 'layout',
        type: 'enum',
        label: '배치',
        options: [
          { value: 'a', label: '가' },
          { value: 'b', label: '나' },
          { value: 'c', label: '다' },
          { value: 'd', label: '라' },
          { value: 'e', label: '마' },
        ],
      },
      { key: 'contact', type: 'email', label: '연락 메일' },
      { key: 'homepage', type: 'url', label: '누리집' },
      { key: 'show_badge', type: 'boolean', label: '배지 보이기' },
    ],
  },
  {
    key: 'footer',
    label: '푸터',
    fields: [{ key: 'copyright', type: 'multilingual', label: '저작권 문구', max_length: 200 }],
  },
];

const typedValue = {
  profile: { intro: '안녕하세요', brand_color: '#112233', layout: 'a', contact: 'a@example.com', homepage: 'https://example.com', show_badge: false },
  footer: { copyright: { ko: '저작권 한국어', en: 'Copyright English' } },
};

describe('TemplateSettingsFields — 칸 종류별 부품 (#434)', () => {
  let originalG7Core: unknown;

  beforeEach(() => {
    uploaderProps.length = 0;
    multilingualMode.real = false;
    originalG7Core = (window as any).G7Core;
  });

  afterEach(() => {
    multilingualMode.real = false;
    (window as any).G7Core = originalG7Core;
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings, field_type_controls_rendered
  it('text 칸은 여러 줄 입력이고 바꾸면 그 섹션·키에 쓴다', () => {
    const onChange = vi.fn();
    render(<TemplateSettingsFields sections={typedSections} value={typedValue} onChange={onChange} />);

    const intro = screen.getByLabelText('소개 글') as HTMLTextAreaElement;
    expect(intro.tagName).toBe('TEXTAREA');
    expect(intro.value).toBe('안녕하세요');
    expect(intro.maxLength).toBe(500);

    fireEvent.change(intro, { target: { value: '두 줄\n소개' } });
    expect(onChange).toHaveBeenLastCalledWith({ ...typedValue, profile: { ...typedValue.profile, intro: '두 줄\n소개' } });
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings, field_type_controls_rendered
  it('color 칸은 색 고르개와 16진 글자 칸 두 개이고 어느 쪽을 바꿔도 같은 키에 쓴다', () => {
    const onChange = vi.fn();
    const { container, rerender } = render(<TemplateSettingsFields sections={typedSections} value={typedValue} onChange={onChange} />);

    const inputs = container.querySelectorAll<HTMLInputElement>('#template_settings_field_profile_brand_color input');
    expect(inputs).toHaveLength(2);
    expect(inputs[0].type).toBe('color');
    expect(inputs[0].value).toBe('#112233');
    expect(inputs[1].type).toBe('text');
    expect(inputs[1].value).toBe('#112233');

    fireEvent.change(inputs[0], { target: { value: '#abcdef' } });
    expect(onChange.mock.lastCall[0].profile.brand_color).toBe('#abcdef');

    fireEvent.change(inputs[1], { target: { value: '#00ff00' } });
    expect(onChange).toHaveBeenLastCalledWith({ ...typedValue, profile: { ...typedValue.profile, brand_color: '#00ff00' } });

    // 16진 6자리가 아닌 값은 고르개를 검정으로 두고 글자 칸에는 그대로 보인다
    rerender(<TemplateSettingsFields sections={typedSections} value={{ ...typedValue, profile: { ...typedValue.profile, brand_color: 'red' } }} onChange={onChange} />);
    const after = container.querySelectorAll<HTMLInputElement>('#template_settings_field_profile_brand_color input');
    expect(after[0].value).toBe('#000000');
    expect(after[1].value).toBe('red');
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings, field_type_controls_rendered
  it('선택지가 4개를 넘는 enum 칸은 라디오 대신 목록 상자이고 고른 값을 쓴다', async () => {
    const onChange = vi.fn();
    const { container } = render(<TemplateSettingsFields sections={typedSections} value={typedValue} onChange={onChange} />);

    const field = container.querySelector('#template_settings_field_profile_layout') as HTMLElement;
    expect(field.querySelectorAll('input[type="radio"]')).toHaveLength(0);

    const trigger = field.querySelector('[aria-haspopup="listbox"]') as HTMLButtonElement;
    expect(trigger).not.toBeNull();
    expect(trigger.textContent).toContain('가');

    fireEvent.click(trigger);
    const listbox = await screen.findByRole('listbox');
    expect(within(listbox).getAllByRole('option')).toHaveLength(5);

    fireEvent.click(within(listbox).getByRole('option', { name: '마' }));
    expect(onChange).toHaveBeenLastCalledWith({ ...typedValue, profile: { ...typedValue.profile, layout: 'e' } });
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings, field_type_controls_rendered
  it('email·url 칸은 그 입력 형식으로 그리고 바꾸면 각 키에 쓴다', () => {
    const onChange = vi.fn();
    render(<TemplateSettingsFields sections={typedSections} value={typedValue} onChange={onChange} />);

    const email = screen.getByLabelText('연락 메일') as HTMLInputElement;
    const url = screen.getByLabelText('누리집') as HTMLInputElement;
    expect(email.type).toBe('email');
    expect(email.value).toBe('a@example.com');
    expect(url.type).toBe('url');
    expect(url.value).toBe('https://example.com');

    fireEvent.change(email, { target: { value: 'b@example.com' } });
    expect(onChange.mock.lastCall[0].profile.contact).toBe('b@example.com');

    fireEvent.change(url, { target: { value: 'https://example.org' } });
    expect(onChange).toHaveBeenLastCalledWith({ ...typedValue, profile: { ...typedValue.profile, contact: 'b@example.com', homepage: 'https://example.org' } });
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings, field_type_controls_rendered
  it('boolean 칸은 스위치이고 누르면 새 값(불리언)을 그 섹션·키에 쓴다', () => {
    // 엔진이 주는 change 이벤트 생성기 — 스위치는 이것으로 onChange 를 부른다
    (window as any).G7Core = {
      createChangeEvent: ({ checked, name }: { checked: boolean; name?: string }) => ({ target: { checked, name, value: checked, type: 'checkbox' } }),
    };
    const onChange = vi.fn();
    const { rerender } = render(<TemplateSettingsFields sections={typedSections} value={typedValue} onChange={onChange} />);

    const toggle = screen.getByRole('switch', { name: '배지 보이기' });
    expect(toggle.getAttribute('aria-checked')).toBe('false');

    fireEvent.click(toggle);
    expect(onChange).toHaveBeenLastCalledWith({ ...typedValue, profile: { ...typedValue.profile, show_badge: true } });
    expect(onChange.mock.lastCall[0].profile.show_badge).toBe(true);

    // 켜진 값에서 다시 누르면 false 를 쓴다(문자열이 아니라 불리언)
    rerender(<TemplateSettingsFields sections={typedSections} value={{ ...typedValue, profile: { ...typedValue.profile, show_badge: true } }} onChange={onChange} />);
    fireEvent.click(screen.getByRole('switch', { name: '배지 보이기' }));
    expect(onChange.mock.lastCall[0].profile.show_badge).toBe(false);
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings, multilingual_field_language_tabs
  it('다국어 칸은 언어 탭 — 탭을 바꾸면 그 언어 값을 보이고, 입력은 그 언어 키에만 쓴다', () => {
    multilingualMode.real = true;
    const onChange = vi.fn();
    render(<TemplateSettingsFields sections={typedSections} value={typedValue} onChange={onChange} />);

    const ko = screen.getByLabelText('저작권 문구 (한국어)') as HTMLInputElement;
    const en = screen.getByLabelText('저작권 문구 (English)') as HTMLInputElement;
    expect(ko.value).toBe('저작권 한국어');
    expect(en.value).toBe('Copyright English');

    // 처음에는 기본 언어 탭만 보인다
    expect(ko.parentElement!.parentElement!.className).toBe('block');
    expect(en.parentElement!.parentElement!.className).toBe('hidden');

    fireEvent.click(screen.getByRole('button', { name: 'English' }));
    expect(en.parentElement!.parentElement!.className).toBe('block');
    expect(ko.parentElement!.parentElement!.className).toBe('hidden');

    fireEvent.change(en, { target: { value: 'Copyright 2026' } });
    expect(onChange).toHaveBeenLastCalledWith({
      ...typedValue,
      footer: { copyright: { ko: '저작권 한국어', en: 'Copyright 2026' } },
    });
  });
});
