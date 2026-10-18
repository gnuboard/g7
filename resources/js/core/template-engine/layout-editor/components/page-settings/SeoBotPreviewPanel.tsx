/**
 * SeoBotPreviewPanel.tsx — 봇 HTML 실시간 미리보기 패널
 *
 * [검색엔진] 탭 맨 하단 접이식 섹션(**기본 펼침**). 검색 봇이 보는 완성 HTML 을
 * 코드/렌더 토글로 표시한다. ①~④ 설정을 바꾸면 **디바운스 후 미리보기 재호출**(실시간 갱신).
 * dirty(저장 전) 설정 + 샘플 데이터 기준이며 SEO 캐시를 우회한다(엔드포인트).
 *
 *  - POST `/api/admin/templates/{id}/editor/seo-bot-preview` (Bearer)
 *    body { layout, url, locale, seed_context, route_params } → { data:{ identifier, enabled, html } }
 *  - enabled=false 또는 toggle off → html=null → "이 화면은 검색엔진에 노출 안 됨" 안내.
 *  - `settingsSignature` 가 바뀌면 디바운스(기본 400ms) 후 재호출(과다 호출 방지). 수동 새로고침 즉시.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(라이브러리 중립).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useRef, useState } from 'react';
import { buildAuthHeaders } from '../../utils/authToken';

/** 봇 미리보기 응답(서버 data) */
export interface SeoBotPreviewResponse {
  identifier?: string;
  enabled: boolean;
  html: string | null;
}

export interface SeoBotPreviewPanelProps {
  /** 편집 대상 템플릿 식별자 */
  templateIdentifier: string;
  /** 현재 편집 중 레이아웃(저장 전 dirty 포함) — body.layout */
  layout: Record<string, unknown>;
  /** 미리보기 URL(샘플 라우트) */
  url: string;
  /** 로케일 */
  locale: string;
  /** 편집기 샘플 데이터 컨텍스트(seedContext — 실 API fetch 없이) */
  seedContext?: Record<string, unknown>;
  /** 샘플 route params */
  routeParams?: Record<string, unknown>;
  /**
   * 설정 시그니처 — ①~④ 입력(enabled/page_type/extensions/og/structured/vars/toggle)을
   * 직렬화한 문자열. 바뀌면 디바운스 후 재호출(과다 호출 방지).
   */
  settingsSignature: string;
  /** 다국어 해석 t */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 디바운스 ms(기본 400). 테스트에서 0 주입 가능 */
  debounceMs?: number;
  /**
   * fetch 주입(테스트). 미전달 시 전역 fetch. 가드 엔드포인트라 Bearer 헤더는 내부에서 첨부.
   */
  fetchImpl?: typeof fetch;
  /** data-testid 접두 */
  testidPrefix?: string;
}

/**
 * 봇 HTML 실시간 미리보기 패널.
 *
 * @param props SeoBotPreviewPanelProps
 * @return 봇 미리보기 엘리먼트
 */
export function SeoBotPreviewPanel({
  templateIdentifier,
  layout,
  url,
  locale,
  seedContext,
  routeParams,
  settingsSignature,
  t,
  debounceMs = 400,
  fetchImpl,
  testidPrefix = 'g7le-seo-bot-preview',
}: SeoBotPreviewPanelProps): React.ReactElement {
  // 기본 펼침.
  const [expanded, setExpanded] = useState(true);
  const [result, setResult] = useState<SeoBotPreviewResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  // 항상 최신 입력을 디바운스 콜백이 읽도록 ref 로 보관(stale closure 회피).
  const inputRef = useRef({ templateIdentifier, layout, url, locale, seedContext, routeParams });
  inputRef.current = { templateIdentifier, layout, url, locale, seedContext, routeParams };

  const runFetch = useCallback(async (): Promise<void> => {
    const f = fetchImpl ?? (typeof fetch !== 'undefined' ? fetch : undefined);
    if (!f) return;
    const { templateIdentifier: id, layout: lo, url: u, locale: lc, seedContext: sc, routeParams: rp } = inputRef.current;
    setLoading(true);
    setError(null);
    try {
      const endpoint = `/api/admin/templates/${encodeURIComponent(id)}/editor/seo-bot-preview`;
      const res = await f(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ layout: lo, url: u, locale: lc, seed_context: sc ?? {}, route_params: rp ?? {} }),
      });
      const body = await res.json().catch(() => null);
      if (!res.ok) {
        setError((body as { message?: string })?.message ?? `HTTP ${res.status}`);
        return;
      }
      const data = (body as { data?: SeoBotPreviewResponse })?.data ?? null;
      setResult(data);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setLoading(false);
    }
  }, [fetchImpl]);

  // settingsSignature 변경 → 디바운스 재호출. 펼침 상태에서만(접힘 시 호출 안 함).
  useEffect(() => {
    if (!expanded) return;
    if (timerRef.current) clearTimeout(timerRef.current);
    timerRef.current = setTimeout(() => {
      void runFetch();
    }, debounceMs);
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, [settingsSignature, expanded, debounceMs, runFetch]);

  const refresh = useCallback((): void => {
    if (timerRef.current) clearTimeout(timerRef.current);
    void runFetch();
  }, [runFetch]);

  const html = result?.html ?? null;
  const enabled = result?.enabled ?? true;

  return (
    <div data-testid={testidPrefix} style={wrap}>
      <div style={header}>
        <button
          type="button"
          data-testid={`${testidPrefix}-toggle`}
          onClick={() => setExpanded((v) => !v)}
          aria-expanded={expanded}
          style={titleBtn}
        >
          {expanded ? '▾' : '▸'} {t('layout_editor.page_settings.seo.bot_preview_title')}
        </button>
        {expanded ? (
          <button type="button" data-testid={`${testidPrefix}-refresh`} onClick={refresh} disabled={loading} style={refreshBtn}>
            ⟳ {t('layout_editor.page_settings.seo.bot_preview_refresh')}
          </button>
        ) : null}
      </div>

      {expanded && (
        <div style={{ marginTop: 8 }}>
          {/* 노출 안 됨 — render null 과 일치 */}
          {result && !enabled ? (
            <p data-testid={`${testidPrefix}-disabled`} style={disabledNote}>
              ⓘ {t('layout_editor.page_settings.seo.bot_preview_disabled')}
            </p>
          ) : (
            // HTML 코드 미리보기만 — 렌더(iframe) 토글은 제거(HTML 미리보기가 요구의 전부).
            <div data-testid={`${testidPrefix}-body`} style={bodyBox}>
              {loading && !html ? (
                <p style={mutedNote}>{t('layout_editor.translation.loading')}</p>
              ) : error ? (
                <p data-testid={`${testidPrefix}-error`} style={{ ...mutedNote, color: '#dc2626' }}>{error}</p>
              ) : html == null ? (
                <p style={mutedNote}>{t('layout_editor.page_settings.seo.bot_preview_empty')}</p>
              ) : (
                <pre data-testid={`${testidPrefix}-code`} style={codePre}>{html}</pre>
              )}
            </div>
          )}

          <p data-testid={`${testidPrefix}-sample-note`} style={sampleNote}>
            ⓘ {t('layout_editor.page_settings.seo.bot_preview_sample_note')}
          </p>
        </div>
      )}
    </div>
  );
}

const wrap: React.CSSProperties = { borderTop: '1px solid #e2e8f0', paddingTop: 10, marginTop: 12 };
const header: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8 };
const titleBtn: React.CSSProperties = { background: 'none', border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 600, color: '#0f172a', padding: 0 };
const refreshBtn: React.CSSProperties = { marginLeft: 'auto', padding: '4px 10px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const disabledNote: React.CSSProperties = { margin: 0, fontSize: 12, color: '#64748b', background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 6, padding: '8px 10px' };
const bodyBox: React.CSSProperties = { border: '1px solid #e2e8f0', borderRadius: 8, background: '#0b1020', padding: 0, minHeight: 80, overflow: 'hidden' };
// 코드 미리보기 — 산출물 그대로 표시. 긴 줄은 단어 경계에서 줄바꿈(break-word)하되 글자(멀티바이트)
// 중간은 자르지 않는다. break-all 은 한글 글자 중간을 갈라(티셔츠→티/츠) 깨뜨리므로 쓰지 않는다.
const codePre: React.CSSProperties = { margin: 0, padding: 12, fontSize: 11, lineHeight: 1.5, color: '#d1fae5', fontFamily: 'monospace', whiteSpace: 'pre-wrap', wordBreak: 'break-word', maxHeight: 360, overflow: 'auto' };
const mutedNote: React.CSSProperties = { margin: 0, padding: 12, fontSize: 12, color: '#94a3b8' };
const sampleNote: React.CSSProperties = { margin: '8px 0 0', fontSize: 11, color: '#94a3b8' };
