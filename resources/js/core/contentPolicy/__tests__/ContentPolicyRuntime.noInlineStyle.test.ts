/**
 * 본문 콘텐츠 규칙 런타임 — 외부 영상 박스에 style 속성이 없고, 본문 서식은 플래그가 있을 때만 지연한다 (공개 #163, engine-v1.91.0)
 *
 * [case:network-strict-style-csp-inline-style-blocked]
 *
 * @scenario-manifest tests/scenarios/strict-style-csp-compat.yaml
 */
import { describe, expect, it } from 'vitest';
// @ts-ignore - 템플릿 의존성 경로
import DOMPurify from '../../../../../templates/_bundled/sirsoft-basic/node_modules/dompurify';
import fixture from './fixtures/render-policy.json';
import { ContentPolicyRuntime } from '../ContentPolicyRuntime';

const BASE = {
  FORBID_TAGS: ['script', 'iframe', 'svg', 'audio', 'video', 'source', 'track', 'form', 'style'],
  FORBID_ATTR: ['onerror', 'onload', 'onloadstart'],
  ALLOW_DATA_ATTR: true,
  ADD_ATTR: ['target'],
};

/**
 * 규칙을 가진 런타임.
 *
 * @param overrides 규칙 덮어쓰기
 * @returns 런타임
 */
function runtimeWith(overrides: Record<string, unknown> = {}): ContentPolicyRuntime {
  return new ContentPolicyRuntime({ getGlobalSettings: () => ({ content: { render_policy: { ...fixture.render_policy, ...overrides } } }) });
}

/**
 * 고정본 규칙에서 지정 비율을 쓰는 서비스의 표본 주소를 찾습니다.
 *
 * @param runtime 런타임
 * @param candidates 후보 주소
 * @returns [주소, 비율] 목록
 */
function resolvable(runtime: ContentPolicyRuntime, candidates: string[]): Array<[string, string]> {
  return candidates.map((url) => [url, runtime.resolveEmbed(url)?.aspect ?? ''] as [string, string]).filter(([, aspect]) => aspect !== '');
}

describe('ContentPolicyRuntime — 인라인 스타일 없음', () => {
  /**
   * @scenario case=embed_box_no_inline_style
   * @effects embed_box_has_no_style_attr, embed_box_aspect_by_data_attr
   */
  it('외부 영상 박스·iframe 에 style 속성이 없고 비율은 data-aspect 로 싣는다', () => {
    const runtime = runtimeWith();
    const samples = resolvable(runtime, [
      'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      'https://www.youtube.com/shorts/dQw4w9WgXcQ',
      'https://vimeo.com/123456789',
    ]);
    expect(samples.length).toBeGreaterThan(0);

    for (const [url, aspect] of samples) {
      const holder = document.createElement('div');
      holder.innerHTML = `<figure class="media"><oembed url="${url}"></oembed></figure>`;
      runtime.renderEmbeds(holder);

      const box = holder.querySelector('.g7-embed') as HTMLElement;
      expect(box, url).not.toBeNull();
      expect(holder.innerHTML).not.toMatch(/\sstyle=/);
      expect(box.getAttribute('data-aspect')).toBe(aspect);
      expect(box.getAttribute('data-provider')).toBeTruthy();
      const frame = box.querySelector('iframe') as HTMLIFrameElement;
      expect(frame.getAttribute('sandbox')).toBeTruthy();
      expect(frame.getAttribute('loading')).toBe('lazy');
      expect(frame.hasAttribute('style')).toBe(false);
    }
  });

  /**
   * @scenario case=content_style_defer_flag
   * @effects content_style_unchanged_without_flag
   */
  it('G7_DEFER_STYLE 이 없으면 본문 style 속성은 종전 그대로다(옛·외부 템플릿 호환)', () => {
    const out = runtimeWith().sanitize(DOMPurify, '<p style="color:red;text-align:center">a</p>', BASE) ?? '';

    expect(out).toContain('style="color:red;text-align:center"');
    expect(out).not.toContain('data-g7-style');
  });

  /**
   * @scenario case=content_style_defer_flag
   * @effects content_style_deferred_with_flag
   */
  it('G7_DEFER_STYLE: true 면 style 을 data-g7-style 로 옮기고 DOMPurify 설정으로 새지 않는다', () => {
    const out = runtimeWith().sanitize(DOMPurify, '<p style="color:red;text-align:center" data-g7-style="position:fixed">a</p><script>x</script>', { ...BASE, G7_DEFER_STYLE: true }) ?? '';

    expect(out).toBe('<p data-g7-style="color:red;text-align:center">a</p>');
  });

  /**
   * @scenario case=content_style_defer_flag
   * @effects content_style_candidate_tag_style_dropped
   */
  it('후보 태그(정해진 속성만 받는 태그)의 style 은 지연해도 버려진다 — 플래그 없는 결과와 같다', () => {
    const runtime = runtimeWith({
      forbid_tags: BASE.FORBID_TAGS.filter((t) => !['video', 'source', 'track'].includes(t)),
      extra_tags: ['figure', 'figcaption', 'oembed', 'video', 'source', 'track'],
      attributes: { ...fixture.render_policy.attributes, video: ['src', 'controls', 'preload', 'poster'], source: ['src', 'type'] },
    });
    const html = '<video src="/api/modules/sirsoft-board/boards/free/content-media/AAAAAAAAAAAA?expires=1&signature=z" controls style="position:fixed"></video>';

    const plain = runtime.sanitize(DOMPurify, html, BASE) ?? '';
    const deferred = runtime.sanitize(DOMPurify, html, { ...BASE, G7_DEFER_STYLE: true }) ?? '';

    expect(plain).not.toContain('position:fixed');
    expect(deferred).not.toContain('position:fixed');
    expect(deferred).not.toContain('data-g7-style');
  });

  /**
   * @scenario case=content_style_defer_flag
   * @effects content_figure_size_style_kept
   */
  it('그림 묶음(figure)의 style 은 남긴다 — 편집기에서 줄인 이미지 크기가 글 보기에서도 유지된다', () => {
    // 종전 규칙은 figure 에 class 만 받아, 50% 로 줄인 이미지가 글 보기에서는 100% 로 나왔다(정책과 무관하게 항상).
    expect(fixture.render_policy.attributes.figure).toContain('style');

    const runtime = runtimeWith();
    const html = '<figure class="image image_resized" style="width:50%;"><img src="/a.png" alt="a"></figure>';

    const plain = runtime.sanitize(DOMPurify, html, BASE) ?? '';
    expect(plain).toContain('<figure class="image image_resized" style="width:50%;">');

    // 지연 경로(엄격한 스타일 정책 호환)에서는 data-g7-style 로 옮겨 화면에 붙인 뒤 입힌다 — 버리지 않는다
    const deferred = runtime.sanitize(DOMPurify, html, { ...BASE, G7_DEFER_STYLE: true }) ?? '';
    expect(deferred).toContain('<figure class="image image_resized" data-g7-style="width:50%;">');
    expect(deferred).not.toMatch(/\sstyle=/);
  });
});
