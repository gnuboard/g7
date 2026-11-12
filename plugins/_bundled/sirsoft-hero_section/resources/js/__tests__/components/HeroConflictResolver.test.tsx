/**
 * @file HeroConflictResolver.test.tsx
 * @description 동시 수정 충돌(409) 뒤 현재 창에서 최신본과 내 변경을 비교해 재적용한다 — 처음 불러온 원본·내 입력·최신본을
 * 항목별로 대조해, 다른 운영자만 바꾼 값은 최신본을, 나만 바꾼 값은 내 값을 기본으로 하고, 둘 다 바꾼 값은 직접 고르게 한다.
 * 이미 바뀐·지워진 이미지의 옛 ID 는 고를 수 없다. 적용 결과는 최신 revision 을 가진 폼이다. (매니페스트 preview-frame-and-conflict.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, screen } from '@testing-library/react';
import { HeroConflictResolver } from '../../components/composite/HeroConflictResolver';

const image = (id: number, device: 'pc' | 'mobile') => ({ id, device, url: `/img/${id}`, original_filename: `${device}-${id}.jpg`, width: 1920, height: 640 });

/** 수정 화면 상세 응답 모양의 배너. */
const detail = (over: Record<string, unknown> = {}) => ({
  id: 5, revision: 3, position: 'home_hero', is_active: true, display_period: 'always', starts_at_local: null, ends_at_local: null,
  title: { ko: '원래 제목' }, description: { ko: '원래 설명' }, button_text: null, image_alt: null,
  title_visible: true, title_color: '#FFFFFF', title_align: 'left', description_visible: true, description_color: '#E5E7EB', description_align: 'left',
  presentation: { pc_x: 50, pc_y: 50, mobile_x: 50, mobile_y: 50, overlay: 'none' }, link_url: '/a', link_new_window: false,
  images: { pc: image(10, 'pc'), mobile: null },
  ...over,
});

/** 그리고 행·적용 단추를 돌려준다. */
const mount = (base: object, mine: object, latest: object) => {
  const onApply = vi.fn();
  const view = render(<HeroConflictResolver base={base} mine={mine} latest={latest} locales={['ko', 'en']} onApply={onApply} />);
  const row = (field: string) => view.container.querySelector<HTMLElement>(`[data-conflict-field="${field}"]`);
  const apply = () => screen.getByRole('button', { name: /sirsoft-hero_section\.admin\.form\.conflict_merge\.apply/ }) as HTMLButtonElement;
  return { view, row, apply, onApply };
};

describe('HeroConflictResolver', () => {
  afterEach(() => cleanup());

  // @scenario surface=conflict_others_change_kept_by_default
  // @effects others_changes_not_overwritten
  it('다른 운영자만 바꾼 항목은 최신 값을 기본으로 쓰고, 내가 바꾼 항목은 내 값을 쓴다 — 결과는 최신 revision', () => {
    const base = detail();
    const mine = detail({ link_url: '/mine', title: { ko: '원래 제목' } });
    const latest = detail({ revision: 4, title: { ko: '남이 바꾼 제목' } });
    const { row, apply, onApply } = mount(base, mine, latest);

    expect(row('title')?.getAttribute('data-change')).toBe('theirs');
    expect(row('link')?.getAttribute('data-change')).toBe('mine');
    expect(row('description')).toBeNull();
    expect(apply().disabled).toBe(false);

    fireEvent.click(apply());
    const [merged, nextBase] = onApply.mock.calls[0];
    expect(merged.revision).toBe(4);
    expect(merged.title).toEqual({ ko: '남이 바꾼 제목' });
    expect(merged.link_url).toBe('/mine');
    expect(nextBase).toBe(latest);
  });

  // @scenario surface=conflict_both_changed_requires_choice
  // @effects others_changes_not_overwritten
  it('둘 다 바꾼 항목은 고르기 전에는 적용할 수 없고, 고른 값으로 적용한다', () => {
    const base = detail();
    const mine = detail({ title: { ko: '내 제목', en: 'Mine' } });
    const latest = detail({ revision: 9, title: { ko: '남의 제목' } });
    const { row, apply, onApply } = mount(base, mine, latest);

    expect(row('title')?.getAttribute('data-change')).toBe('both');
    expect(apply().disabled).toBe(true);
    fireEvent.click(row('title')!.querySelector<HTMLInputElement>('input[value="mine"]')!);
    expect(apply().disabled).toBe(false);
    fireEvent.click(apply());
    expect(onApply.mock.calls[0][0].title).toEqual({ ko: '내 제목', en: 'Mine' });
    expect(onApply.mock.calls[0][0].revision).toBe(9);

    fireEvent.click(row('title')!.querySelector<HTMLInputElement>('input[value="latest"]')!);
    fireEvent.click(apply());
    expect(onApply.mock.calls[1][0].title).toEqual({ ko: '남의 제목' });
  });

  // @scenario surface=conflict_stale_image_not_resent
  // @effects stale_image_id_never_resent
  it('다른 운영자가 이미지를 바꾸거나 지웠으면 내 쪽의 옛 이미지는 고를 수 없고 최신 이미지를 쓴다', () => {
    const base = detail({ images: { pc: image(10, 'pc'), mobile: image(11, 'mobile') } });
    const mine = detail({ images: { pc: image(10, 'pc'), mobile: image(11, 'mobile') } });
    const latest = detail({ revision: 4, images: { pc: image(20, 'pc'), mobile: null } });
    const { row, apply, onApply } = mount(base, mine, latest);

    for (const field of ['pc_image', 'mobile_image']) {
      expect(row(field)?.getAttribute('data-change')).toBe('theirs');
      expect(row(field)!.querySelector<HTMLInputElement>('input[value="mine"]')!.disabled).toBe(true);
    }
    fireEvent.click(apply());
    expect(onApply.mock.calls[0][0].images).toEqual({ pc: image(20, 'pc'), mobile: null });
  });

  // @scenario surface=conflict_stale_image_not_resent
  // @effects my_temp_images_preserved
  it('내가 새로 올린 임시 이미지는 지켜지고, 둘 다 바꾼 이미지는 내 임시 이미지·최신 이미지 중에서 고른다', () => {
    const base = detail({ images: { pc: image(10, 'pc'), mobile: image(11, 'mobile') } });
    const mine = detail({ images: { pc: image(30, 'pc'), mobile: image(31, 'mobile') } });
    const latest = detail({ revision: 4, images: { pc: image(10, 'pc'), mobile: null } });
    const { row, apply, onApply } = mount(base, mine, latest);

    expect(row('pc_image')?.getAttribute('data-change')).toBe('mine');
    expect(row('mobile_image')?.getAttribute('data-change')).toBe('both');
    fireEvent.click(row('mobile_image')!.querySelector<HTMLInputElement>('input[value="mine"]')!);
    fireEvent.click(apply());
    expect(onApply.mock.calls[0][0].images).toEqual({ pc: image(30, 'pc'), mobile: image(31, 'mobile') });
  });

  // @scenario surface=conflict_media_compared
  // @effects my_media_change_not_dropped
  it('미디어(종류·영상)도 비교해, 나만 바꾼 미디어는 적용 뒤에도 남는다', () => {
    const clip = { id: 41, device: 'pc', url: '/vid/41', original_filename: 'pc-41.mp4' };
    const base = detail({ media_type: 'image', videos: { pc: null, mobile: null } });
    const mine = detail({ media_type: 'video', videos: { pc: clip, mobile: null } });
    const latest = detail({ revision: 4, title: { ko: '남이 바꾼 제목' }, media_type: 'image', videos: { pc: null, mobile: null } });
    const { row, apply, onApply } = mount(base, mine, latest);

    expect(row('media')?.getAttribute('data-change')).toBe('mine');
    fireEvent.click(apply());
    const [merged] = onApply.mock.calls[0];
    expect(merged.revision).toBe(4);
    expect(merged.title).toEqual({ ko: '남이 바꾼 제목' });
    expect(merged.media_type).toBe('video');
    expect(merged.videos.pc.id).toBe(41);
  });

  // @scenario surface=conflict_media_compared
  // @effects stale_image_id_never_resent
  it('다른 운영자가 영상을 바꾸거나 뺐으면 내 쪽의 옛 영상은 고를 수 없고, 둘 다 바꾼 미디어는 직접 고른다', () => {
    const video = (id: number, device: 'pc' | 'mobile') => ({ id, device, url: `/vid/${id}`, original_filename: `${device}-${id}.mp4` });
    const base = detail({ media_type: 'video', videos: { pc: video(40, 'pc'), mobile: null } });
    const stale = mount(base, base, detail({ revision: 4, media_type: 'image', videos: { pc: null, mobile: null } }));
    expect(stale.row('media')?.getAttribute('data-change')).toBe('theirs');
    expect(stale.row('media')!.querySelector<HTMLInputElement>('input[value="mine"]')!.disabled).toBe(true);
    cleanup();

    const both = mount(base, detail({ media_type: 'video', videos: { pc: video(41, 'pc'), mobile: null } }), detail({ revision: 4, media_type: 'video', videos: { pc: video(42, 'pc'), mobile: null } }));
    expect(both.row('media')?.getAttribute('data-change')).toBe('both');
    expect(both.apply().disabled).toBe(true);
    fireEvent.click(both.row('media')!.querySelector<HTMLInputElement>('input[value="mine"]')!);
    fireEvent.click(both.apply());
    expect(both.onApply.mock.calls[0][0].media_type).toBe('video');
    expect(both.onApply.mock.calls[0][0].videos).toEqual({ pc: video(41, 'pc'), mobile: null });
  });

  // @scenario surface=conflict_reapply_and_conflict_again
  // @effects others_changes_not_overwritten
  it('재적용 뒤 다시 충돌하면 새 기준·최신본으로 다시 비교하고 앞서 고른 값은 남기지 않는다', () => {
    const base = detail();
    const mine = detail({ title: { ko: '내 제목' } });
    const first = detail({ revision: 4, title: { ko: '남의 제목' } });
    const { view, row, apply, onApply } = mount(base, mine, first);
    fireEvent.click(row('title')!.querySelector<HTMLInputElement>('input[value="mine"]')!);
    fireEvent.click(apply());
    const [merged, nextBase] = onApply.mock.calls[0];

    const second = detail({ revision: 5, title: { ko: '또 다른 제목' } });
    view.rerender(<HeroConflictResolver base={nextBase} mine={merged} latest={second} locales={['ko']} onApply={onApply} />);
    expect(row('title')?.getAttribute('data-change')).toBe('both');
    expect(apply().disabled).toBe(true);
  });

  // @scenario surface=conflict_others_change_kept_by_default
  // @effects blank_defaults_not_reported_as_conflict
  it('비어 있는 다국어 칸·표시 기본값은 같은 값으로 본다(차이 없음이면 그대로 최신 revision 으로 적용)', () => {
    const base = detail({ presentation: null, button_text: null });
    const mine = detail({ presentation: { pc_x: 50, pc_y: 50, mobile_x: 50, mobile_y: 50, overlay: 'none' }, button_text: { ko: '', en: '' } });
    const latest = detail({ revision: 7, presentation: null, button_text: [] });
    const { view, apply, onApply } = mount(base, mine, latest);

    expect(view.container.querySelectorAll('[data-conflict-field]').length).toBe(0);
    fireEvent.click(apply());
    expect(onApply.mock.calls[0][0].revision).toBe(7);
  });
});
