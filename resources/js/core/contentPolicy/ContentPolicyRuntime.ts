/**
 * 본문 콘텐츠 규칙 런타임 — `G7Core.contentPolicy` (engine-v1.90.0, #883 5단계)
 *
 * 코어 환경설정 「본문 콘텐츠」가 정한 규칙(서버가 계산해 `_global.settings.content.render_policy` 로 내려준다)으로 본문 HTML 을
 * 표시용으로 정화하고, 외부 영상 삽입(`<oembed url>`)을 정해진 플레이어 iframe 으로 바꾼다. 템플릿 HtmlContent·편집기 플러그인이 같은
 * 규칙을 쓴다 — 아무도 태그 목록을 하드코딩하지 않는다.
 *
 * - `sanitize(purify, html, baseConfig)` — 템플릿이 넘긴 DOMPurify 로 **전용 인스턴스**를 만들어(훅이 전역에 새지 않게) 정화한다.
 *   기본 금지 목록에서 켜진 후보 태그를 빼고 추가 태그를 더하며, 후보 태그는 정해진 속성만, 미디어 `src`·`poster` 는 서빙 주소
 *   패턴에 맞을 때만 남긴다. 그 뒤 `renderEmbeds` 로 외부 영상을 바꾼다. 규칙이 없으면(옛 서버) `null` 을 돌려 호출자가 종전 경로를 쓴다.
 *   꺼진 후보의 표시 제거 태그(`drop_tags`, 캡션 끔이면 figcaption)는 내용째 지운다. `oembed` 는 외부 영상 삽입을 꺼도 정화를 통과시켜
 *   `renderEmbeds` 가 링크로 바꾼다 — 정화가 먼저 지우면 빈 `<figure class="media">` 만 남는다 (engine-v1.90.1, #887).
 * - `renderEmbeds(fragment, policy)` — `oembed[url]` 을 켜진 서비스의 **서버가 컴파일한 정규식**에 맞추고, 꺼낸 id 로만 플레이어 주소를
 *   채워 샌드박스 iframe 을 만든다(원본 주소는 iframe 에 넣지 않는다). 맞지 않으면 `rel="noopener noreferrer nofollow"` 링크.
 * - `editorConstraints()` — 편집기가 쓸 서비스 목록·금지 태그·업로드 동영상/오디오 허용.
 */

/** 외부 영상 서비스 (서버 `EmbedProviderRegistry::toFrontendArray`) */
export interface EmbedProviderPolicy {
  id: string;
  name: string;
  source: 'preset' | 'custom' | 'extension' | string;
  patterns: string[];
  aspects: string[];
  player_url: string;
  aspect: string;
  allow: string[];
}

/** 표시 규칙 (서버 `ContentHtmlPolicy::toFrontendArray`) */
export interface ContentRenderPolicy {
  version: 1;
  forbid_tags: string[];
  /** 표시에서 내용째 지울 태그 — 꺼진 후보 중 브라우저가 원래 받는 태그(figcaption). 편집기 금지에는 넣지 않는다(저장 원문 보존). engine-v1.90.1 */
  drop_tags?: string[];
  extra_tags: string[];
  attributes: Record<string, string[]>;
  custom_tags: string[];
  media_src_patterns: string[];
  allow_external_embed: boolean;
  allow_uploaded_video: boolean;
  allow_uploaded_audio: boolean;
  embed_providers: EmbedProviderPolicy[];
}

/** 해석한 외부 영상 */
export interface ResolvedEmbed {
  provider: EmbedProviderPolicy;
  id: string;
  playerUrl: string;
  aspect: string;
}

/** 런타임 의존성 */
export interface ContentPolicyRuntimeDeps {
  /** `_global.settings` 를 돌려준다 */
  getGlobalSettings: () => any;
}

/** iframe 샌드박스 — 스크립트·같은 출처(플레이어 자체)·전체 화면·팝업만 */
export const EMBED_SANDBOX = 'allow-scripts allow-same-origin allow-presentation allow-popups allow-popups-to-escape-sandbox';

/** 미디어 주소를 가진 태그 */
const MEDIA_TAGS: Record<string, string[]> = { video: ['src', 'poster'], audio: ['src'], source: ['src'], track: ['src'] };

/** 외부 영상 삽입 태그 — 정화 출력에 남지 않고 `renderEmbeds` 가 늘 iframe·링크로 바꾸거나 지운다 */
const EMBED_TAG = 'oembed';

/** 화면 비율 → CSS aspect-ratio */
const ASPECT_RATIO: Record<string, string> = { '16:9': '16 / 9', '9:16': '9 / 16', '4:3': '4 / 3', '1:1': '1 / 1' };

/**
 * 정규식 문자열을 안전하게 컴파일합니다(잘못된 패턴은 null).
 *
 * @param pattern 정규식 본문
 * @param flags 플래그
 * @returns 정규식 또는 null
 */
function compile(pattern: string, flags = ''): RegExp | null {
  try {
    return new RegExp(pattern, flags);
  } catch {
    return null;
  }
}

/**
 * http(s) 주소인지.
 *
 * @param value 주소
 * @returns http(s) 면 true
 */
function isHttpUrl(value: string): boolean {
  return /^https?:\/\//i.test(value.trim());
}

export class ContentPolicyRuntime {
  constructor(private readonly deps: ContentPolicyRuntimeDeps) {}

  /**
   * 지금 표시 규칙 — 서버가 내려주지 않았으면(옛 서버·설정 미로드) null.
   *
   * @returns 규칙 또는 null
   */
  getPolicy(): ContentRenderPolicy | null {
    let settings: any;
    try {
      settings = this.deps.getGlobalSettings();
    } catch {
      return null;
    }
    const policy = settings?.content?.render_policy;

    return policy && typeof policy === 'object' && policy.version === 1 ? (policy as ContentRenderPolicy) : null;
  }

  /**
   * 본문을 정화합니다 — 규칙이 없으면 null(호출자가 종전 경로).
   *
   * @param purify 템플릿의 DOMPurify(팩토리 겸 인스턴스)
   * @param html 본문 HTML
   * @param baseConfig 템플릿 기본 설정(FORBID_TAGS·FORBID_ATTR 등)
   * @returns 정화한 HTML 또는 null
   */
  sanitize(purify: any, html: string, baseConfig: Record<string, any>): string | null {
    const policy = this.getPolicy();
    if (!policy || typeof purify !== 'function') {
      return null;
    }

    const instance = purify(typeof window !== 'undefined' ? window : undefined);
    if (!instance || typeof instance.sanitize !== 'function') {
      return null;
    }

    const enabledTags = new Set([...Object.keys(policy.attributes ?? {}), ...(policy.custom_tags ?? [])]);
    const baseForbid: string[] = Array.isArray(baseConfig.FORBID_TAGS) ? baseConfig.FORBID_TAGS : [];
    const serverForbid = new Set(policy.forbid_tags ?? []);
    // 금지 목록 = 템플릿 기본 금지 중 켜진 후보가 아닌 것 + 서버 금지 (호출자가 덧붙인 금지는 그대로 남는다 — 정책으로 풀 수 없음)
    // 호출자(컴포넌트 prop)가 덧붙인 금지는 규칙으로 풀 수 없다 — `G7_KEEP_FORBID_TAGS` 로 받아 늘 금지한다
    const keepForbid: string[] = Array.isArray(baseConfig.G7_KEEP_FORBID_TAGS) ? baseConfig.G7_KEEP_FORBID_TAGS : [];
    // 꺼진 후보의 표시 제거 태그(캡션 끔 → figcaption) — 금지 태그는 기본이 「태그만 벗기고 글자 남김」이라 ADD_FORBID_CONTENTS 로 내용째 지운다
    const dropTags: string[] = Array.isArray(policy.drop_tags) ? policy.drop_tags : [];
    const forbid = Array.from(new Set([...baseForbid.filter((tag) => !enabledTags.has(tag) || serverForbid.has(tag)), ...serverForbid, ...keepForbid, ...dropTags]));
    const { G7_KEEP_FORBID_TAGS: _keep, ...purifyConfig } = baseConfig;
    // oembed 는 외부 영상 삽입을 꺼도 통과시킨다 — renderEmbeds 가 원래 주소 링크로 바꾼다(봇 화면 finalize('bot') 과 같은 결과). 호출자 금지는 그대로
    const passEmbed = !keepForbid.includes(EMBED_TAG) && !serverForbid.has(EMBED_TAG);
    const extraAttrs = Array.from(new Set([...Object.values(policy.attributes ?? {}).flat(), ...(passEmbed ? ['url'] : [])]));
    const mediaPatterns = (policy.media_src_patterns ?? []).map((p) => compile(p)).filter((r): r is RegExp => r !== null);

    instance.addHook?.('uponSanitizeElement', (node: Element, data: { tagName: string }) => {
      const tag = String(data?.tagName ?? '').toLowerCase();
      const attrs = MEDIA_TAGS[tag];
      if (!attrs || !enabledTags.has(tag) || typeof node?.getAttribute !== 'function') {
        return;
      }
      for (const name of attrs) {
        const value = (node.getAttribute(name) ?? '').trim();
        if (value !== '' && !mediaPatterns.some((re) => re.test(value))) {
          node.parentNode?.removeChild(node);
          return;
        }
      }
    });

    instance.addHook?.('uponSanitizeAttribute', (node: Element, data: { attrName: string; keepAttr: boolean }) => {
      const tag = String(node?.nodeName ?? '').toLowerCase();
      const allowed = policy.attributes?.[tag];
      const name = String(data?.attrName ?? '').toLowerCase();
      if (allowed && !allowed.includes(name) && !name.startsWith('data-')) {
        data.keepAttr = false;
      }
      // oembed 를 위해 더한 url 속성은 다른 태그에서 쓰지 않는다
      if (name === 'url' && tag !== EMBED_TAG) {
        data.keepAttr = false;
      }
    });

    const fragment = instance.sanitize(html, {
      ...purifyConfig,
      FORBID_TAGS: forbid,
      ADD_TAGS: Array.from(new Set([...(baseConfig.ADD_TAGS ?? []), ...(policy.extra_tags ?? []), ...(passEmbed ? [EMBED_TAG] : [])])).filter((tag) => !keepForbid.includes(tag) && !dropTags.includes(tag)),
      ADD_ATTR: Array.from(new Set([...(baseConfig.ADD_ATTR ?? []), ...extraAttrs])),
      ADD_FORBID_CONTENTS: Array.from(new Set([...(Array.isArray(baseConfig.ADD_FORBID_CONTENTS) ? baseConfig.ADD_FORBID_CONTENTS : []), ...dropTags])),
      RETURN_DOM_FRAGMENT: true,
    }) as DocumentFragment;

    this.renderEmbeds(fragment, policy);

    const holder = document.createElement('div');
    holder.appendChild(fragment);

    return holder.innerHTML;
  }

  /**
   * 외부 영상 주소를 해석합니다.
   *
   * @param url 원본 주소
   * @param policy 규칙
   * @returns 해석 결과 또는 null
   */
  resolveEmbed(url: string, policy: ContentRenderPolicy | null = this.getPolicy()): ResolvedEmbed | null {
    if (!policy || !policy.allow_external_embed || !isHttpUrl(url)) {
      return null;
    }
    for (const provider of policy.embed_providers ?? []) {
      for (let i = 0; i < (provider.patterns ?? []).length; i++) {
        const match = compile(provider.patterns[i], 'i')?.exec(url.trim());
        const id = match?.groups?.id;
        if (id) {
          return {
            provider,
            id,
            playerUrl: provider.player_url.replace('{id}', encodeURIComponent(id)),
            aspect: provider.aspects?.[i] ?? provider.aspect ?? '16:9',
          };
        }
      }
    }

    return null;
  }

  /**
   * `oembed[url]` 을 플레이어 iframe 또는 링크로 바꿉니다. 외부 영상 삽입을 껐거나 허용 서비스가 아니면 http(s) 주소는
   * `rel="noopener noreferrer nofollow"` 새 창 링크, 그 밖의 주소는 지운다(봇 화면 `finalize('bot')` 과 같은 결과).
   *
   * @param root 정화한 조각
   * @param policy 규칙
   * @returns 반환값 없음
   */
  renderEmbeds(root: DocumentFragment | Element, policy: ContentRenderPolicy | null = this.getPolicy()): void {
    const embeds = Array.from(root.querySelectorAll?.('oembed') ?? []);
    for (const embed of embeds) {
      const url = (embed.getAttribute('url') ?? '').trim();
      const resolved = this.resolveEmbed(url, policy);
      const doc = embed.ownerDocument ?? document;

      if (resolved) {
        const box = doc.createElement('div');
        box.setAttribute('class', 'g7-embed');
        box.setAttribute('data-provider', resolved.provider.id);
        box.setAttribute('style', `position:relative;width:100%;aspect-ratio:${ASPECT_RATIO[resolved.aspect] ?? '16 / 9'};${resolved.aspect === '9:16' ? 'max-width:360px;margin:0 auto;' : ''}`);
        const frame = doc.createElement('iframe');
        frame.setAttribute('src', resolved.playerUrl);
        frame.setAttribute('sandbox', EMBED_SANDBOX);
        // 전체 화면은 `allow` 의 `fullscreen` 하나로만 준다 — `allowfullscreen` 을 함께 두면 브라우저가 「allow 가 우선」 경고를 남기고,
        // 서비스 설정에서 fullscreen 을 뺀 경우에도 옛 속성이 허용해 버린다.
        frame.setAttribute('allow', (resolved.provider.allow ?? []).join('; '));
        frame.setAttribute('loading', 'lazy');
        frame.setAttribute('title', resolved.provider.name);
        frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        frame.setAttribute('style', 'position:absolute;inset:0;width:100%;height:100%;border:0;');
        box.appendChild(frame);
        embed.parentNode?.replaceChild(box, embed);

        continue;
      }

      if (!isHttpUrl(url)) {
        embed.parentNode?.removeChild(embed);

        continue;
      }

      const link = doc.createElement('a');
      link.setAttribute('href', url);
      link.setAttribute('rel', 'noopener noreferrer nofollow');
      link.setAttribute('target', '_blank');
      link.textContent = url;
      embed.parentNode?.replaceChild(link, embed);
    }
  }

  /**
   * 편집기 제약 — 서비스 목록·금지 태그·업로드 동영상/오디오 허용.
   *
   * @returns 제약 (규칙이 없으면 null)
   */
  editorConstraints(): { providers: EmbedProviderPolicy[]; forbidTags: string[]; allowExternalEmbed: boolean; allowUploadedVideo: boolean; allowUploadedAudio: boolean } | null {
    const policy = this.getPolicy();
    if (!policy) {
      return null;
    }

    return {
      providers: policy.allow_external_embed ? policy.embed_providers ?? [] : [],
      forbidTags: policy.forbid_tags ?? [],
      allowExternalEmbed: policy.allow_external_embed === true,
      allowUploadedVideo: policy.allow_uploaded_video === true,
      allowUploadedAudio: policy.allow_uploaded_audio === true,
    };
  }
}
