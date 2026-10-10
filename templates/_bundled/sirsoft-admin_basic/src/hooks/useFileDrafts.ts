import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/** 한 파일의 저장되지 않은 초안. */
export interface FileDraft {
  /** 편집 중이던 본문 */
  value: string;
  /** 마지막으로 적어 둔 시각 (epoch ms) */
  savedAt: number;
  /** 초안을 뜨기 시작한 시점의 서버 판본 — 복원할 때 그 사이 저장이 있었는지 가른다 */
  baseRevision: number;
}

/** 키 → 초안. */
export type FileDraftMap = Record<string, FileDraft>;

export interface UseFileDraftsOptions {
  /** localStorage 키 접두 (템플릿별로 갈린다) */
  namespace: string;
  /** 쓰기 debounce (ms) */
  debounceMs?: number;
  /** 전체 초안 크기 상한 (바이트) */
  maxBytes?: number;
}

export interface UseFileDraftsResult {
  /** 화면 진입 시 읽어 둔 초안 (자동 복원하지 않는다 — 사용자가 고르게 한다) */
  pendingDrafts: FileDraftMap;
  /** 초안 1건을 적어 둔다 (debounce) */
  rememberDraft: (key: string, value: string, baseRevision: number) => void;
  /** 초안 1건을 지운다 (저장 성공·사용자가 버림) */
  forgetDraft: (key: string) => void;
  /** 초안 전체를 지운다 */
  forgetAllDrafts: () => void;
  /** 복원 배너를 닫는다 (초안은 남긴다 — 다음 진입에서 다시 물을 수 있게) */
  dismissPending: () => void;
}

/** 기본 쓰기 debounce — 타이핑마다 직렬화하면 큰 파일에서 입력이 끊긴다 */
const DEFAULT_DEBOUNCE_MS = 2000;

/** 기본 전체 상한 — localStorage 는 출처당 5~10MB 라 그 절반 아래로 잡는다 */
const DEFAULT_MAX_BYTES = 2 * 1024 * 1024;

/**
 * 초안 수명 — 이보다 오래된 것은 읽지도, 다시 쓰지도 않는다 (결정 2026-09-12).
 *
 * 수명이 없으면 초안은 영원히 남는다. 실측 2026-09-12: 전날 19:21 에 남은 초안 3건이
 * 11시간 뒤에도 «저장하지 않은 편집 N건이 있습니다» 배너에 그대로 올라왔다. 그 배너는
 * 볼 때마다 "내가 방금 뭘 안 저장했나" 를 묻게 만들고, 쌓일수록 진짜 최근 편집분이
 * 그 안에 묻힌다.
 */
const DEFAULT_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

/**
 * 지금 로그인한 관리자의 uuid 를 구합니다 (주문 목록 「컬럼 선택」과 같은 방식).
 *
 * @returns 회원 uuid, 모르면 null
 */
function currentMemberUuid(): string | null {
  try {
    const uuid = (window as any).G7Core?.AuthManager?.getInstance?.()?.getUser?.()?.uuid;
    return typeof uuid === 'string' && uuid !== '' ? uuid : null;
  } catch {
    return null;
  }
}

/**
 * localStorage 를 안전하게 읽습니다.
 *
 * 사생활 보호 창·차단된 사이트 데이터에서는 접근 자체가 예외를 던진다. 초안이 없는 것은
 * 정상 상태이므로 여기서 화면을 세우지 않는다.
 *
 * @param storageKey 저장 키
 * @returns 읽은 초안 맵 (실패·부재 시 빈 객체)
 */
function readDrafts(storageKey: string | null, maxAgeMs: number = DEFAULT_MAX_AGE_MS): FileDraftMap {
  if (storageKey === null) {
    return {};
  }

  try {
    const raw = window.localStorage.getItem(storageKey);

    if (!raw) {
      return {};
    }

    const parsed = JSON.parse(raw) as unknown;

    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return {};
    }

    const result: FileDraftMap = {};

    for (const [key, entry] of Object.entries(parsed as Record<string, unknown>)) {
      if (
        entry &&
        typeof entry === 'object' &&
        typeof (entry as FileDraft).value === 'string'
      ) {
        const draft = entry as FileDraft;
        const savedAt = Number(draft.savedAt) || 0;

        // 수명이 지난 초안은 없는 것으로 친다. 여기서 거르면 배너·복원·재저장 어디에도
        // 실리지 않으므로, 다음 `flush()` 가 저장소에서도 함께 걷어낸다.
        if (maxAgeMs > 0 && savedAt > 0 && Date.now() - savedAt > maxAgeMs) {
          continue;
        }

        result[key] = {
          value: draft.value,
          savedAt,
          baseRevision: Number(draft.baseRevision) || 0,
        };
      }
    }

    return result;
  } catch {
    return {};
  }
}

/**
 * 상한을 넘으면 오래된 초안부터 버립니다.
 *
 * 넘친 채로 쓰면 `QuotaExceededError` 가 나 **그 뒤의 모든 초안 쓰기가 조용히 실패한다** —
 * 가장 최근 편집분이 저장되지 않는 쪽이 오래된 초안이 사라지는 쪽보다 나쁘다.
 *
 * @param drafts 초안 맵
 * @param maxBytes 상한
 * @returns 상한 안으로 줄인 초안 맵
 */
function trimToLimit(drafts: FileDraftMap, maxBytes: number): FileDraftMap {
  let serialized = JSON.stringify(drafts);

  if (serialized.length <= maxBytes) {
    return drafts;
  }

  const byAge = Object.entries(drafts).sort((a, b) => a[1].savedAt - b[1].savedAt);
  const trimmed: FileDraftMap = { ...drafts };

  for (const [key] of byAge) {
    delete trimmed[key];
    serialized = JSON.stringify(trimmed);

    if (serialized.length <= maxBytes) {
      break;
    }
  }

  return trimmed;
}

/**
 * 미저장 편집분을 브라우저에 적어 두고 되살립니다 (결정 D-4).
 *
 * ## 자동 복원하지 않는 이유
 *
 * 진입 시 읽은 초안은 {@link UseFileDraftsResult.pendingDrafts} 로 **돌려주기만** 한다.
 * 자동으로 편집기에 밀어 넣으면, 어제 다른 사람이 남긴 초안이 오늘 내 화면에서 서버 본문을
 * 덮어쓴 것처럼 보이고, 그 상태로 저장하면 남의 미완성 편집이 실제로 배포된다.
 * 무엇이 언제 것인지 보여 주고 사용자가 [복원]/[버리기] 를 고르게 한다.
 *
 * @param options 네임스페이스·debounce·상한
 * @returns 초안 읽기·쓰기·지우기 도구
 */
export function useFileDrafts({
  namespace,
  debounceMs = DEFAULT_DEBOUNCE_MS,
  maxBytes = DEFAULT_MAX_BYTES,
}: UseFileDraftsOptions): UseFileDraftsResult {
  // 초안은 작성물이라 관리자 회원마다 갈린다(#639 ⑩) — 같은 브라우저를 쓰는 다른 관리자에게
  // 「저장하지 않은 편집」이 뜨면 안 된다. `namespace` 는 글꼴 크기 같은 기기 선호 키의 접두이기도 하므로
  // 건드리지 않고 uuid 는 이 훅 안에서만 넣는다. 회원을 모르면 초안을 남기지 않는다(null) — 공용 키에 다시 쓰면
  // 같은 결함이 돌아온다.
  const memberUuid = useMemo(() => currentMemberUuid(), []);
  const storageKey = useMemo(() => (memberUuid ? `${namespace}.${memberUuid}.drafts` : null), [namespace, memberUuid]);

  // 진입 시점의 스냅샷 — 이후 내가 쓰는 초안이 여기 섞이면 "복원할까요" 배너가
  // 방금 내가 친 글자를 두고 다시 뜬다. 그래서 최초 1회만 읽고 갱신하지 않는다.
  const [pendingDrafts, setPendingDrafts] = useState<FileDraftMap>(() => {
    if (typeof window === 'undefined') {
      return {};
    }

    return readDrafts(storageKey);
  });

  /**
   * **이 문서가 적은** 초안만 담는다.
   *
   * 병합으로 본 남의 초안 사본까지 여기 두면, 다른 탭이 그 초안을 지운 뒤(저장 성공·버리기) 이 탭의
   * 다음 쓰기가 사본을 도로 적어 되살린다(실측 2026-10-06: 탭2 에서 버린 `_admin_base` 초안이 탭1 의
   * 새로고침 직전 쓰기로 되살아나 배너에 다시 떴다). 남의 초안은 매 쓰기마다 저장소에서 새로 읽는다.
   */
  const draftsRef = useRef<FileDraftMap>({});
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }

    // 진입 시점의 저장소 내용은 `pendingDrafts` 가 보여 줄 뿐 이 문서가 적은 것이 아니다.
    draftsRef.current = {};
  }, [storageKey]);

  // 회원 구분 없이 남은 옛 키는 누구 것인지 가를 수 없으므로 읽지 않고 지운다.
  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }

    try {
      window.localStorage.removeItem(`${namespace}.drafts`);
    } catch {
      // 지우지 못해도 읽지 않으므로 다른 관리자에게 보이지 않는다.
    }
  }, [namespace]);

  /**
   * 아직 브라우저에 실리지 못한 쓰기가 남았는지.
   *
   * 동의 게이트가 낀 환경에서는 `setItem` 이 **예외 없이 조용히** 버려지므로, 썼다고 믿으면
   * 초안이 한 번도 남지 않는데 화면은 "복원할 수 있다" 고 약속하게 된다. 되읽어 확인한다.
   */
  const pendingWriteRef = useRef(false);

  /**
   * 이 문서가 **의도적으로 지운** 키와 그 시각.
   *
   * 아래 `flush()` 는 저장소를 다시 읽어 병합하므로, 지운 키를 기억해 두지 않으면 병합이
   * 그것을 도로 살려 낸다(저장에 성공해 지운 초안이 다음 진입에서 다시 «복원할까요» 로
   * 뜬다). 삭제는 병합보다 세다 — 다만 **지운 시점까지 적힌 것에만** 세다. 지운 뒤 다른 탭이
   * 같은 파일을 고쳐 새로 적은 초안까지 지우면 그 탭의 편집분이 말없이 사라진다.
   */
  const removedRef = useRef<Map<string, number>>(new Map());

  /**
   * 초안을 저장소에 적습니다.
   *
   * **통째로 덮지 않고 병합한다.** 종전에는 메모리의 맵을 그대로 `setItem` 해 저장소를
   * 갈아치웠다. 편집기를 두 탭에 띄우면 한쪽의 `flush()` 가 다른 쪽이 적어 둔 초안을
   * 말없이 지웠고(실측 2026-09-12: 한 탭에서 지운 초안이 다른 탭의 언마운트 flush 로
   * 되살아나고, 주입한 초안은 반대로 사라졌다), 그 소실은 사용자가 알아챌 방법이 없었다.
   *
   * 병합 규칙은 «더 나중에 적힌 쪽이 이긴다» 다 — 같은 키는 저장 시각(`savedAt`)이 늦은 쪽을 남기고,
   * 내가 지운 키는 지운 시각보다 늦게 적힌 초안이 없을 때만 덜어낸다. 다른 탭이 적은 초안은 함께 살아남는다.
   */
  const flush = useCallback(() => {
    if (typeof window === 'undefined' || storageKey === null) {
      return;
    }

    try {
      const stored = readDrafts(storageKey);
      const merged: FileDraftMap = { ...stored };

      // 같은 키는 **더 나중에 적힌 쪽**이 이긴다. 내 맵에는 진입 때 읽어 둔 남의 초안 사본도 섞여
      // 있어서, «내 맵이 이긴다» 로 덮으면 그 뒤 다른 탭이 적은 새 초안이 옛 사본으로 되돌아간다
      // (실측 2026-10-03).
      for (const [key, mine] of Object.entries(draftsRef.current)) {
        const theirs = stored[key];
        merged[key] = theirs && theirs.savedAt > mine.savedAt ? theirs : mine;
      }

      removedRef.current.forEach((removedAt, key) => {
        if (merged[key] && merged[key].savedAt > removedAt) {
          return;
        }

        delete merged[key];
      });

      const trimmed = trimToLimit(merged, maxBytes);
      const own: FileDraftMap = {};
      for (const key of Object.keys(draftsRef.current)) {
        if (trimmed[key]) {
          own[key] = trimmed[key];
        }
      }
      draftsRef.current = own;

      const payload = JSON.stringify(trimmed);
      window.localStorage.setItem(storageKey, payload);
      pendingWriteRef.current = window.localStorage.getItem(storageKey) !== payload;
    } catch {
      // 저장에 실패해도 편집은 계속돼야 한다 — 초안은 편의 장치이지 정본이 아니다.
      pendingWriteRef.current = true;
    }
  }, [storageKey, maxBytes]);

  const rememberDraft = useCallback(
    (key: string, value: string, baseRevision: number) => {
      // 다시 쓰는 키는 «지움» 표시를 거둔다 — 지웠다가 같은 파일을 또 고치는 흐름이다.
      removedRef.current.delete(key);

      draftsRef.current = {
        ...draftsRef.current,
        [key]: { value, savedAt: Date.now(), baseRevision },
      };

      if (timerRef.current) {
        clearTimeout(timerRef.current);
      }

      timerRef.current = setTimeout(flush, debounceMs);
    },
    [flush, debounceMs]
  );

  const forgetDraft = useCallback(
    (key: string) => {
      removedRef.current.set(key, Date.now());

      const next = { ...draftsRef.current };
      delete next[key];
      draftsRef.current = next;

      setPendingDrafts((current) => {
        if (!(key in current)) {
          return current;
        }

        const nextPending = { ...current };
        delete nextPending[key];

        return nextPending;
      });

      flush();
    },
    [flush]
  );

  const forgetAllDrafts = useCallback(() => {
    // 저장소에만 있고 내 맵에는 없던 키(다른 탭이 적은 것)까지 확실히 지운다 — [버리기] 는
    // "지금 배너에 보이는 것" 이 아니라 이 네임스페이스 전체를 버리겠다는 뜻이다.
    const removedAt = Date.now();
    Object.keys(readDrafts(storageKey)).forEach((key) => removedRef.current.set(key, removedAt));
    Object.keys(draftsRef.current).forEach((key) => removedRef.current.set(key, removedAt));

    draftsRef.current = {};
    setPendingDrafts({});

    if (typeof window === 'undefined' || storageKey === null) {
      return;
    }

    try {
      window.localStorage.removeItem(storageKey);
    } catch {
      // 위와 같은 이유 — 지우지 못해도 화면을 세우지 않는다.
    }
  }, [storageKey]);

  const dismissPending = useCallback(() => setPendingDrafts({}), []);

  // 떠날 때 debounce 에 걸려 있던 마지막 편집분을 흘려보낸다. 이게 없으면 "2초 안에 탭을
  // 닫으면 마지막 몇 글자가 사라지는" 재현 어려운 결함이 남는다.
  useEffect(
    () => () => {
      if (timerRef.current) {
        clearTimeout(timerRef.current);
        flush();
      }
    },
    [flush]
  );

  /**
   * 탭을 닫거나 화면을 감출 때 **그 이벤트 안에서** 초안을 적는다.
   *
   * 위의 언마운트 정리만으로는 부족하다 — 탭을 닫을 때 React 는 언마운트하지 않으므로
   * 바로 위 주석이 말하는 "2초 안에 닫으면 마지막 몇 글자가 사라진다" 가 실제로는 남아 있었다.
   *
   * 동의 게이트(GDPR 플러그인)가 낀 환경은 이것으로 해결되지 **않는다**. 그쪽은
   * `Storage.prototype.setItem` 을 가로채 strictly-necessary 허용목록 · functional 동의 ·
   * user-initiated 면제(WP29 §3.6, 마지막 인터랙션 후 500ms) 중 하나에 걸릴 때만 통과시키는데,
   * 실측 2026-09-12 로 `pagehide`·`beforeunload`·`visibilitychange` 핸들러 안의 쓰기가 모두
   * 버려졌다 — 떠나는 이벤트에는 `window.event` 가 실리지 않고 마지막 인터랙션도 이미 500ms 를
   * 넘긴다. 그 환경은 아래 **인터랙션 재시도** 가 맡는다.
   */
  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }

    const flushNow = (): void => {
      if (timerRef.current) {
        clearTimeout(timerRef.current);
        timerRef.current = null;
      }

      flush();
    };

    const onHide = (): void => {
      // 탭 전환·모바일 백그라운드 진입은 그대로 돌아오는 경우가 많지만, 돌아오지 못하는
      // 경우(앱 종료)와 구분할 방법이 없으므로 감춰질 때 한 번 적어 둔다.
      if (document.visibilityState === 'hidden') {
        flushNow();
      }
    };

    window.addEventListener('pagehide', flushNow);
    window.addEventListener('beforeunload', flushNow);
    document.addEventListener('visibilitychange', onHide);

    return () => {
      window.removeEventListener('pagehide', flushNow);
      window.removeEventListener('beforeunload', flushNow);
      document.removeEventListener('visibilitychange', onHide);
    };
  }, [flush]);

  /**
   * 버려진 쓰기를 **다음 사용자 입력 안에서** 다시 시도한다.
   *
   * 동의 게이트는 "마지막 인터랙션 직후" 의 쓰기만 통과시키는데, 초안 쓰기는 debounce 때문에
   * 늘 그 창 밖에서 일어난다. 되읽기로 버려진 것을 알았으면 다음 신뢰된 입력이 올 때
   * 그 핸들러 **안에서** 다시 쓴다 — 타이핑 중이라면 다음 키 입력이 곧바로 오므로 사실상
   * 즉시 실린다. 게이트가 없는 환경에서는 `pendingWriteRef` 가 늘 false 라 아무 일도 않는다.
   *
   * capture · passive 로 단다 — 편집기가 이벤트를 삼켜도 우리 쪽은 먼저 보고, 스크롤·입력
   * 성능에 끼어들지 않는다.
   *
   * `isTrusted` 를 따로 가리지 않는다. 게이트가 요구하는 것이 신뢰된 이벤트라면 합성 이벤트로는
   * 어차피 통과하지 못하고 `pendingWriteRef` 가 true 로 남아 다음 진짜 입력에서 다시 시도한다 —
   * 스스로 교정되므로, 가려 봐야 쓰기 한 번을 아끼는 대신 검사할 수 없는 갈래만 늘어난다.
   */
  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }

    const retry = (): void => {
      if (!pendingWriteRef.current) {
        return;
      }

      flush();
    };

    const types = ['keydown', 'pointerdown', 'click', 'wheel', 'touchstart'];
    types.forEach((type) => window.addEventListener(type, retry, { capture: true, passive: true }));

    return () => {
      types.forEach((type) => window.removeEventListener(type, retry, { capture: true }));
    };
  }, [flush]);

  return { pendingDrafts, rememberDraft, forgetDraft, forgetAllDrafts, dismissPending };
}
