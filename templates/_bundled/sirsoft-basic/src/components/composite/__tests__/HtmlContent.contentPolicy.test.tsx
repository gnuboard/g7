/**
 * HtmlContent — 코어 「본문 콘텐츠」 규칙(G7Core.contentPolicy) 적용 (#883 5단계)
 *
 * 규칙이 있으면 켜진 후보(그림 캡션·외부 영상)를 살리고 유튜브 oembed 를 샌드박스 iframe 으로 바꾼다. 사용자 iframe·script·
 * onerror 는 계속 지우고, 허용 밖 서비스는 링크로. prop 으로 덧붙인 금지는 규칙으로 풀리지 않는다. 규칙이 없으면 종전 정화.
 *
 * @scenario-manifest templates/_bundled/sirsoft-basic/tests/scenarios/html-content-policy.yaml
 */
import React from 'react';
import { render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { HtmlContent } from '../HtmlContent';
import { ContentPolicyRuntime } from '../../../../../../../resources/js/core/contentPolicy/ContentPolicyRuntime';
import fixture from '../../../../../../../resources/js/core/contentPolicy/__tests__/fixtures/render-policy.json';

/**
 * 코어 규칙 런타임을 G7Core 에 깝니다.
 *
 * @param policy 표시 규칙 (null 이면 규칙 없음)
 * @returns 없음
 */
function installPolicy(policy: Record<string, unknown> | null): void {
    const runtime = new ContentPolicyRuntime({ getGlobalSettings: () => (policy ? { content: { render_policy: policy } } : {}) });
    (window as any).G7Core = { contentPolicy: { sanitize: (p: any, h: string, c: any) => runtime.sanitize(p, h, c) } };
}

/**
 * 렌더한 HTML.
 *
 * @param content 본문
 * @param props 추가 prop
 * @returns innerHTML
 */
function html(content: string, props: Record<string, unknown> = {}): string {
    return render(<HtmlContent content={content} isHtml {...props} />).container.innerHTML;
}

afterEach(() => {
    delete (window as any).G7Core;
});

describe('HtmlContent 본문 콘텐츠 규칙', () => {
    /**
     * @scenario case=policy_on
     * @effects caption_kept, youtube_iframe, user_iframe_removed, unmatched_link
     */
    it('캡션·유튜브 iframe 은 살리고, 사용자 iframe·script·onerror 는 지우고, 허용 밖 서비스는 링크', () => {
        installPolicy(fixture.render_policy as any);
        const out = html('<figure class="image"><img src="/a.png" onerror="x()"><figcaption>cap</figcaption></figure>'
            + '<figure class="media"><oembed url="https://youtu.be/dQw4w9WgXcQ"></oembed></figure>'
            + '<figure class="media"><oembed url="https://unknown.test/v"></oembed></figure>'
            + '<iframe src="https://evil.test"></iframe><script>x</script>');

        expect(out).toContain('<figcaption>cap</figcaption>');
        expect(out).toContain('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"');
        expect(out).not.toContain('evil.test');
        expect(out).not.toContain('onerror');
        expect(out).not.toContain('<script');
        expect(out).toContain('href="https://unknown.test/v"');
    });

    /**
     * 후보를 끈 규칙(서버 `ContentHtmlPolicy::toFrontendArray()` 실측값) — 외부 영상은 iframe 이 되지 않고, 자기 서빙 경로 video 도 지운다.
     *
     * @scenario case=policy_on
     * @effects candidates_off_respected, embed_off_link
     */
    it('후보를 끄면 외부 영상 iframe·업로드 동영상이 나오지 않는다', () => {
        installPolicy({
            ...(fixture.render_policy as any),
            forbid_tags: [...(fixture.render_policy as any).forbid_tags],
            drop_tags: ['figcaption'],
            extra_tags: [],
            attributes: {},
            allow_external_embed: false,
            allow_uploaded_video: false,
            embed_providers: [],
        });
        const out = html('<figure class="media"><oembed url="https://youtu.be/dQw4w9WgXcQ"></oembed></figure>'
            + '<video src="/api/modules/sirsoft-board/boards/free/content-media/AAAAAAAAAAAA" controls></video>');

        expect(out).not.toContain('<iframe');
        expect(out).not.toContain('youtube-nocookie.com');
        expect(out).not.toContain('<video');
        // 외부 영상 삽입을 끄면 원래 주소 링크로 보인다 (#887)
        expect(out).toContain('href="https://youtu.be/dQw4w9WgXcQ"');
    });

    /**
     * 「그림 캡션」 끔(서버 `fromSettings(['allow_figure_caption' => false])->toFrontendArray()` 모양) — 캡션만 내용째 지우고 그림은 남긴다 (#887)
     *
     * @scenario case=caption_off
     * @effects caption_off_image_kept
     */
    it('그림 캡션을 끄면 캡션만 지우고 그림은 남긴다', () => {
        installPolicy({
            ...(fixture.render_policy as any),
            extra_tags: ['oembed', 'figure'],
            attributes: { oembed: ['url'], figure: ['class'] },
            drop_tags: ['figcaption'],
        });
        const out = html('<figure class="image"><img src="/a.png" alt="a"><figcaption>캡션887</figcaption></figure>');

        expect(out).not.toContain('<figcaption');
        expect(out).not.toContain('캡션887');
        expect(out).toContain('<figure class="image"><img src="/a.png" alt="a"></figure>');
    });

    /**
     * 「외부 영상 삽입」 끔(서버 `fromSettings(['allow_external_embed' => false])->toFrontendArray()` 모양) — 안내문대로 원래 주소 링크, 빈 상자 없음 (#887)
     *
     * @scenario case=embed_off
     * @effects embed_off_link
     */
    it('외부 영상 삽입을 끄면 영상 주소를 링크로 보이고 빈 상자를 남기지 않는다', () => {
        installPolicy({
            ...(fixture.render_policy as any),
            extra_tags: ['figure', 'figcaption'],
            attributes: { figure: ['class'], figcaption: ['class'] },
            allow_external_embed: false,
            embed_providers: [],
        });
        const out = html('<figure class="media"><oembed url="https://youtu.be/dQw4w9WgXcQ"></oembed></figure>');

        expect(out).not.toContain('<iframe');
        expect(out).not.toContain('<figure class="media"></figure>');
        expect(out).toContain('<a href="https://youtu.be/dQw4w9WgXcQ" rel="noopener noreferrer nofollow" target="_blank">https://youtu.be/dQw4w9WgXcQ</a>');
    });

    /**
     * 업로드 동영상 후보를 켠 규칙(서버 실측값) — 자기 서빙 경로 video 만 남고, 바깥 주소 video·이벤트 속성은 지운다.
     *
     * @scenario case=policy_on
     * @effects external_video_removed
     */
    it('업로드 동영상을 켜도 서빙 경로 밖 주소의 video 는 지운다', () => {
        const base = fixture.render_policy as any;
        installPolicy({
            ...base,
            forbid_tags: base.forbid_tags.filter((t: string) => !['video', 'source', 'track'].includes(t)),
            extra_tags: ['figure', 'figcaption', 'video', 'source', 'track', 'oembed'],
            attributes: { ...base.attributes, video: ['src', 'controls', 'preload', 'width', 'height', 'playsinline', 'poster'], source: ['src', 'type'], track: ['src', 'kind', 'srclang', 'label', 'default'] },
            allow_uploaded_video: true,
        });
        const out = html('<video src="https://evil.test/a.mp4" controls></video>'
            + '<video src="/api/modules/sirsoft-board/boards/free/content-media/AAAAAAAAAAAA?expires=1&amp;signature=z" controls onloadstart="x()"></video>');

        expect(out).not.toContain('evil.test');
        expect(out).toContain('content-media/AAAAAAAAAAAA');
        expect(out).not.toContain('onloadstart');
        expect((out.match(/<video/g) ?? []).length).toBe(1);
    });

    /**
     * @scenario case=prop_forbid
     * @effects prop_forbid_not_unlocked
     */
    it('purifyConfig 로 덧붙인 금지(figure)는 규칙이 켜도 풀리지 않는다', () => {
        installPolicy(fixture.render_policy as any);
        const out = html('<figure class="image"><figcaption>cap</figcaption></figure>', { purifyConfig: { FORBID_TAGS: ['figure'] } });

        expect(out).not.toContain('<figure');
    });

    /**
     * @scenario case=policy_off
     * @effects legacy_path_without_policy
     */
    it('규칙이 없으면 종전 정화 — oembed 는 지워지고 iframe 도 없다', () => {
        installPolicy(null);
        const out = html('<figure class="media"><oembed url="https://youtu.be/dQw4w9WgXcQ"></oembed></figure>');

        expect(out).not.toContain('<iframe');
        expect(out).not.toContain('<oembed');
    });
});
