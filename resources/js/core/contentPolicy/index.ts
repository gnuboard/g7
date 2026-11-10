/**
 * 본문 콘텐츠 규칙 런타임 진입점 (engine-v1.90.0, #883 5단계)
 *
 * `G7Core.contentPolicy` 파사드(`initContentPolicyAPI`)와 시험이 {@link getContentPolicyRuntime} 으로 같은 인스턴스를 쓴다.
 */

import { ContentPolicyRuntime } from './ContentPolicyRuntime';

export { ContentPolicyRuntime, EMBED_SANDBOX } from './ContentPolicyRuntime';
export type { ContentPolicyRuntimeDeps, ContentRenderPolicy, EmbedProviderPolicy, ResolvedEmbed } from './ContentPolicyRuntime';

let runtime: ContentPolicyRuntime | null = null;

/**
 * 런타임을 돌려줍니다(처음 부를 때 만든다). 규칙은 `_global.settings` 에서 그때그때 읽는다.
 *
 * @returns 런타임
 */
export function getContentPolicyRuntime(): ContentPolicyRuntime {
  if (!runtime) {
    runtime = new ContentPolicyRuntime({
      getGlobalSettings: () => {
        const G7Core = (window as any).G7Core;
        const state = G7Core?.state?.get?.() ?? {};

        return state?._global?.settings ?? state?.settings ?? null;
      },
    });
  }

  return runtime;
}

/**
 * 시험용 — 런타임을 버린다.
 *
 * @returns 반환값 없음
 */
export function resetContentPolicyRuntimeForTests(): void {
  runtime = null;
}
