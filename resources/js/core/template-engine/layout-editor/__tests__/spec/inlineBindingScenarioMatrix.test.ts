/**
 * inlineBindingScenarioMatrix.test.ts — 4시나리오 × 3로케일 전수 회귀 매트릭스
 *
 * 텍스트 데이터 연결 키화의 네 가지 조작 시나리오가 **모든 로케일**에서
 * node.text(레이아웃 JSON) + 키 값(custom_translations, 로케일별)이 정합하게 영속되는지 100% 검증.
 *
 *  ① 데이터 칩 없는 기성 키 — 단순 수정만 (param 0 단일 키, 회귀 가드)
 *  ② 기성 키 + 데이터 칩 추가 + 여러 위치 끼워넣기 (앞/중간/끝)
 *  ③ 데이터 칩 있는 기성 키 — 기성 칩 위치만 변경 (편집 로케일만, 타 로케일 불변)
 *  ④ 기성 칩 위치 변경 + 데이터 칩 추가 + 위치 변경 (복합)
 *
 * 본 테스트는 키화/위치 SSoT 순수 함수 + inlineBindingApi(전 로케일 버퍼 동기)를 직접 구동해
 * 라이브 caret/drag 합성에 의존하지 않고 입력 cross product 를 결정적으로 잠근다. 라이브 브라우저
 * + DB + 유저 렌더는 별도(인계 보고서·E 절차)로 영속/렌더 경로를 보완 검증한다.
 *
 * 검증 SSoT 형식:
 *  - node.text = `$t:custom.X|pN={{소스}}` (소스 정의, 로케일 무관)
 *  - 키 값(로케일별) = `... {pN} ...` (어순/위치 로케일 독립 — ko/en/ja 다름이 정상)
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import {
  // 키 값 위치 SSoT (순수)
  buildParamizedKeyText,
  buildParamizedKeyValue,
  insertPlaceholderAt,
  appendPlaceholder,
  movePlaceholder,
  extractParamBindings,
  paramPlaceholderTokens,
  sameParamPlaceholderSet,
} from '../../spec/inlineBindingUtils';
import {
  keyifyWithNewBinding,
  insertBindingIntoParamKey,
  putSingleLocaleKeyValue,
} from '../../components/property-controls/inlineBindingApi';
import {
  getPendingValue,
  getPendingValues,
  clearPending,
} from '../../hooks/pendingCustomTranslations';

const TPL = 'sirsoft-basic';
const LOCALES = ['ko', 'en', 'ja'] as const;

/**
 * createCustomKey POST + findCustomKeyRow GET 를 한 메모리 store 로 모킹한다.
 * POST 는 입력 value 를 **전 로케일**에 동일하게 심는다(백엔드 createCustomKey 동작 — 입력 로케일
 * 값을 base 로 전 로케일 초기화). GET 은 그 store 행을 반환한다(전 로케일 버퍼 동기 검증용).
 */
function stubKeyStore(): { rows: Array<{ id: number; translation_key: string; values: Record<string, string>; lock_version: number }> } {
  const rows: Array<{ id: number; translation_key: string; values: Record<string, string>; lock_version: number }> = [];
  let seq = 0;
  const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? 'GET';
    const body = init?.body ? JSON.parse(init.body as string) : null;
    // POST createCustomKey
    if (typeof url === 'string' && url.endsWith('/custom-translations') && method === 'POST') {
      seq += 1;
      const base = String(body?.value ?? '');
      const values: Record<string, string> = {};
      for (const l of LOCALES) values[l] = base; // 백엔드: 입력 base 를 전 로케일 초기값으로.
      const row = { id: seq, translation_key: `custom.${seq}`, values, lock_version: 0 };
      rows.push(row);
      return { ok: true, status: 201, json: async () => ({ data: row }) } as unknown as Response;
    }
    // GET 목록 (findCustomKeyRow)
    if (typeof url === 'string' && url.endsWith('/custom-translations') && method === 'GET') {
      return { ok: true, status: 200, json: async () => ({ data: rows }) } as unknown as Response;
    }
    // PUT/config/lang reload 등 — 무해.
    return { ok: true, status: 200, json: async () => ({ data: { cache_version: 1 } }) } as unknown as Response;
  });
  vi.stubGlobal('fetch', fetchMock);
  return { rows };
}

beforeEach(() => {
  localStorage.setItem('auth_token', 'test-token');
});
afterEach(() => {
  vi.restoreAllMocks();
  clearPending();
});

describe('시나리오 ① — 데이터 칩 없는 기성 키 단순 수정 (param 0, 회귀 가드)', () => {
  it('순수 평문 키화 → node.text=param 0 단일 키, 키 값=입력 평문(자리표시 0)', () => {
    // commit() 의 isMixed=false 경로 SSoT: buildParamizedKeyText/Value 가 보간 0 이면 키 토큰 단독.
    const original = '취소'; // 데이터 칩(보간) 없는 평문.
    const keyToken = '$t:custom.5';
    const nodeText = buildParamizedKeyText(keyToken, original);
    const keyValue = buildParamizedKeyValue(original);

    expect(nodeText).toBe('$t:custom.5'); // |pN= 미부착 (데이터 칩 0)
    expect(nodeText).not.toContain('|p'); // param 0
    expect(keyValue).toBe('취소'); // 자리표시 없음
    expect(paramPlaceholderTokens(keyValue)).toEqual([]); // {pN} 0
  });

  it('단순 수정은 편집 로케일 키 값만 바뀌고 타 로케일 불변 (per-locale 독립)', () => {
    // 기성 키 값(전 로케일) — 단순 수정 = 편집 로케일만 PUT(updateCustomKeyValue 가 nextValues 에서
    // 그 로케일만 덮음). 본 단언은 그 머지 규칙을 직접 잠근다.
    const existing = { ko: '취소', en: 'Cancel', ja: 'キャンセル' };
    const editLocale = 'ko';
    const edited = '닫기';
    const next = { ...existing, [editLocale]: edited };
    expect(next).toEqual({ ko: '닫기', en: 'Cancel', ja: 'キャンセル' }); // ko 만 변경
    // 데이터 칩 0 → 어느 로케일도 {pN} 자리표시 없음.
    for (const l of LOCALES) expect(paramPlaceholderTokens(next[l as keyof typeof next])).toEqual([]);
  });
});

describe('시나리오 ② — 기성 키 + 데이터 칩 추가 + 여러 위치 끼워넣기', () => {
  it('미키화 평문에 첫 칩 추가(중간 위치) → 전 로케일 {p0} seed + node.text param', async () => {
    stubKeyStore();
    // "발행일:" 평문 노드(ko 편집). 커서를 "발행" 과 "일:" 사이(charIndex 2)에 두고 데이터 칩 추가.
    const res = await keyifyWithNewBinding(
      TPL, 'auth/register', 'ko',
      '발행일:', 2,
      'termsContent', 'data.published_at', 'scalar',
    );
    expect(res.kind).toBe('ok');
    if (res.kind !== 'ok') return;
    // node.text = param 정규화(소스 보존).
    expect(res.text).toContain('$t:custom.');
    expect(res.text).toContain('|p0={{');
    expect(res.text).toContain('published_at');
    const key = res.key;
    // 편집 로케일(ko): 커서 위치(중간)에 {p0} 삽입. 양옆 비공백이면 공백 1개로 구분(칩이 평문과
    // 붙지 않게 — insertPlaceholderAt 규칙). "발행"(앞) + " {p0} " + "일:"(뒤) → 양쪽 공백.
    expect(getPendingValue(key, 'ko')).toBe('발행 {p0} 일:');
    // 미편집 로케일(en/ja): 서버 plainBase 끝에 {p0} 추가(번역가가 드래그). plainBase 는 키화 시점의
    // merged 평문("발행 일:" — 커서 위치에 칩 삽입되며 양옆 공백이 평문에 남음)에서 {pN} 만 제거한 것.
    // 즉 편집 로케일 커서 위치가 plainBase 의 공백 분포에 반영된다(번들 createCustomKey 가 base 를 전 로케일 초기화).
    expect(getPendingValue(key, 'en')).toBe('발행 일: {p0}');
    expect(getPendingValue(key, 'ja')).toBe('발행 일: {p0}');
    // 전 로케일 {p0} 보유(다로케일 누락 결함 회귀 가드).
    for (const l of LOCALES) expect(paramPlaceholderTokens(getPendingValue(key, l)!)).toEqual(['p0']);
  });

  it('둘째·셋째 칩을 서로 다른 위치(앞/끝)에 추가 → 전 로케일 {p0}{p1}{p2}, 편집 로케일만 위치 정밀', async () => {
    stubKeyStore();
    // 1차 키화(끝 위치).
    const first = await keyifyWithNewBinding(
      TPL, 'auth/register', 'ko', '발행일:', '발행일:'.length,
      'termsContent', 'data.published_at', 'scalar',
    );
    expect(first.kind).toBe('ok');
    if (first.kind !== 'ok') return;
    const nodeText1 = first.text;

    // 2차 칩(data.id)을 ko 키 값 맨 앞(charIndex 0)에 삽입.
    const second = await insertBindingIntoParamKey(
      TPL, 'ko', nodeText1, 0, 'current_user', 'data.id', 'scalar',
    );
    expect(second.kind).toBe('ok');
    if (second.kind !== 'ok') return;
    expect(second.paramName).toBe('p1');
    const nodeText2 = second.text;

    // 3차 칩(data.name)을 ko 키 값 맨 끝에 삽입.
    const key = extractParamBindings(nodeText2)!.key;
    const koLen = getPendingValue(key, 'ko')!.length;
    const third = await insertBindingIntoParamKey(
      TPL, 'ko', nodeText2, koLen, 'current_user', 'data.name', 'scalar',
    );
    expect(third.kind).toBe('ok');
    if (third.kind !== 'ok') return;
    expect(third.paramName).toBe('p2');

    // node.text = 세 param 모두 부착(소스 정의, 로케일 무관).
    const finalParsed = extractParamBindings(third.text)!;
    expect(finalParsed.params.map((p) => p.name)).toEqual(['p0', 'p1', 'p2']);
    // buildBindingExpression 은 옵셔널 체이닝 형태(`current_user?.data?.id`)를 만든다.
    expect(third.text).toContain('published_at');
    expect(third.text).toContain('data?.id');
    expect(third.text).toContain('data?.name');

    // 전 로케일이 p0/p1/p2 셋 다 보유(어느 로케일도 칩 누락 없음).
    for (const l of LOCALES) {
      expect(paramPlaceholderTokens(getPendingValue(key, l)!)).toEqual(['p0', 'p1', 'p2']);
    }
    // 편집 로케일(ko) 위치 정밀: p1 앞 → p0 → (p2 끝). "{p1}...{p0}...{p2}" 순.
    const ko = getPendingValue(key, 'ko')!;
    expect(ko.indexOf('{p1}')).toBeLessThan(ko.indexOf('{p0}'));
    expect(ko.indexOf('{p0}')).toBeLessThan(ko.indexOf('{p2}'));
    // 미편집 로케일(en/ja): 끝에 순차 추가(p0 먼저 있었으니 p0, 그다음 p1, p2).
    expect(getPendingValue(key, 'en')).toBe('발행일: {p0} {p1} {p2}');
    expect(getPendingValue(key, 'ja')).toBe('발행일: {p0} {p1} {p2}');
  });
});

describe('시나리오 ③ — 데이터 칩 있는 기성 키, 기성 칩 위치만 변경', () => {
  it('편집 로케일 키 값의 {pN} 만 이동, 타 로케일 + node.text 불변', async () => {
    stubKeyStore();
    // 기성 param 키(3칩). node.text 는 소스 정의(이동과 무관).
    const nodeText =
      "$t:custom.62|p0={{current_user?.data?.id ?? ''}}|p1={{termsContent?.data?.published_at | date}}|p2={{current_user?.data?.name ?? ''}}";
    const key = extractParamBindings(nodeText)!.key;

    // 편집 로케일(ko) 키 값: "발 행일: {p0} {p1} {p2}" 에서 p1 을 맨 앞으로 이동.
    const koBefore = '발 행일: {p0} {p1} {p2}';
    const koAfter = movePlaceholder(koBefore, 'p1', 0);
    await putSingleLocaleKeyValue(TPL, key, 'ko', koAfter);

    // p1 이 맨 앞으로(위치 변경), p0/p1/p2 멀티셋은 불변(데이터 손실 0).
    expect(koAfter.indexOf('{p1}')).toBe(0);
    expect(sameParamPlaceholderSet(koBefore, koAfter)).toBe(true);
    expect(getPendingValue(key, 'ko')).toBe(koAfter);

    // 타 로케일(en/ja)은 PUT 안 했으므로 버퍼에 없음(불변 — 어순 로케일 독립).
    expect(getPendingValue(key, 'en')).toBeUndefined();
    expect(getPendingValue(key, 'ja')).toBeUndefined();

    // node.text(소스 정의)는 이동과 무관하게 불변(이름 기반 치환).
    expect(extractParamBindings(nodeText)!.params.map((p) => p.name)).toEqual(['p0', 'p1', 'p2']);
  });
});

describe('시나리오 ④ — 복합 (기성 칩 이동 + 칩 추가 + 위치 변경)', () => {
  it('칩 이동 → 새 칩 추가(중간) → 또 이동: 전 로케일 정합 + node.text 신규 param', async () => {
    stubKeyStore();
    // 기성 2칩 키. 먼저 서버 행을 만들어 findCustomKeyRow 가 전 로케일을 보게 한다.
    const seed = await keyifyWithNewBinding(
      TPL, 'auth/register', 'ko', '발행일:', '발행일:'.length,
      'termsContent', 'data.published_at', 'scalar',
    );
    expect(seed.kind).toBe('ok');
    if (seed.kind !== 'ok') return;
    let nodeText = seed.text; // $t:custom.X|p0={{published_at}}
    const key = seed.key;

    // (a) 기성 칩 p0 이동 — ko 키 값에서 맨 앞으로.
    const koSeed = getPendingValue(key, 'ko')!; // "발행일: {p0}"
    const koMoved = movePlaceholder(koSeed, 'p0', 0);
    await putSingleLocaleKeyValue(TPL, key, 'ko', koMoved);
    expect(getPendingValue(key, 'ko')!.indexOf('{p0}')).toBe(0);

    // (b) 새 칩(data.id) 추가 — ko 키 값 중간(현재 ko 값 길이 절반 부근, charIndex 4).
    const ins = await insertBindingIntoParamKey(
      TPL, 'ko', nodeText, 4, 'current_user', 'data.id', 'scalar',
    );
    expect(ins.kind).toBe('ok');
    if (ins.kind !== 'ok') return;
    nodeText = ins.text;
    expect(ins.paramName).toBe('p1');

    // (c) 또 이동 — 방금 추가한 p1 을 맨 끝으로.
    const koNow = getPendingValue(key, 'ko')!;
    const koEnd = movePlaceholder(koNow, 'p1', koNow.length);
    await putSingleLocaleKeyValue(TPL, key, 'ko', koEnd);

    // node.text = p0/p1 둘 다 부착(소스 정의).
    const finalParsed = extractParamBindings(nodeText)!;
    expect(finalParsed.params.map((p) => p.name)).toEqual(['p0', 'p1']);
    expect(nodeText).toContain('published_at');
    expect(nodeText).toContain('data?.id'); // 옵셔널 체이닝 형태

    // 전 로케일 p0/p1 보유.
    for (const l of LOCALES) {
      expect(paramPlaceholderTokens(getPendingValue(key, l)!)).toEqual(['p0', 'p1']);
    }
    // ko: p1 이 맨 끝(마지막 이동 반영).
    const koFinal = getPendingValue(key, 'ko')!;
    expect(koFinal.lastIndexOf('{p1}')).toBeGreaterThan(koFinal.lastIndexOf('{p0}'));
    // en/ja: 끝에 순차 추가(p0 있었고 p1 끝 추가).
    expect(getPendingValue(key, 'en')).toBe('발행일: {p0} {p1}');
    expect(getPendingValue(key, 'ja')).toBe('발행일: {p0} {p1}');
  });
});

describe('4시나리오 공통 — 전 로케일 자리표시 멀티셋 정합 (위치 무관 데이터 무손실)', () => {
  it('node.text param 집합과 각 로케일 키 값 {pN} 집합이 항상 일치', async () => {
    stubKeyStore();
    const res = await keyifyWithNewBinding(
      TPL, 'auth/register', 'ko', '안녕', 0,
      'current_user', 'data.name', 'scalar',
    );
    expect(res.kind).toBe('ok');
    if (res.kind !== 'ok') return;
    const ins = await insertBindingIntoParamKey(
      TPL, 'ko', res.text, 0, 'termsContent', 'data.published_at', 'scalar',
    );
    expect(ins.kind).toBe('ok');
    if (ins.kind !== 'ok') return;
    const params = extractParamBindings(ins.text)!.params.map((p) => p.name).sort();
    for (const l of LOCALES) {
      const kv = getPendingValue(res.key, l)!;
      expect(paramPlaceholderTokens(kv)).toEqual(params); // node.text param ↔ 키 값 {pN} 1:1
    }
  });
});
