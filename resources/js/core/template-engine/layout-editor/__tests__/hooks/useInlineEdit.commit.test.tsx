/**
 * useInlineEdit.commit.test.tsx — 인라인 편집 commit 통합
 *
 * 단위(classifyInlineText)만으로는 안 잡히는 결함을 잡기 위한 **commit 전체 경로** 통합 테스트
 * (engine-regression-code-first 원칙 — 라이브 example.com 에서 평문+보간 노드 키화 시 보간 소실
 * 결함을 Chrome MCP 로 재현 → 이 테스트로 결정적 재현·잠금).
 *
 * 검증:
 *  - 평문+보간 노드(`회원 {{current_user?.data?.id ?? ''}}`) 키화 시:
 *    (a) createCustomKey POST body.value = 자리표시 문장(`회원 {p0}`) — 보간 보존
 *    (b) 패치된 node.text = `$t:custom....|p0={{...}}` — param 부착(보간 보존)
 *  - 순수 평문 노드 키화 시: POST value = 입력값, node.text = `$t:custom....`(param 0).
 */

import React from 'react';
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, act, cleanup } from '@testing-library/react';
import { LayoutEditorProvider } from '../../LayoutEditorContext';
import { LayoutDocumentProvider } from '../../LayoutDocumentContext';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import { useInlineEdit } from '../../hooks/useInlineEdit';
import { getPendingValue, clearPending } from '../../hooks/pendingCustomTranslations';
import type { UseLayoutDocumentResult, LoadedLayoutDocument } from '../../hooks/useLayoutDocument';
import type { EditorNode } from '../../utils/layoutTreeUtils';

function buildDocCtx(initialComponents: EditorNode[]): {
  ctx: UseLayoutDocumentResult;
  getComponents: () => EditorNode[];
} {
  let document: LoadedLayoutDocument = {
    layoutName: 'home',
    raw: { components: initialComponents },
    lockVersion: 1,
  };
  const ctx: UseLayoutDocumentResult = {
    document,
    isLoading: false,
    error: null,
    isDirty: false,
    saveSuccessCounter: 0,
    reload: async () => {},
    patchLayout: (patcher) => {
      const current = (document.raw.components as EditorNode[]) ?? [];
      const next = patcher(current);
      document = { ...document, raw: { ...document.raw, components: next } };
      (ctx as unknown as { document: LoadedLayoutDocument }).document = document;
    },
    setLayoutComponents: (next) => {
      document = { ...document, raw: { ...document.raw, components: next } };
      (ctx as unknown as { document: LoadedLayoutDocument }).document = document;
    },
    save: async () => ({ kind: 'success', newLockVersion: 2 }),
  };
  return { ctx, getComponents: () => (document.raw.components as EditorNode[]) ?? [] };
}

/** 훅을 마운트하고 commit + keyifyChipValue 핸들을 ref 로 노출. */
function Harness({
  ctx,
  onReady,
}: {
  ctx: UseLayoutDocumentResult;
  onReady: (hook: ReturnType<typeof useInlineEdit>) => void;
}): React.ReactElement {
  const hook = useInlineEdit();
  React.useEffect(() => {
    onReady(hook);
  }, [hook, onReady]);
  return <span data-testid="harness" />;
}

function mount(initialComponents: EditorNode[]) {
  const { ctx, getComponents } = buildDocCtx(initialComponents);
  const engine = new TranslationEngine();
  let hookRef: ReturnType<typeof useInlineEdit> | null = null;
  render(
    <TranslationProvider translationEngine={engine} translationContext={{ templateId: 'test', locale: 'ko' }}>
      <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
        <LayoutDocumentProvider value={ctx}>
          <Harness ctx={ctx} onReady={(h) => { hookRef = h; }} />
        </LayoutDocumentProvider>
      </LayoutEditorProvider>
    </TranslationProvider>,
  );
  return {
    ctx,
    getComponents,
    getCommit: () => hookRef!.commit,
    getKeyifyChipValue: () => hookRef!.keyifyChipValue,
  };
}

/** createCustomKey POST 응답 mock + 호출 본문 캡처. */
function stubCreateKeyFetch(): { calls: Array<{ url: string; body: any }> } {
  const calls: Array<{ url: string; body: any }> = [];
  let seq = 0;
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
    const body = init?.body ? JSON.parse(init.body as string) : null;
    calls.push({ url, body });
    if (typeof url === 'string' && url.includes('/custom-translations') && init?.method === 'POST') {
      seq += 1;
      return {
        ok: true,
        status: 201,
        json: async () => ({
          data: {
            id: seq,
            translation_key: `custom.home.${seq}`,
            values: { ko: body?.value ?? '' },
            lock_version: 0,
          },
        }),
      } as unknown as Response;
    }
    // config.json (bustTranslationCache) + lang reload — 무해 응답.
    return { ok: true, status: 200, json: async () => ({ data: { cache_version: 1 } }) } as unknown as Response;
  });
  vi.stubGlobal('fetch', fetchMock);
  return { calls };
}

beforeEach(() => {
  localStorage.setItem('auth_token', 'test-token');
});
afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  clearPending();
});

describe('useInlineEdit.commit — 평문+보간 키화 param 정규화 (라이브 회귀)', () => {
  it('평문+보간 노드 키화 → POST value=자리표시 문장 + node.text=param 부착(보간 보존)', async () => {
    const { calls } = stubCreateKeyFetch();
    const original = "회원 {{current_user?.data?.id ?? ''}}";
    const { getComponents, getCommit } = mount([{ name: 'P', text: original }]);

    await act(async () => {
      await getCommit()([0], '회원수'); // 평문을 "회원수"로 편집 후 키화
    });

    // (a) createCustomKey POST body.value = 자리표시 문장 — 보간 자리가 {p0} 로 보존돼야 한다.
    const post = calls.find((c) => c.url.includes('/custom-translations') && c.body && 'value' in c.body);
    expect(post, 'createCustomKey POST 가 발생해야 함').toBeTruthy();
    // ⬇️ 결함 시: post.body.value === '회원수' (보간 소실). 기대: 자리표시 문장.
    expect(post!.body.value).toBe('회원 {p0}');

    // (b) 패치된 node.text = `$t:custom.home.1|p0={{...}}` — param 부착으로 보간 보존.
    const node = getComponents()[0];
    expect(node.text).toContain('$t:custom.home.');
    expect(node.text).toContain("|p0={{current_user?.data?.id ?? ''}}");
  });

  it('순수 평문 노드 키화 → POST value=입력값 + node.text=param 0 단일 키', async () => {
    const { calls } = stubCreateKeyFetch();
    const { getComponents, getCommit } = mount([{ name: 'P', text: '회원' }]);

    await act(async () => {
      await getCommit()([0], '회원 수');
    });

    const post = calls.find((c) => c.url.includes('/custom-translations') && c.body && 'value' in c.body);
    expect(post!.body.value).toBe('회원 수');
    const node = getComponents()[0];
    expect(node.text).toMatch(/^\$t:custom\.home\.\d+$/); // param 미부착
  });

  it('D-44: "공통 문구 + 데이터"(lang 키 + 보간) 키화 → 키 값에 raw $t: 미박힘 (재귀 폭증 차단)', async () => {
    // 재현: $t:policy.published_at: {{date}} 노드를 인라인 편집 키화.
    // 결함 시: createCustomKey POST value = "$t:policy.published_at: {p0}" (lang 키 참조) →
    //   다음 편집 때 그 값을 또 키화해 $t:custom.X|p0={p0} 무한 증식(DB .19~.26 흔적).
    // 수정 후: lang 키를 "발행일" 로 평문화 → POST value = "발행일: {p0}" (lang 키 참조 0).
    const { calls } = stubCreateKeyFetch();
    // TranslationEngine 싱글톤 사전에 policy.published_at = 발행일 주입(translateKey 가 읽음).
    const engine = TranslationEngine.getInstance();
    engine.setTranslationValue('test-tpl', 'ko', 'policy.published_at', '발행일');

    const original = '$t:policy.published_at: {{termsContent?.data?.published_at | date}}';
    const { getComponents, getCommit } = mount([{ name: 'Span', text: original }]);

    await act(async () => {
      // 인라인 편집 확정값은 무관(키화는 원본 text 의 평문화 + 보간 보존 기준).
      await getCommit()([0], '발행일:');
    });

    const post = calls.find((c) => c.url.includes('/custom-translations') && c.body && 'value' in c.body);
    expect(post, 'createCustomKey POST 가 발생해야 함').toBeTruthy();
    // 핵심 단언: 키 값에 raw $t: lang 키 참조가 없어야 한다(재귀 원천 차단).
    expect(post!.body.value).not.toContain('$t:');
    expect(post!.body.value).toBe('발행일: {p0}');

    // 패치된 node.text = $t:custom....|p0={{...date}} — 보간(데이터)은 param 으로 보존(칩 렌더 대상).
    const node = getComponents()[0];
    expect(node.text).toContain('$t:custom.home.');
    expect(node.text).toContain('|p0={{termsContent?.data?.published_at | date}}');
    // node.text 의 키 토큰 자체는 새 custom 키 — 이전 lang 키(policy.published_at) 참조 아님.
    expect(node.text).not.toContain('policy.published_at|'); // lang 키를 키 토큰으로 쓰지 않음
  });
});

describe('useInlineEdit.keyifyChipValue — 칩 온 엔트리 키화', () => {
  it('미키화 데이터 노드 칩 편집기 내용 변경 시 키화 → POST=평문 base(자리표시 0) + 버퍼=자리표시 문장 + node.text=param', async () => {
    // 데이터 든 미키화 노드를 칩 편집기에서 편집(키 값 "발행일자: {p0}")하면:
    //  - createCustomKey POST value = 평문 base("발행일자", {pN} 제거) — 속성패널 keyifyWithNewBinding 과
    //    동일(미저장 새로고침 시 raw {pN} 미노출, desync 0).
    //  - 자리표시 문장("발행일자: {p0}")은 저장-지연 버퍼 → 레이아웃 [저장] 시 flush.
    //  - node.text = $t:custom....|p0={{원본보간}} (보간=칩 보존).
    const { calls } = stubCreateKeyFetch();
    // keyifyChipValue 가 deriveChipModel 로 lang 키를 평문화하므로 싱글톤 사전에 lang 값 주입
    // (translateKey 가 TranslationEngine.getInstance() 사용). 미주입 시 keyifiable:false → 보간 미부착.
    TranslationEngine.getInstance().setTranslationValue('test-tpl', 'ko', 'policy.published_at', '발행일');
    const original = '$t:policy.published_at: {{termsContent?.data?.published_at | date}}';
    const { getComponents, getKeyifyChipValue } = mount([{ name: 'Span', text: original }]);

    let createdKey: string | undefined;
    await act(async () => {
      // 사용자가 칩 편집기에서 "발행일:" 을 "발행일자:" 로 바꾼 키 값(자리표시 포함).
      const res = await getKeyifyChipValue()([0], '발행일자: {p0}');
      createdKey = res.translationKey;
    });

    const post = calls.find((c) => c.url.includes('/custom-translations') && c.body && 'value' in c.body);
    expect(post, 'createCustomKey POST 발생').toBeTruthy();
    // POST value = 평문 base("발행일자:" — 라벨 구분자 `:` 는 보존, {pN} 자리표시만 제거).
    expect(post!.body.value).toBe('발행일자:');
    expect(post!.body.value).not.toContain('{p'); // 자리표시 미포함
    expect(post!.body.value).not.toContain('$t:'); // raw lang 키 미포함

    // 저장-지연 버퍼에 자리표시 문장 기록(레이아웃 저장 시 flush).
    expect(createdKey).toBeTruthy();
    expect(getPendingValue(createdKey!, 'ko')).toBe('발행일자: {p0}');

    // node.text = param 형태(보간 보존 = 칩).
    const node = getComponents()[0];
    expect(node.text).toContain('$t:custom.home.');
    expect(node.text).toContain('|p0={{termsContent?.data?.published_at | date}}');

    // 결함 A 회귀: keyifyChipValue 가
    // bustTranslationCache(서버 lang 재fetch — 새 키 서버값=plainBase, 자리표시 없음) 직후
    // placeholder 를 **재-seed** 해야 캔버스가 `{p0}` 치환(데이터 칩) 문장을 유지한다. bust 가
    // seed 를 plainBase 로 덮어쓰면 `{p0}` 소실 → 데이터 미렌더(칩 소실). 엔진 사전에 placeholder
    // 가 남아 있는지 확인(서버 plainBase `"발행일자:"` 가 아니라 `"발행일자: {p0}"` 유지).
    const engineVal = TranslationEngine.getInstance().translate(createdKey!, { templateId: 'test-tpl', locale: 'ko' });
    expect(engineVal).toContain('{p0}'); // bust 후에도 자리표시(데이터 칩) 보존
    expect(engineVal).toBe('발행일자: {p0}');
  });

  it('G-2: 이미 param 키화된 노드에 keyifyChipValue 재호출 → 재키화 안 하고 현재 로케일 값만 버퍼(충돌 차단)', async () => {
    // 결함 G-2 재현(라이브 DB 확정): 미키화 노드에 '+데이터'(keyifyWithNewBinding)로 1차 키화하면
    // node.text 가 `$t:custom.X|p0={{published_at}}|p1={{data.id}}` 가 된다. 그 직후 저장 버튼이
    // activeKeyRef===null 이라 keyifyChipValue 를 또 호출하면, keyifyChipValue 가 이미 키화된
    // 노드를 무조건 createCustomKey 로 **재키화**해 둘째 키를 만들고 두 모델이 충돌한다(기존 칩
    // published_at 소실 + raw 보간 박힘). 수정 후: keyifyChipValue 는 노드가 이미 param 키이면
    // 새 키를 만들지 않고 그 키의 현재 로케일 값만 버퍼 기록(putSingleLocaleKeyValue 경로)한다.
    const { calls } = stubCreateKeyFetch();
    // 1차 키화가 끝난 상태의 노드 — node.text 가 이미 param 부착 키.
    const alreadyKeyed = '$t:custom.home.7|p0={{termsContent?.data?.published_at | date}}';
    const { getComponents, getKeyifyChipValue } = mount([{ name: 'Span', text: alreadyKeyed }]);

    let result: { kind: string; translationKey?: string } | undefined;
    await act(async () => {
      // 저장 시 keyifyChipValue 가 (충돌 경로로) 호출되는 상황을 직접 재현.
      result = await getKeyifyChipValue()([0], '발행일: {p0}');
    });

    // 핵심 단언 ①: createCustomKey POST 가 **발생하지 않아야** 한다(재키화 금지).
    const post = calls.find((c) => c.url.includes('/custom-translations') && c.body && 'value' in c.body);
    expect(post, 'G-2: 이미 키화된 노드는 createCustomKey 를 다시 호출하면 안 됨').toBeFalsy();

    // 핵심 단언 ②: node.text 가 새 키로 바뀌지 않고 기존 키(custom.home.7) + 기존 칩 보존.
    const node = getComponents()[0];
    expect(node.text).toBe(alreadyKeyed); // 기존 published_at 칩 보존, 둘째 키화 없음

    // 핵심 단언 ③: 반환은 기존 키이고, 현재 로케일 값은 버퍼에 기록(저장-지연).
    expect(result?.kind).toBe('updated');
    expect(result?.translationKey).toBe('custom.home.7');
    expect(getPendingValue('custom.home.7', 'ko')).toBe('발행일: {p0}');
  });

  it('S9-N4: 미키화 lang **named-param** 노드(`$t:user.*|count={{}}`) 칩 이동 후 키화 → createCustomKey 발생(저장 무반응 회귀 차단)', async () => {
    // 결함 (identity/challenge `남은 시도: {{count}}회` 노드 칩 순서 변경 후 저장
    // 무반응): keyifyChipValue 의 G-2 충돌 가드(`extractParamBindings(originalText)` 가 truthy 면
    // `updated` 반환)가 **미키화 lang named-param** 노드(`$t:user.identity.challenge.remaining_attempts
    // |count={{Math.max(...)}}`)도 매칭한다(PARAMIZED_KEY_RE 가 custom/lang 키를 구분 안 함). 그러면
    // 키화(createCustomKey) 없이 lang 키(`user.identity...`)에 pending 만 심고 `updated` 반환 →
    // 오버레이 handleInlineChipKeyify 가 `kind !== 'created'` 이라 null 반환 → 칩 편집기 안 닫힘 +
    // 캔버스 raw `{p0}` 노출 + DB 키 미생성(저장 무반응). 수정: existing 가드는 **custom 키일 때만**.
    const { calls } = stubCreateKeyFetch();
    // Shape 3 lang 값 주입 — deriveChipModel 이 `{{count}}` 를 칩으로 평문화하려면 lang 사전 필요.
    TranslationEngine.getInstance().setTranslationValue(
      'test-tpl', 'ko', 'user.identity.challenge.remaining_attempts', '남은 시도: {{count}}회',
    );
    const original =
      '$t:user.identity.challenge.remaining_attempts|count={{Math.max(0, (_local.maxAttempts ?? 0) - (_local.attempts ?? 0))}}';
    const { getComponents, getKeyifyChipValue } = mount([{ name: 'Span', text: original }]);

    let result: { kind: string; translationKey?: string } | undefined;
    await act(async () => {
      // 칩을 맨 앞으로 이동한 키 값(라이브 재현 — `{p0}남은 시도: 회`).
      result = await getKeyifyChipValue()([0], '{p0}남은 시도: 회');
    });

    // 핵심 단언 ①: createCustomKey POST 가 **발생해야** 한다(키화 — 저장 무반응 회귀 차단).
    const post = calls.find((c) => c.url.includes('/custom-translations') && c.body && 'value' in c.body);
    expect(post, 'S9-N4: 미키화 lang named-param 노드는 키화(createCustomKey)되어야 함').toBeTruthy();

    // 핵심 단언 ②: 반환 kind=created (오버레이가 편집기를 닫고 customKey 전환).
    expect(result?.kind).toBe('created');
    expect(result?.translationKey).toMatch(/^custom\.home\.\d+$/);

    // 핵심 단언 ③: node.text 가 새 custom 키 param 형태(lang 키 아님 — 칩=count 보간 보존).
    const node = getComponents()[0];
    expect(node.text).toContain('$t:custom.home.');
    expect(node.text).not.toContain('$t:user.identity'); // lang 키를 키 토큰으로 쓰지 않음
    expect(node.text).toContain('|p0={{Math.max(0, (_local.maxAttempts ?? 0) - (_local.attempts ?? 0))}}');

    // 핵심 단언 ④: lang 키(`user.identity...`)에 pending 이 심기지 않아야 한다(오염 차단 — `.4` 키 결함).
    expect(getPendingValue('user.identity.challenge.remaining_attempts', 'ko')).toBeUndefined();
    // 새 custom 키 버퍼에는 칩 이동 반영된 키 값.
    expect(getPendingValue(result!.translationKey!, 'ko')).toBe('{p0}남은 시도: 회');
  });
});
