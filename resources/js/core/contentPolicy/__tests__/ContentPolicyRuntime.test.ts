/**
 * 본문 콘텐츠 규칙 런타임 — 정화·외부 영상 플레이어·규칙 없음 폴백 (#883 5단계, engine-v1.90.0)
 *
 * @scenario-manifest tests/scenarios/content-render-policy.yaml
 */
import { describe, expect, it } from 'vitest';
// 코어에는 DOMPurify 의존성이 없다 — 런타임은 템플릿이 넘긴 DOMPurify 를 쓰므로, 시험도 템플릿이 실제로 쓰는 판을 쓴다
// @ts-ignore - 템플릿 의존성 경로
import DOMPurify from '../../../../../templates/_bundled/sirsoft-basic/node_modules/dompurify';
import fixture from './fixtures/render-policy.json';
import { ContentPolicyRuntime, EMBED_SANDBOX } from '../ContentPolicyRuntime';

/** 템플릿 HtmlContent 와 같은 기본 설정(축약) */
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

describe('ContentPolicyRuntime', () => {
  /**
   * @scenario case=runtime_sanitize
   * @effects runtime_sanitizes_with_policy, external_media_removed, own_media_kept, event_attrs_stripped
   */
  it('켜진 후보만 살리고 미디어 출처·속성·이벤트를 거른다, 사용자 iframe·script 는 지운다', () => {
    const runtime = runtimeWith({
      forbid_tags: BASE.FORBID_TAGS.filter((t) => !['video', 'source', 'track'].includes(t)),
      extra_tags: ['figure', 'figcaption', 'oembed', 'video', 'source', 'track'],
      attributes: { ...fixture.render_policy.attributes, video: ['src', 'controls', 'preload', 'poster'], source: ['src', 'type'] },
    });
    const html = '<script>x</script><iframe src="https://evil.test"></iframe>'
      + '<video src="https://evil.test/a.mp4" controls></video>'
      + '<video src="/api/modules/sirsoft-board/boards/free/content-media/AAAAAAAAAAAA?expires=1&signature=z" controls autoplay onloadstart="x()"></video>'
      + '<figure class="image"><img src="/a.png"><figcaption>cap</figcaption></figure>';

    const out = runtime.sanitize(DOMPurify, html, BASE) ?? '';

    expect(out).not.toContain('<script');
    expect(out).not.toContain('evil.test');
    expect(out).toContain('content-media/AAAAAAAAAAAA');
    expect(out).not.toContain('autoplay');
    expect(out).not.toContain('onloadstart');
    expect(out).toContain('<figcaption>cap</figcaption>');
  });

  /**
   * 기본 규칙(캡션 켬)에서 `drop_tags` 는 비어 있다 — 고정본은 서버 출력 그대로 (#887)
   * @scenario case=parity_fixture
   * @effects caption_off_drops_caption_keeps_image
   */
  it('기본 규칙(캡션 켬)은 지울 태그가 없다', () => {
    expect((fixture as any).render_policy.drop_tags).toEqual([]);
  });

  /**
   * @scenario case=runtime_embed
   * @effects embed_iframe_from_id_only, unmatched_embed_becomes_link
   */
  it('유튜브 oembed 는 꺼낸 id 로만 만든 샌드박스 iframe, 허용 밖 서비스는 nofollow 링크', () => {
    const runtime = runtimeWith();
    const html = '<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ&amp;list=evil"></oembed></figure>'
      + '<figure class="media"><oembed url="https://unknown.test/v/1"></oembed></figure>'
      + '<figure class="media"><oembed url="javascript:alert(1)"></oembed></figure>';

    const holder = document.createElement('div');
    holder.innerHTML = runtime.sanitize(DOMPurify, html, BASE) ?? '';

    const frames = holder.querySelectorAll('iframe');
    expect(frames).toHaveLength(1);
    expect(frames[0].getAttribute('src')).toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
    expect(frames[0].getAttribute('sandbox')).toBe(EMBED_SANDBOX);
    expect(frames[0].getAttribute('referrerpolicy')).toBe('strict-origin-when-cross-origin');
    // 전체 화면은 allow 의 fullscreen 하나로 — allowfullscreen 을 겹쳐 두면 브라우저 경고 + 서비스 설정 무시(실측 2026-11-08)
    expect(frames[0].hasAttribute('allowfullscreen')).toBe(false);
    expect(frames[0].getAttribute('allow')).toContain('fullscreen');
    const link = holder.querySelector('a[href="https://unknown.test/v/1"]');
    expect(link?.getAttribute('rel')).toBe('noopener noreferrer nofollow');
    expect(holder.innerHTML).not.toContain('javascript:');
    expect(holder.querySelectorAll('oembed')).toHaveLength(0);
  });

  /**
   * 「그림 캡션」 끔 — 서버 `ContentHtmlPolicy::fromSettings(['allow_figure_caption' => false])->toFrontendArray()` 의 모양(외부 영상은 켬).
   * 캡션(figcaption)만 내용째 지우고 그림(figure·img)은 남긴다. 편집기 금지 목록(forbid_tags)에는 넣지 않는다 — 저장 원문은 그대로, 다시 켜면 복원 (#887)
   * @scenario case=runtime_caption_off
   * @effects caption_off_drops_caption_keeps_image
   */
  it('그림 캡션을 끄면 캡션만 내용째 지우고 그림은 남긴다', () => {
    const runtime = runtimeWith({
      extra_tags: ['oembed', 'figure'],
      attributes: { oembed: ['url'], figure: ['class'] },
      drop_tags: ['figcaption'],
    });
    const holder = document.createElement('div');
    holder.innerHTML = runtime.sanitize(DOMPurify, '<figure class="image"><img src="/a.png" alt="a"><figcaption>캡션887</figcaption></figure><p>본문</p>', BASE) ?? '';

    expect(holder.querySelectorAll('figcaption')).toHaveLength(0);
    expect(holder.textContent).not.toContain('캡션887');
    expect(holder.querySelector('figure.image > img[src="/a.png"]')).not.toBeNull();
    expect(holder.textContent).toContain('본문');
  });

  /**
   * 「외부 영상 삽입」 끔 — 서버 `fromSettings(['allow_external_embed' => false])->toFrontendArray()` 의 모양(oembed 규칙·서비스 없음).
   * 봇 화면(`finalize('bot')`)과 같이 원래 주소를 nofollow 링크로 — 빈 `<figure class="media">` 를 남기지 않는다 (#887)
   * @scenario case=runtime_embed_off
   * @effects embed_off_becomes_link
   */
  it('외부 영상 삽입을 끄면 oembed 는 원래 주소 nofollow 링크가 되고 빈 상자를 남기지 않는다', () => {
    const runtime = runtimeWith({
      extra_tags: ['figure', 'figcaption'],
      attributes: { figure: ['class'], figcaption: ['class'] },
      allow_external_embed: false,
      embed_providers: [],
    });
    const html = '<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>'
      + '<figure class="media"><oembed url="https://unknown.test/v/1"></oembed></figure>'
      + '<figure class="media"><oembed url="javascript:alert(1)"></oembed></figure>'
      + '<p url="https://x.test">p</p>';
    const holder = document.createElement('div');
    holder.innerHTML = runtime.sanitize(DOMPurify, html, BASE) ?? '';

    expect(holder.querySelectorAll('iframe')).toHaveLength(0);
    expect(holder.querySelectorAll('oembed')).toHaveLength(0);
    for (const href of ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://unknown.test/v/1']) {
      const link = holder.querySelector(`figure.media > a[href="${href}"]`);
      expect(link?.getAttribute('rel')).toBe('noopener noreferrer nofollow');
      expect(link?.getAttribute('target')).toBe('_blank');
      expect(link?.textContent).toBe(href);
    }
    expect(holder.innerHTML).not.toContain('javascript:');
    // oembed 를 통과시키려고 더한 url 속성은 oembed 밖에서 쓰지 않는다
    expect(holder.querySelector('p')?.hasAttribute('url')).toBe(false);
  });

  /**
   * 운영자 추가 태그 svg — PHP `HtmlSanitizerContentPolicyTest::SAMPLE` 과 같은 표본(대표 패리티, #883)
   * @scenario case=runtime_sanitize
   * @effects svg_allowed, runtime_sanitizes_with_policy
   */
  it('운영자 추가 태그 svg 는 남고 스크립트·사용자 iframe 은 지운다 — 봇 정화기와 같은 표본', () => {
    const sample = '<figure class="image"><img src="/a.png" alt="a"><figcaption>설명</figcaption></figure>'
      + '<figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>'
      + '<svg width="10" height="10"><circle r="4"></circle></svg>'
      + '<script>alert(1)</script><iframe src="https://evil.example/x"></iframe>';
    const policy = (fixture as any).render_policy;
    // 서버 `ContentHtmlPolicy::toFrontendArray()` 가 추가 태그를 금지 목록에서 빼고 추가 목록에 더한 모양
    const withSvg = runtimeWith({ custom_tags: ['svg'], extra_tags: [...(policy.extra_tags ?? []), 'svg'], forbid_tags: (policy.forbid_tags ?? []).filter((t: string) => t !== 'svg') });
    const holder = document.createElement('div');
    holder.innerHTML = withSvg.sanitize(DOMPurify, sample, BASE) ?? '';
    expect(holder.querySelectorAll('figcaption')).toHaveLength(1);
    expect(holder.querySelectorAll('svg')).toHaveLength(1);
    expect(holder.querySelectorAll('script')).toHaveLength(0);
    expect(holder.innerHTML).not.toContain('evil.example');

    const without = document.createElement('div');
    without.innerHTML = runtimeWith().sanitize(DOMPurify, sample, BASE) ?? '';
    expect(without.querySelectorAll('svg')).toHaveLength(0);
  });

  /**
   * @scenario case=runtime_no_policy
   * @effects no_policy_falls_back
   */
  it('규칙이 없으면(옛 서버) null — 호출자가 종전 경로를 쓴다', () => {
    const runtime = new ContentPolicyRuntime({ getGlobalSettings: () => ({}) });

    expect(runtime.getPolicy()).toBeNull();
    expect(runtime.sanitize(DOMPurify, '<p>x</p>', BASE)).toBeNull();
    expect(runtime.editorConstraints()).toBeNull();
  });
});
