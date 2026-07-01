/**
 * pendingCustomTranslations.ts — 커스텀 다국어 키 값 변경의 저장-지연 버퍼
 *
 * 데이터 칩(자리표시) 추가·이동·평문 편집·해제는 **키 값**(custom_translations.values)을 바꾼다.
 * 이를 서버에 **즉시 PUT** 하면, 같은 변경의 짝인 node.text 의 `|pN=` param 정의는 레이아웃
 * 저장 전까지 메모리에만 있어 — 저장 안 하고 새로고침 시 **키 값엔 `{pN}` 있는데 param 정의는
 * 없는 desync**(raw `{pN}` 노출 / 2번째 param 소실)가 난다.
 *
 * 해결: 키 값 변경을 본 **세션 버퍼**에만 기록하고, TranslationEngine 싱글톤에 낙관적으로 seed 해
 * 캔버스가 라이브로 반영하되 **서버 PUT 은 하지 않는다**. 레이아웃 [저장] 시 `flushPending` 이
 * 버퍼를 전 키 PUT 으로 영속한다 — node.text(레이아웃 PUT)와 키 값(custom-translations PUT)이
 * **같은 저장 동작에서 함께** 반영되어 desync 가 원천 불가능하다.
 *
 * 버퍼는 키별 **로케일별 값 맵**이다(부분 갱신 — 편집한 로케일만 덮어쓰고 나머지는 서버 값 유지).
 * 모듈 전역(에디터 세션 단위) — 한 번에 하나의 레이아웃 편집 세션만 활성이며, flush/clear 로 비운다.
 *
 * @since engine-v1.50.0
 */

import { buildAuthHeaders } from '../utils/authToken';
import { TranslationEngine } from '../../TranslationEngine';
import { findCustomKeyRow, bustTranslationCache, EDITOR_TRANSLATIONS_REFRESHED_EVENT } from './useInlineEdit';

/** 키별 로케일 값 버퍼 — `key → { locale → value }`. 편집한 로케일만 보유(부분). */
const buffer = new Map<string, Record<string, string>>();

/**
 * 키의 특정 로케일 값을 버퍼에 기록(저장 보류) + 캔버스 라이브 반영(낙관적 seed).
 *
 * @param templateIdentifier 템플릿 식별자(엔진 seed 용)
 * @param key 커스텀 키(`custom.*`)
 * @param locale 로케일
 * @param value 새 키 값(자리표시 문장)
 */
export function setPendingValue(
  templateIdentifier: string,
  key: string,
  locale: string,
  value: string,
): void {
  const cur = buffer.get(key) ?? {};
  cur[locale] = value;
  buffer.set(key, cur);
  // 캔버스 라이브 — 엔진 사전에 즉시 주입 + 재렌더 신호(서버 PUT 없이 미리보기 일치).
  try {
    TranslationEngine.getInstance().setTranslationValue(templateIdentifier, locale, key, value);
  } catch {
    /* seed 실패는 무시 — flush/재fetch 가 최종 보정 */
  }
  if (typeof window !== 'undefined') {
    try {
      window.dispatchEvent(
        new CustomEvent(EDITOR_TRANSLATIONS_REFRESHED_EVENT, { detail: { templateIdentifier, locale } }),
      );
    } catch {
      /* 무해 */
    }
  }
}

/**
 * 키의 여러 로케일 값을 한 번에 버퍼에 기록(전 로케일 일괄 변경 — 해제/신규 추가).
 *
 * @param templateIdentifier 템플릿 식별자
 * @param key 커스텀 키
 * @param values 로케일별 값
 */
export function setPendingValues(
  templateIdentifier: string,
  key: string,
  values: Record<string, string>,
): void {
  for (const [locale, value] of Object.entries(values)) {
    setPendingValue(templateIdentifier, key, locale, value);
  }
}

/** 버퍼에 보류 중인 키의 특정 로케일 값(없으면 undefined). */
export function getPendingValue(key: string, locale: string): string | undefined {
  return buffer.get(key)?.[locale];
}

/**
 * 버퍼에 보류 중인 키의 **전 로케일 값 맵**(없으면 undefined).
 *
 * 키화 직후(저장 전)에는 키 값이 pending 버퍼에만 있고 서버 GET 목록에는 아직 나타나지 않을 수
 * 있다(서버 응답 캐시/타이밍). 그 시점에 데이터를 추가하면 `findCustomKeyRow`(서버 GET)가 행을
 * 못 찾아 데이터 추가가 무반응으로 끝난다. 본 함수로 버퍼의 전 로케일 집합을 폴백 베이스로 쓴다.
 *
 * @param key 커스텀 키(`custom.*`)
 * @returns 로케일별 값 맵(편집한 로케일만) 또는 undefined
 */
export function getPendingValues(key: string): Record<string, string> | undefined {
  const v = buffer.get(key);
  return v ? { ...v } : undefined;
}

/** 보류 중인 변경이 있는지(레이아웃 dirty 판정 보조). */
export function hasPending(): boolean {
  return buffer.size > 0;
}

/** 버퍼 비우기(저장 취소/이탈/세션 종료). */
export function clearPending(): void {
  buffer.clear();
}

/**
 * 버퍼의 전 키·전 로케일 값을 주어진 TranslationEngine 에 다시 seed 한다.
 *
 * 콘텐츠 언어 전환 시 `useEditorTemplateAssets` 가 **새 TranslationEngine 인스턴스 + loadTranslations**
 * 로 서버 사전을 재로드한다. 이때 저장-지연 버퍼(pending)로 seed 했던 키 값이 새 엔진/새 fetch 로
 * 덮어써져, 레이아웃 [저장] 전에 다른 로케일로 전환하면 새 키 값(`{pN}` 포함)이 캔버스에 반영되지
 * 않고 서버 stale 값으로 렌더된다.
 * 사전 (재)로드 직후 본 함수로 버퍼 전체를 다시 주입해, 저장 전에도 전 로케일에서 라이브로 반영되게 한다.
 *
 * @param engine 대상 TranslationEngine 인스턴스
 * @param templateIdentifier 템플릿 식별자
 */
export function reseedPendingIntoEngine(
  engine: { setTranslationValue: (t: string, l: string, k: string, v: string) => void },
  templateIdentifier: string,
): void {
  for (const [key, localeValues] of buffer.entries()) {
    for (const [locale, value] of Object.entries(localeValues)) {
      try {
        engine.setTranslationValue(templateIdentifier, locale, key, value);
      } catch {
        /* seed 실패 무시 — 다음 전환/flush 가 보정 */
      }
    }
  }
}

/**
 * 버퍼를 서버에 영속(레이아웃 저장 시 호출) — 키별로 현재 서버 값 위에 보류 로케일 값을 덮어 PUT.
 *
 * 키 행이 없으면(삭제됨) 그 키는 건너뛴다(무손실). 모든 PUT 후 캐시를 무효화해 다음 serveLanguage
 * 가 신선 병합하게 한다. 성공/실패 키 목록을 반환한다(부분 실패도 나머지는 영속 — 저장 흐름 비차단).
 *
 * @param templateIdentifier 템플릿 식별자
 * @returns { ok: 영속된 키 수, failed: 실패 키 목록 }
 */
export async function flushPending(
  templateIdentifier: string,
): Promise<{ ok: number; failed: string[] }> {
  if (buffer.size === 0) return { ok: 0, failed: [] };
  const entries = Array.from(buffer.entries());
  let ok = 0;
  const failed: string[] = [];
  for (const [key, pendingValues] of entries) {
    try {
      const row = await findCustomKeyRow(templateIdentifier, key);
      if (!row) { failed.push(key); continue; }
      // 서버 값 위에 보류 로케일만 덮어쓴다(편집 안 한 로케일 보존).
      const nextValues = { ...(row.values ?? {}), ...pendingValues };
      const url = `/api/admin/templates/${encodeURIComponent(templateIdentifier)}/custom-translations/${row.id}`;
      const res = await fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ values: nextValues, expected_lock_version: row.lock_version }),
      });
      if (!res.ok) { failed.push(key); continue; }
      ok += 1;
    } catch {
      failed.push(key);
    }
  }
  // 성공한 키는 버퍼에서 제거(실패는 다음 저장 재시도 위해 유지).
  for (const [key] of entries) {
    if (!failed.includes(key)) buffer.delete(key);
  }
  try { await bustTranslationCache(templateIdentifier); } catch { /* 무해 */ }
  return { ok, failed };
}
