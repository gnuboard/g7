/**
 * 외부 영상 주소 해석 — PHP 와 JS 가 같은 정규식으로 같은 id 를 낸다 (#883 5단계)
 *
 * 고정본(`resources/js/core/contentPolicy/__tests__/fixtures/render-policy.json`)은 PHP(`EmbedProviderRegistryTest::buildFixture`)가 만든 `render_policy` 와 표본 20개의 PHP
 * 해석 결과다. PHPUnit 이 고정본 = 지금 PHP 출력을 잠그고, 이 시험이 JS 런타임이 같은 결과를 내는지 잠근다.
 *
 * @scenario-manifest tests/scenarios/content-render-policy.yaml
 */
import { describe, expect, it } from 'vitest';
import fixture from './fixtures/render-policy.json';
import { ContentPolicyRuntime } from '../ContentPolicyRuntime';

describe('외부 영상 정규식 PHP↔JS 패리티', () => {
  /**
   * @scenario case=parity_fixture
   * @effects php_js_patterns_identical
   */
  it('표본 20개가 PHP 와 같은 서비스·id·플레이어 주소·비율로 해석된다', () => {
    const runtime = new ContentPolicyRuntime({ getGlobalSettings: () => ({ content: { render_policy: fixture.render_policy } }) });

    expect(fixture.samples).toHaveLength(20);
    for (const sample of fixture.samples) {
      const resolved = runtime.resolveEmbed(sample.url);
      expect({ url: sample.url, provider: resolved?.provider.id ?? null, id: resolved?.id ?? null, player_url: resolved?.playerUrl ?? null, aspect: resolved?.aspect ?? null }).toEqual(sample);
    }
  });
});
