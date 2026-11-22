/**
 * @file MenuIconField.test.tsx
 * @description 관리자 메뉴 아이콘 입력 필드 — 미리보기·유형 라벨·탭 4개(선택·직접 입력·업로드·SVG 코드) (#381)
 *
 * 필드는 엔진 폼 바인딩이 읽는 가짜 이벤트(`{ target: { name, value } }`)로 구조형 값(icon_spec)을 내보낸다.
 * 업로드는 `G7Core.api.post` 로 보내고 응답의 icon_spec 을 그대로 내보낸다. 문구는 `admin.menus.icon_field.*`
 * 키로 찾는다(시험 환경의 G7Core.t 는 없는 키를 키 그대로 돌려준다).
 *
 * @scenario-manifest tests/scenarios/menu-icon-rendering.yaml
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MenuIconField, initialTab, parseDirectInput } from '../MenuIconField';
import type { MenuIconSpecData } from '../MenuIcon';

const K = 'admin.menus.icon_field';

/**
 * 탭 단추를 찾는다.
 *
 * @param key tab_ 뒤 이름
 * @returns 탭 단추
 */
const tab = (key: 'pick' | 'input' | 'upload' | 'svg'): HTMLElement => screen.getByRole('tab', { name: `${K}.tab_${key}` });

let originalG7Core: unknown;

beforeEach(() => {
  originalG7Core = (window as any).G7Core;
});

afterEach(() => {
  (window as any).G7Core = originalG7Core;
});

/**
 * G7Core.api.post 를 바꿔 끼운다 (나머지 G7Core 는 시험 준비본 그대로).
 *
 * @param post 모의 post
 */
function mockApiPost(post: ReturnType<typeof vi.fn>): void {
  (window as any).G7Core = { ...(originalG7Core as object), api: { post } };
}

describe('initialTab · parseDirectInput', () => {
  /**
   * @scenario surface=form_field, icon_type=class
   * @effects form_initial_tab_matches_value
   */
  it.each([
    [null, 'pick'],
    [{ type: 'class', value: 'fas fa-users' }, 'pick'],
    [{ type: 'class', value: 'fa-solid fa-users' }, 'pick'],
    [{ type: 'class', value: 'far fa-user' }, 'pick'],
    [{ type: 'class', value: 'fas fa-rocket' }, 'input'],
    [{ type: 'image', source: 'site', path: '/a.png', url: '/a.png' }, 'input'],
    [{ type: 'image', source: 'attachment', attachment_id: 1, hash: 'h', url: '/api/attachment/h' }, 'upload'],
    [{ type: 'image', source: 'package', path: 'assets/a.png', url: '/x/a.png' }, 'upload'],
    [{ type: 'svg', value: '<svg/>' }, 'svg'],
  ] as Array<[MenuIconSpecData | null, string]>)('값 %j 이면 첫 탭은 %s', (value, expected) => {
    expect(initialTab(value)).toBe(expected);
  });

  /**
   * @scenario surface=form_field, icon_type=image
   * @effects external_url_blocked_in_direct_input
   */
  it('parseDirectInput 은 / 로 시작하면 사이트 이미지, 외부 주소·data·javascript 는 오류 키, 그 밖은 FA 클래스로 읽는다', () => {
    expect(parseDirectInput('/qa.png')).toEqual({
      spec: { type: 'image', source: 'site', path: '/qa.png', url: '/qa.png' },
      errorKey: null,
    });
    for (const blocked of ['https://x/y.png', 'http://x/y.png', '//x/y.png', 'data:image/png;base64,AA', 'javascript:alert(1)']) {
      expect(parseDirectInput(blocked), blocked).toEqual({ spec: null, errorKey: 'input_external_blocked' });
    }
    expect(parseDirectInput('  fas   fa-house ')).toEqual({ spec: { type: 'class', value: 'fas fa-house' }, errorKey: null });
    expect(parseDirectInput('   ')).toEqual({ spec: null, errorKey: null });
  });
});

describe('MenuIconField — 렌더', () => {
  /**
   * @scenario surface=form_field, icon_type=class
   * @effects form_initial_tab_matches_value, structured_icon_renders_per_type
   */
  it('class 값은 미리보기에 그 클래스를 그리고 라벨에 원문을, 목록에 있으면 선택 탭을 연다', () => {
    const { container } = render(<MenuIconField value={{ type: 'class', value: 'fas fa-users' }} onChange={vi.fn()} />);
    const preview = container.querySelector('[data-ref="menu_icon_preview"]');

    expect(preview?.querySelector('i')?.className).toBe('fas fa-users w-8 h-8');
    expect(container.querySelector('[data-ref="menu_icon_type_label"]')?.textContent).toBe('fas fa-users');
    expect(tab('pick').getAttribute('aria-selected')).toBe('true');
    expect(screen.getByText('Users')).toBeInTheDocument();
  });

  /**
   * @scenario surface=form_field, icon_type=svg
   * @effects form_initial_tab_matches_value, structured_icon_renders_per_type, svg_sanitized_inline
   */
  it('svg 값은 정제된 SVG 를 미리보기에 그리고 SVG 탭을 연다', () => {
    const svg = '<svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0" fill="currentColor"/></svg>';
    const { container } = render(<MenuIconField value={{ type: 'svg', value: svg }} onChange={vi.fn()} />);
    const preview = container.querySelector('[data-ref="menu_icon_preview"]');

    expect(preview?.querySelectorAll('svg')).toHaveLength(1);
    expect(preview?.querySelector('script')).toBeNull();
    expect(container.querySelector('[data-ref="menu_icon_type_label"]')?.textContent).toBe(`${K}.type_svg`);
    expect(tab('svg').getAttribute('aria-selected')).toBe('true');
  });

  /**
   * @scenario surface=form_field, icon_type=image
   * @effects package_icon_previewed, structured_icon_renders_per_type
   */
  it('확장 패키지 이미지는 미리보기 <img> 와 「확장 이미지 · 경로」 라벨로 보인다', () => {
    const value: MenuIconSpecData = { type: 'image', source: 'package', path: 'assets/a.png', url: '/api/modules/assets/sirsoft-x/assets/a.png' };
    const { container } = render(<MenuIconField value={value} onChange={vi.fn()} />);

    expect(container.querySelector('[data-ref="menu_icon_preview"] img')?.getAttribute('src')).toBe('/api/modules/assets/sirsoft-x/assets/a.png');
    expect(container.querySelector('[data-ref="menu_icon_type_label"]')?.textContent).toBe(`${K}.package_image · assets/a.png`);
  });

  /**
   * @scenario surface=form_field, icon_type=image
   * @effects structured_icon_renders_per_type, broken_image_falls_back_to_circle
   */
  it('업로드 이미지 라벨은 「업로드한 이미지」이고, 깨지면 미리보기가 fas fa-circle 로 바뀐다', () => {
    const value: MenuIconSpecData = { type: 'image', source: 'attachment', attachment_id: 1, hash: 'h', url: '/api/attachment/h' };
    const { container } = render(<MenuIconField value={value} onChange={vi.fn()} />);

    expect(container.querySelector('[data-ref="menu_icon_type_label"]')?.textContent).toBe(`${K}.uploaded_image`);
    expect(tab('upload').getAttribute('aria-selected')).toBe('true');

    fireEvent.error(container.querySelector('[data-ref="menu_icon_preview"] img')!);

    expect(container.querySelector('[data-ref="menu_icon_preview"] i')?.className).toBe('fas fa-circle w-8 h-8');
  });

  /**
   * @scenario surface=form_field, icon_type=class
   * @effects form_initial_tab_matches_value
   */
  it('값이 없으면 라벨은 「없음」, 지우기 단추가 없다', () => {
    const { container } = render(<MenuIconField value={null} onChange={vi.fn()} />);

    expect(container.querySelector('[data-ref="menu_icon_type_label"]')?.textContent).toBe(`${K}.none`);
    expect(container.querySelector('[data-ref="menu_icon_preview"]')?.innerHTML).toBe('');
    expect(screen.queryByRole('button', { name: `${K}.clear` })).toBeNull();
  });

  /**
   * @scenario surface=form_field, icon_type=class
   * @effects form_emits_icon_spec_event
   */
  it('서버 검증 오류 문구를 필드 아래에 보인다', () => {
    const { container } = render(<MenuIconField value={null} onChange={vi.fn()} error="아이콘 형식이 올바르지 않습니다." />);

    expect(container.querySelector('[data-ref="menu_icon_error"]')?.textContent).toBe('아이콘 형식이 올바르지 않습니다.');
  });
});

describe('MenuIconField — 값 내보내기', () => {
  /**
   * @scenario surface=form_field, icon_type=class
   * @effects form_emits_icon_spec_event
   */
  it('선택 탭에서 아이콘을 고르면 {target:{name:icon_spec, value:{type:class, value:fas fa-users}}} 를 내보낸다', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    const { container } = render(<MenuIconField value={null} onChange={onChange} />);

    await user.click(container.querySelector('button[aria-haspopup="listbox"]')!);
    await user.click(await screen.findByText('Users'));

    expect(onChange).toHaveBeenCalledTimes(1);
    expect(onChange).toHaveBeenCalledWith({ target: { name: 'icon_spec', value: { type: 'class', value: 'fas fa-users' } } });
  });

  /**
   * @scenario surface=form_field, icon_type=image
   * @effects form_emits_icon_spec_event
   */
  it('직접 입력 /qa.png 는 사이트 이미지로 내보낸다', () => {
    const onChange = vi.fn();
    render(<MenuIconField value={null} onChange={onChange} />);

    fireEvent.click(tab('input'));
    fireEvent.change(screen.getByRole('textbox', { name: `${K}.input_label` }), { target: { value: '/qa.png' } });

    expect(onChange).toHaveBeenLastCalledWith({
      target: { name: 'icon_spec', value: { type: 'image', source: 'site', path: '/qa.png', url: '/qa.png' } },
    });
    expect(screen.getByText(`${K}.input_detected_image`)).toBeInTheDocument();
  });

  /**
   * @scenario surface=form_field, icon_type=class
   * @effects form_emits_icon_spec_event
   */
  it('직접 입력 FA 클래스는 공백을 하나로 줄여 class 로 내보낸다', () => {
    const onChange = vi.fn();
    render(<MenuIconField value={null} onChange={onChange} />);

    fireEvent.click(tab('input'));
    fireEvent.change(screen.getByRole('textbox', { name: `${K}.input_label` }), { target: { value: 'fa-regular   fa-bell' } });

    expect(onChange).toHaveBeenLastCalledWith({ target: { name: 'icon_spec', value: { type: 'class', value: 'fa-regular fa-bell' } } });
    expect(screen.getByText(`${K}.input_detected_class`)).toBeInTheDocument();
  });

  describe('[case:components-form-blocked-input-leaves-partial-value] 막힌 입력의 중간값 잔존', () => {
  /**
   * 회귀(#381 브라우저 실측): 외부 주소를 한 글자씩 입력하면 막히기 전의 중간 글자(`https`·`https:/`)가
   * class 값으로 내보내져 폼에 남았다. 막힌 입력은 입력을 시작하기 전 값으로 되돌려야 한다.
   *
   * @scenario surface=form_field, icon_type=image
   * @effects external_url_blocked_in_direct_input
   */
  it('외부 주소를 한 글자씩 입력해도 마지막으로 내보낸 값은 입력 전 값이다', () => {
    const onChange = vi.fn();
    const before: MenuIconSpecData = { type: 'image', source: 'site', path: '/qa.png', url: '/qa.png' };
    render(<MenuIconField value={before} onChange={onChange} />);

    fireEvent.click(tab('input'));
    const box = screen.getByRole('textbox', { name: `${K}.input_label` });
    fireEvent.focus(box);
    const typed = 'https://example.com/x.png';
    for (let i = 1; i <= typed.length; i += 1) {
      fireEvent.change(box, { target: { value: typed.slice(0, i) } });
    }

    const last = onChange.mock.calls.at(-1)?.[0]?.target?.value;
    expect(last).toEqual(before);
    expect(screen.getByText(`${K}.input_external_blocked`)).toBeInTheDocument();
  });

  /**
   * 폼이 값을 되돌려 주는(제어) 상태에서도, 막혀서 입력 전 값으로 되돌린 메아리가 운영자가 친 글자를 지우지 않는다.
   *
   * @scenario surface=form_field, icon_type=image
   * @effects external_url_blocked_in_direct_input
   */
  it('제어 폼에서 외부 주소가 막혀도 친 글자와 오류 문구는 남는다', () => {
    const Controlled: React.FC = () => {
      const [value, setValue] = React.useState<MenuIconSpecData | null>(null);
      return <MenuIconField value={value} onChange={(e) => setValue(e.target.value)} />;
    };
    render(<Controlled />);

    fireEvent.click(tab('input'));
    const box = screen.getByRole('textbox', { name: `${K}.input_label` }) as HTMLInputElement;
    fireEvent.focus(box);
    const typed = 'https://example.com/x.png';
    for (let i = 1; i <= typed.length; i += 1) {
      fireEvent.change(box, { target: { value: typed.slice(0, i) } });
    }

    expect(box.value).toBe(typed);
    expect(screen.getByText(`${K}.input_external_blocked`)).toBeInTheDocument();
  });
  });

  /**
   * 회귀(#381 validate-plan O3): 편집 중 다른 메뉴로 바뀌어 값이 바깥에서 SVG·없음으로 갈리면,
   * 「직접 입력」 칸에 이전 메뉴의 클래스 글자가 남았다(SVG 칸도 같은 방향으로 남았다).
   *
   * @scenario surface=form_field, icon_type=svg
   * @effects form_inputs_follow_external_value
   */
  it('값이 바깥에서 바뀌면 직접 입력·SVG 칸이 새 값으로 다시 맞춰진다', () => {
    const svg = '<svg viewBox="0 0 24 24"><path d="M0 0" fill="currentColor"/></svg>';
    const { rerender } = render(<MenuIconField value={{ type: 'class', value: 'fas fa-users' }} onChange={vi.fn()} />);

    rerender(<MenuIconField value={{ type: 'svg', value: svg }} onChange={vi.fn()} />);
    fireEvent.click(tab('input'));
    expect((screen.getByRole('textbox', { name: `${K}.input_label` }) as HTMLInputElement).value).toBe('');

    rerender(<MenuIconField value={{ type: 'class', value: 'fas fa-lock' }} onChange={vi.fn()} />);
    fireEvent.click(tab('svg'));
    expect((screen.getByRole('textbox', { name: `${K}.svg_label` }) as HTMLTextAreaElement).value).toBe('');

    rerender(<MenuIconField value={null} onChange={vi.fn()} />);
    fireEvent.click(tab('input'));
    expect((screen.getByRole('textbox', { name: `${K}.input_label` }) as HTMLInputElement).value).toBe('');
  });

  /**
   * @scenario surface=form_field, icon_type=image
   * @effects external_url_blocked_in_direct_input
   */
  it('직접 입력 https://x/y.png 는 오류 문구를 보이고 내보내지 않는다', () => {
    const onChange = vi.fn();
    render(<MenuIconField value={null} onChange={onChange} />);

    fireEvent.click(tab('input'));
    fireEvent.change(screen.getByRole('textbox', { name: `${K}.input_label` }), { target: { value: 'https://x/y.png' } });

    expect(onChange).not.toHaveBeenCalled();
    expect(screen.getByRole('alert').textContent).toBe(`${K}.input_external_blocked`);
  });

  /**
   * @scenario surface=form_field, icon_type=svg
   * @effects form_emits_icon_spec_event
   */
  it('SVG 탭 입력은 svg 값으로, 비우면 null 로 내보낸다', () => {
    const onChange = vi.fn();
    const svg = '<svg viewBox="0 0 24 24"><path d="M0 0" fill="currentColor"/></svg>';
    render(<MenuIconField value={null} onChange={onChange} />);

    fireEvent.click(tab('svg'));
    const textarea = screen.getByRole('textbox', { name: `${K}.svg_label` });
    fireEvent.change(textarea, { target: { value: svg } });

    expect(onChange).toHaveBeenLastCalledWith({ target: { name: 'icon_spec', value: { type: 'svg', value: svg } } });
    expect(screen.queryByText(`${K}.svg_not_svg`)).toBeNull();

    fireEvent.change(textarea, { target: { value: '' } });
    expect(onChange).toHaveBeenLastCalledWith({ target: { name: 'icon_spec', value: null } });
  });

  /**
   * @scenario surface=form_field, icon_type=svg
   * @effects form_emits_icon_spec_event
   */
  it('svg 로 시작하지 않는 코드는 경고 문구를 보인다', () => {
    render(<MenuIconField value={null} onChange={vi.fn()} />);

    fireEvent.click(tab('svg'));
    fireEvent.change(screen.getByRole('textbox', { name: `${K}.svg_label` }), { target: { value: '<div>x</div>' } });

    expect(screen.getByText(`${K}.svg_not_svg`)).toBeInTheDocument();
  });

  /**
   * @scenario surface=form_field, icon_type=class
   * @effects form_clear_emits_null
   */
  it('지우기 단추는 null 을 내보낸다', () => {
    const onChange = vi.fn();
    render(<MenuIconField value={{ type: 'class', value: 'fas fa-users' }} onChange={onChange} />);

    fireEvent.click(screen.getByRole('button', { name: `${K}.clear` }));

    expect(onChange).toHaveBeenCalledWith({ target: { name: 'icon_spec', value: null } });
  });

  /**
   * @scenario surface=form_field, icon_type=class
   * @effects form_emits_icon_spec_event
   */
  it('name prop 을 바꾸면 그 이름으로 내보낸다', () => {
    const onChange = vi.fn();
    render(<MenuIconField name="menu_icon" value={{ type: 'class', value: 'fas fa-users' }} onChange={onChange} />);

    fireEvent.click(screen.getByRole('button', { name: `${K}.clear` }));

    expect(onChange).toHaveBeenCalledWith({ target: { name: 'menu_icon', value: null } });
  });
});

describe('MenuIconField — 업로드', () => {
  const uploaded: MenuIconSpecData = { type: 'image', source: 'attachment', attachment_id: 1, hash: 'h', url: '/api/attachment/h' };

  /**
   * 업로드 탭에서 파일을 고른다.
   *
   * @param container 렌더 컨테이너
   * @returns 고른 파일
   */
  function chooseFile(container: HTMLElement): File {
    const file = new File(['png'], 'icon.png', { type: 'image/png' });
    fireEvent.click(tab('upload'));
    fireEvent.change(container.querySelector('input[type="file"]')!, { target: { files: [file] } });

    return file;
  }

  /**
   * @scenario surface=form_field, icon_type=image
   * @effects upload_returns_icon_spec, form_emits_icon_spec_event
   */
  it('업로드 성공 시 FormData(file)로 업로드 주소에 보내고 응답 data.data.icon_spec 을 내보낸다', async () => {
    const post = vi.fn().mockResolvedValue({ data: { data: { icon_spec: uploaded } } });
    mockApiPost(post);
    const onChange = vi.fn();
    const { container } = render(<MenuIconField value={null} onChange={onChange} />);

    const file = chooseFile(container);

    await waitFor(() => expect(onChange).toHaveBeenCalledWith({ target: { name: 'icon_spec', value: uploaded } }));
    expect(post).toHaveBeenCalledTimes(1);
    const [url, body] = post.mock.calls[0];
    expect(url).toBe('/api/admin/menus/icon-upload');
    expect(body).toBeInstanceOf(FormData);
    expect((body as FormData).get('file')).toBe(file);
  });

  /**
   * @scenario surface=form_field, icon_type=image
   * @effects upload_returns_icon_spec
   */
  it('응답 data.icon_spec 모양도 읽고, uploadEndpoint 를 따른다', async () => {
    const post = vi.fn().mockResolvedValue({ data: { icon_spec: uploaded } });
    mockApiPost(post);
    const onChange = vi.fn();
    const { container } = render(<MenuIconField value={null} onChange={onChange} uploadEndpoint="/api/custom/upload" />);

    chooseFile(container);

    await waitFor(() => expect(onChange).toHaveBeenCalledWith({ target: { name: 'icon_spec', value: uploaded } }));
    expect(post.mock.calls[0][0]).toBe('/api/custom/upload');
  });

  /**
   * @scenario surface=form_field, icon_type=image
   * @effects upload_failure_shows_message
   */
  it('업로드 실패 시 서버 message 를 보이고 내보내지 않는다', async () => {
    mockApiPost(vi.fn().mockRejectedValue({ response: { data: { message: 'X' } } }));
    const onChange = vi.fn();
    const { container } = render(<MenuIconField value={null} onChange={onChange} />);

    chooseFile(container);

    expect(await screen.findByRole('alert')).toHaveTextContent('X');
    expect(screen.getByRole('alert').textContent).toBe('X');
    expect(onChange).not.toHaveBeenCalled();
  });

  /**
   * @scenario surface=form_field, icon_type=image
   * @effects upload_failure_shows_message
   */
  it('응답에 icon_spec 이 없으면 업로드 실패 문구를 보인다', async () => {
    mockApiPost(vi.fn().mockResolvedValue({ data: { data: {} } }));
    const onChange = vi.fn();
    const { container } = render(<MenuIconField value={null} onChange={onChange} />);

    chooseFile(container);

    expect(await screen.findByRole('alert')).toHaveTextContent(`${K}.upload_failed`);
    expect(onChange).not.toHaveBeenCalled();
  });

  /**
   * @scenario surface=form_field, icon_type=image
   * @effects upload_failure_shows_message
   */
  it('G7Core.api 가 없으면 보내지 않고 업로드 실패 문구를 보인다', () => {
    (window as any).G7Core = { ...(originalG7Core as object), api: undefined };
    const onChange = vi.fn();
    const { container } = render(<MenuIconField value={null} onChange={onChange} />);

    chooseFile(container);

    expect(screen.getByRole('alert').textContent).toBe(`${K}.upload_failed`);
    expect(onChange).not.toHaveBeenCalled();
  });

  /**
   * 회귀(#381 validate-plan 4차 O7): 업로드가 끝난 뒤(성공·실패)에도 파일 칸이 고른 파일을 쥐고 있어, 거부된 SVG 를
   * 고쳐 같은 파일을 다시 고르면 브라우저가 change 를 내지 않아 아무 일도 일어나지 않았다.
   *
   * @scenario surface=form_field, icon_type=image
   * @effects upload_input_reset_after_attempt
   */
  it.each([
    ['실패', () => vi.fn().mockRejectedValue({ response: { data: { message: 'X' } } })],
    ['성공', () => vi.fn().mockResolvedValue({ data: { data: { icon_spec: uploaded } } })],
  ])('업로드 %s 뒤 파일 칸이 비워져 같은 파일을 다시 고를 수 있다', async (_label, makePost) => {
    const post = makePost();
    mockApiPost(post);
    const { container } = render(<MenuIconField value={null} onChange={vi.fn()} />);

    chooseFile(container);
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(container.querySelector('input[type="file"]')).not.toBeNull());

    await waitFor(() => {
      const input = container.querySelector('input[type="file"]') as HTMLInputElement;
      expect(input.files?.length ?? 0).toBe(0);
      expect(input.disabled).toBe(false);
    });
  });

  /**
   * 회귀(#381 validate-plan 4차 O8): 업로드·선택으로 값을 바꾸면 그 값은 이 필드가 내보낸 것이라 「메아리」로 보고
   * 칸 동기화를 건너뛰어, 「SVG 코드」·「직접 입력」 칸에 바꾸기 전 글자가 남았다.
   *
   * @scenario surface=form_field, icon_type=svg
   * @effects form_inputs_follow_external_value
   */
  it('업로드로 받은 SVG 는 SVG 코드 칸에, 그 전 클래스 글자는 직접 입력 칸에서 사라진다', async () => {
    const svg = '<svg viewBox="0 0 24 24"><path d="M0 0" fill="currentColor"/></svg>';
    mockApiPost(vi.fn().mockResolvedValue({ data: { data: { icon_spec: { type: 'svg', value: svg } } } }));
    const Controlled: React.FC = () => {
      const [value, setValue] = React.useState<MenuIconSpecData | null>({ type: 'class', value: 'fas fa-rocket' });
      return <MenuIconField value={value} onChange={(e) => setValue(e.target.value)} />;
    };
    const { container } = render(<Controlled />);

    chooseFile(container);
    await waitFor(() => expect(container.querySelector('[data-ref="menu_icon_type_label"]')?.textContent).toBe(`${K}.type_svg`));

    fireEvent.click(tab('svg'));
    expect((screen.getByRole('textbox', { name: `${K}.svg_label` }) as HTMLTextAreaElement).value).toBe(svg);
    fireEvent.click(tab('input'));
    expect((screen.getByRole('textbox', { name: `${K}.input_label` }) as HTMLInputElement).value).toBe('');
  });

  /**
   * @scenario surface=form_field, icon_type=class
   * @effects form_inputs_follow_external_value
   */
  it('선택 탭에서 고른 클래스는 직접 입력 칸에 그대로 보인다', async () => {
    const user = userEvent.setup();
    const Controlled: React.FC = () => {
      const [value, setValue] = React.useState<MenuIconSpecData | null>({ type: 'class', value: 'fas fa-home' });
      return <MenuIconField value={value} onChange={(e) => setValue(e.target.value)} />;
    };
    const { container } = render(<Controlled />);

    fireEvent.click(tab('pick'));
    await user.click(container.querySelector('button[aria-haspopup="listbox"]')!);
    await user.click(await screen.findByText('Users'));

    fireEvent.click(tab('input'));
    expect((screen.getByRole('textbox', { name: `${K}.input_label` }) as HTMLInputElement).value).toBe('fas fa-users');
  });

  /**
   * 회귀(#381 validate-plan 5차 ⓐ): 직접 입력에 외부 주소를 붙여넣어 막힌 뒤 선택 탭에서 지금과 같은 아이콘을 고르면,
   * 값이 바뀌지 않아 칸 맞춤이 돌지 않고 막힌 주소와 빨간 문구가 직접 입력 칸에 남았다.
   *
   * @scenario surface=form_field, icon_type=class
   * @effects form_inputs_follow_external_value
   */
  it('막힌 직접 입력 뒤 같은 아이콘을 다시 골라도 직접 입력 칸이 그 아이콘으로 맞춰지고 오류가 사라진다', async () => {
    const user = userEvent.setup();
    const Controlled: React.FC = () => {
      const [value, setValue] = React.useState<MenuIconSpecData | null>({ type: 'class', value: 'fas fa-users' });
      return <MenuIconField value={value} onChange={(e) => setValue(e.target.value)} />;
    };
    const { container } = render(<Controlled />);

    fireEvent.click(tab('input'));
    const box = screen.getByRole('textbox', { name: `${K}.input_label` }) as HTMLInputElement;
    fireEvent.focus(box);
    fireEvent.change(box, { target: { value: 'https://x.example/a.png' } });
    expect(screen.getByText(`${K}.input_external_blocked`)).toBeInTheDocument();

    fireEvent.click(tab('pick'));
    await user.click(container.querySelector('button[aria-haspopup="listbox"]')!);
    // 트리거에도 현재 선택 「Users」가 보이므로 목록 쪽(마지막) 항목을 누른다
    const users = await screen.findAllByText('Users');
    await user.click(users[users.length - 1]);

    fireEvent.click(tab('input'));
    expect((screen.getByRole('textbox', { name: `${K}.input_label` }) as HTMLInputElement).value).toBe('fas fa-users');
    expect(screen.queryByText(`${K}.input_external_blocked`)).toBeNull();
  });

  /**
   * 회귀(#381 validate-plan 5차 ⓑ): 업로드 오류 문구는 다음 업로드 때만 지워져, 다른 아이콘을 고르거나 지운 뒤에도 남았다.
   *
   * @scenario surface=form_field, icon_type=image
   * @effects upload_failure_shows_message
   */
  it('업로드 오류 뒤 값을 바꾸면 업로드 오류 문구가 사라진다', async () => {
    mockApiPost(vi.fn().mockRejectedValue({ response: { data: { message: 'X' } } }));
    const Controlled: React.FC = () => {
      const [value, setValue] = React.useState<MenuIconSpecData | null>({ type: 'class', value: 'fas fa-users' });
      return <MenuIconField value={value} onChange={(e) => setValue(e.target.value)} />;
    };
    const { container } = render(<Controlled />);

    chooseFile(container);
    expect(await screen.findByRole('alert')).toHaveTextContent('X');

    fireEvent.click(screen.getByRole('button', { name: `${K}.clear` }));
    fireEvent.click(tab('upload'));
    expect(screen.queryByText('X')).toBeNull();
  });

  /**
   * 회귀(#381 validate-plan 5차 ⓒ): 업로드 진행 중 「아이콘 지우기」를 누르면, 늦게 온 업로드 응답이 지운 값을 덮어
   * 업로드 이미지가 되살아났다. 진행 중에 값이 바뀌면 늦은 응답은 버린다.
   *
   * @scenario surface=form_field, icon_type=image
   * @effects form_clear_emits_null
   */
  it('업로드 중 지우기를 누르면 늦게 온 업로드 결과가 값을 덮지 않는다', async () => {
    let resolveUpload: (v: unknown) => void = () => {};
    const post = vi.fn().mockReturnValue(new Promise((resolve) => { resolveUpload = resolve; }));
    mockApiPost(post);
    const onChange = vi.fn();
    const Controlled: React.FC = () => {
      const [value, setValue] = React.useState<MenuIconSpecData | null>({ type: 'class', value: 'fas fa-users' });
      return (
        <MenuIconField
          value={value}
          onChange={(e) => {
            onChange(e);
            setValue(e.target.value);
          }}
        />
      );
    };
    const { container } = render(<Controlled />);

    chooseFile(container);
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: `${K}.clear` }));
    expect(onChange).toHaveBeenLastCalledWith({ target: { name: 'icon_spec', value: null } });

    resolveUpload({ data: { data: { icon_spec: uploaded } } });
    await waitFor(() => {
      const input = container.querySelector('input[type="file"]') as HTMLInputElement | null;
      expect(input?.disabled ?? false).toBe(false);
    });

    expect(onChange).toHaveBeenLastCalledWith({ target: { name: 'icon_spec', value: null } });
    expect(onChange).not.toHaveBeenCalledWith({ target: { name: 'icon_spec', value: uploaded } });
  });

  /**
   * 회귀(#381 validate-plan 6차 O-6a): 직접 입력·SVG 두 칸에서 친 값은 서로를 맞추지 않아, 직접 입력에 막힌 외부 주소·빨간
   * 문구가 SVG 를 붙여 넣은 뒤에도 남고(반대로 SVG 칸엔 옛 코드가 남아 한 글자만 고쳐도 값이 SVG 로 돌아갔다).
   *
   * @scenario surface=form_field, icon_type=svg
   * @effects form_inputs_follow_external_value
   */
  it('한 칸에서 친 값은 다른 칸을 새 값에 맞춘다(막힌 글자·빨간 문구·옛 SVG 잔존 없음)', () => {
    const Controlled: React.FC = () => {
      const [value, setValue] = React.useState<MenuIconSpecData | null>({ type: 'class', value: 'fas fa-users' });
      return <MenuIconField value={value} onChange={(e) => setValue(e.target.value)} />;
    };
    render(<Controlled />);
    const svg = '<svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>';

    fireEvent.click(tab('input'));
    const box = screen.getByRole('textbox', { name: `${K}.input_label` }) as HTMLInputElement;
    fireEvent.focus(box);
    fireEvent.change(box, { target: { value: 'https://a' } });
    expect(screen.getByText(`${K}.input_external_blocked`)).toBeInTheDocument();

    fireEvent.click(tab('svg'));
    fireEvent.change(screen.getByRole('textbox', { name: `${K}.svg_label` }), { target: { value: svg } });
    fireEvent.click(tab('input'));
    expect((screen.getByRole('textbox', { name: `${K}.input_label` }) as HTMLInputElement).value).toBe('');
    expect(screen.queryByText(`${K}.input_external_blocked`)).toBeNull();

    const box2 = screen.getByRole('textbox', { name: `${K}.input_label` }) as HTMLInputElement;
    fireEvent.focus(box2);
    fireEvent.change(box2, { target: { value: 'fas fa-star' } });
    fireEvent.click(tab('svg'));
    expect((screen.getByRole('textbox', { name: `${K}.svg_label` }) as HTMLTextAreaElement).value).toBe('');
  });

  /**
   * 회귀(#381 validate-plan 6차 O-6b): 업로드가 끝나기 전에 저장하면 이전 아이콘으로 저장되고 올린 이미지는 조용히 버려졌다.
   * 업로드 동안 전역 `menuIconUploading` 을 켜 저장 단추를 잠그고(레이아웃), 끝나면(성공·실패) 끈다.
   *
   * @scenario surface=form_field, icon_type=image
   * @effects upload_in_progress_blocks_save
   */
  it('업로드 동안 전역 업로드 중 표시를 켜고 끝나면 끈다', async () => {
    let state: Record<string, any> = {};
    const update = vi.fn((fn: (prev: Record<string, any>) => Record<string, any>) => {
      state = { ...state, ...fn(state) };
    });
    let resolveUpload: (v: unknown) => void = () => {};
    const post = vi.fn().mockReturnValue(new Promise((resolve) => { resolveUpload = resolve; }));
    (window as any).G7Core = { ...(originalG7Core as object), api: { post }, state: { ...((originalG7Core as any)?.state ?? {}), update } };
    const { container } = render(<MenuIconField value={null} onChange={vi.fn()} />);

    chooseFile(container);
    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(state.menuIconUploading).toBe(true);

    resolveUpload({ data: { data: { icon_spec: uploaded } } });
    await waitFor(() => expect(state.menuIconUploading).toBe(false));
  });

  /**
   * 회귀(#381 validate-plan 6차 O-6b): 업로드 중에 폼이 닫히면(취소·다른 화면) 업로드 중 표시를 끄고, 늦게 온 결과는
   * 닫힌 폼 값에 쓰지 않는다.
   *
   * @scenario surface=form_field, icon_type=image
   * @effects upload_in_progress_blocks_save
   */
  it('업로드 중 필드가 사라지면 업로드 중 표시를 끄고 늦은 결과를 내보내지 않는다', async () => {
    let state: Record<string, any> = {};
    const update = vi.fn((fn: (prev: Record<string, any>) => Record<string, any>) => {
      state = { ...state, ...fn(state) };
    });
    let resolveUpload: (v: unknown) => void = () => {};
    const post = vi.fn().mockReturnValue(new Promise((resolve) => { resolveUpload = resolve; }));
    (window as any).G7Core = { ...(originalG7Core as object), api: { post }, state: { ...((originalG7Core as any)?.state ?? {}), update } };
    const onChange = vi.fn();
    const { container, unmount } = render(<MenuIconField value={null} onChange={onChange} />);

    chooseFile(container);
    await waitFor(() => expect(state.menuIconUploading).toBe(true));
    unmount();
    expect(state.menuIconUploading).toBe(false);

    resolveUpload({ data: { data: { icon_spec: uploaded } } });
    await new Promise((r) => setTimeout(r, 0));
    expect(onChange).not.toHaveBeenCalled();
  });

  /**
   * 회귀(#381 validate-plan 6차 O-6c): 직접 입력에 사이트 경로를 한 글자씩 치면 글자마다 미리보기 이미지 요청이 나갔다.
   * 입력이 멈춘 뒤에만 미리보기를 바꾸고, 그 주소에는 사이트 마운트 접두가 붙는다(하위 폴더 설치).
   *
   * @scenario surface=form_field, icon_type=image
   * @effects form_preview_waits_for_typing_pause
   */
  it('직접 입력 사이트 경로는 입력이 멈춘 뒤에만 미리보기를 불러오고 마운트 접두를 붙인다', async () => {
    const originalConfig = (window as any).G7Config;
    (window as any).G7Config = { ...(originalConfig ?? {}), basePath: '/g7' };
    (window as any).G7Core = { ...(originalG7Core as object), url: undefined };
    try {
      const Controlled: React.FC = () => {
        const [value, setValue] = React.useState<MenuIconSpecData | null>({ type: 'class', value: 'fas fa-users' });
        return <MenuIconField value={value} onChange={(e) => setValue(e.target.value)} />;
      };
      const { container } = render(<Controlled />);
      const preview = () => container.querySelector('[data-ref="menu_icon_preview"] img') as HTMLImageElement | null;

      fireEvent.click(tab('input'));
      const box = screen.getByRole('textbox', { name: `${K}.input_label` }) as HTMLInputElement;
      fireEvent.focus(box);
      for (const text of ['/q', '/qa', '/qa.', '/qa.png']) {
        fireEvent.change(box, { target: { value: text } });
      }
      // 치는 동안에는 미리보기 이미지를 만들지 않는다(글자마다 요청 0)
      expect(preview()).toBeNull();

      await waitFor(() => expect(preview()).not.toBeNull());
      expect(preview()!.getAttribute('src')).toBe('/g7/qa.png');
    } finally {
      (window as any).G7Config = originalConfig;
    }
  });
});
