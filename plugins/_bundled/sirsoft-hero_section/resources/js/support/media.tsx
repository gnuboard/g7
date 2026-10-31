import React, { useEffect, useRef, useState, useSyncExternalStore } from 'react';

/** 공개 API 의 영상 파일 하나 */
export interface HeroVideo {
  /** 공개 영상 주소 (범위 요청을 받는다) */
  url: string;

  /** MIME (video/mp4·video/webm) */
  mime?: string | null;
}

/** 슬라이드 미디어가 슬라이더에 알리는 상태 — 재생이 시작됐는지, 끝났는지, 재생할 수 없는지 */
export interface MediaCallbacks {
  /** 끝까지 재생했다 */
  onEnded: () => void;

  /** 재생할 수 없다(파일 오류·유튜브 응답 없음) — 슬라이더는 이 장을 이미지처럼 간격으로 넘긴다 */
  onFailed: () => void;
}

/** 유튜브 iframe 이 메시지를 보내는 출처 — 주소를 이 호스트로만 만들므로 이 출처의 메시지만 받는다 */
const YOUTUBE_ORIGIN = 'https://www.youtube-nocookie.com';

/** 재생 명령 뒤 「재생 중」 신호를 기다리는 시간 — 넘으면 재생할 수 없는 것으로 보고 간격 넘김으로 돌린다 */
const YOUTUBE_START_TIMEOUT_MS = 10_000;

/** 휴대폰 화면 판정 — 이미지 `<source media>` 와 같은 경계 */
const NARROW_QUERY = '(max-width: 767px)';

interface ConsentSnapshotLike {
  status?: string;
  categories?: Record<string, boolean>;
}

interface ConsentFacade {
  getSnapshot?: () => ConsentSnapshotLike | null;
  subscribe?: (listener: (snapshot: unknown) => void) => () => void;
}

/**
 * 코어 동의 파사드(`G7Core.consent`)를 꺼냅니다.
 *
 * @returns 동의 파사드 (없으면 null — 관리자 화면 등)
 */
function consentFacade(): ConsentFacade | null {
  return (window as { G7Core?: { consent?: ConsentFacade } }).G7Core?.consent ?? null;
}

/**
 * 유튜브를 동의 없이 불러와도 되는지 판정합니다 — 동의 관리 제공자가 없는 사이트(none)이거나 방문자가 마케팅에 동의했으면 true.
 * 모르는 상태(부팅 중·재조회 중)와 동의 런타임이 없는 화면은 false 다(대표 이미지와 재생 단추).
 *
 * @param snapshot 동의 스냅샷
 * @returns 불러와도 되면 true
 */
export function marketingAllowed(snapshot: ConsentSnapshotLike | null | undefined): boolean {
  if (!snapshot) {
    return false;
  }

  return snapshot.status === 'none' || (snapshot.status === 'ready' && snapshot.categories?.marketing === true);
}

/**
 * 방문자의 마케팅 동의를 구독합니다 — 동의 배너에서 동의하면 바로 true 로 바뀐다.
 *
 * @returns 유튜브를 불러와도 되면 true
 */
export function useMarketingConsent(): boolean {
  return useSyncExternalStore(
    (notify) => consentFacade()?.subscribe?.(() => notify()) ?? (() => {}),
    () => marketingAllowed(consentFacade()?.getSnapshot?.()),
    () => false,
  );
}

/**
 * 휴대폰 폭인지 구독합니다 — 화면을 돌리거나 창 폭을 바꾸면 영상 파일도 그 기기 것으로 바꾼다.
 *
 * @returns 휴대폰 폭이면 true
 */
export function useNarrowScreen(): boolean {
  return useSyncExternalStore(
    (notify) => {
      if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return () => {};
      }
      const query = window.matchMedia(NARROW_QUERY);
      query.addEventListener?.('change', notify);
      return () => query.removeEventListener?.('change', notify);
    },
    () => typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(NARROW_QUERY).matches,
    () => false,
  );
}

/**
 * 유튜브 영상 ID 모양인지 확인합니다 — 서버가 검증해 둔 값이지만 iframe 주소에 넣기 전에 다시 본다.
 *
 * @param id 영상 ID
 * @returns 11자 영상 ID 면 true
 */
export function isYoutubeId(id: unknown): id is string {
  return typeof id === 'string' && /^[A-Za-z0-9_-]{11}$/.test(id);
}

/**
 * 유튜브 주소(운영자 입력)에서 영상 ID 를 꺼냅니다 — 관리자 미리보기용. 서버 `YoutubeUrl::videoId()` 와 같은 모양만 받는다.
 *
 * @param input 주소 또는 영상 ID
 * @returns 영상 ID (알아볼 수 없으면 null)
 */
export function youtubeIdFromUrl(input: string | null | undefined): string | null {
  const value = (input ?? '').trim();
  if (isYoutubeId(value)) {
    return value;
  }
  let url: URL;
  try {
    url = new URL(/^https?:\/\//i.test(value) ? value : `https://${value}`);
  } catch {
    return null;
  }
  const host = url.hostname.toLowerCase().replace(/^(www|m)\./, '');
  if (!['youtube.com', 'youtu.be', 'youtube-nocookie.com', 'music.youtube.com'].includes(host)) {
    return null;
  }
  const segments = url.pathname.split('/').filter(Boolean);
  let candidate: string | null | undefined = null;
  if (host === 'youtu.be') {
    candidate = segments[0];
  } else if (['embed', 'shorts', 'live', 'v'].includes(segments[0] ?? '')) {
    candidate = segments[1];
  } else if (segments[0] === 'watch') {
    candidate = url.searchParams.get('v');
  }
  return isYoutubeId(candidate) ? candidate : null;
}

interface HeroVideoMediaProps extends MediaCallbacks {
  /** PC·모바일 영상 */
  sources: { pc?: HeroVideo | null; mobile?: HeroVideo | null };

  /** 미리보기 기기 고정 */
  forceDevice?: 'pc' | 'mobile';

  /** 대표 이미지 주소 — 첫 프레임을 받기 전에 보인다 */
  poster?: string;

  /** 지금 재생할지 (활성 장이고 일시정지가 아님) */
  playing: boolean;

  /** 활성 장인지 — 일시정지는 그 자리에 멈추고, 활성 장이 아니게 되면 처음으로 돌린다 */
  active: boolean;

  /** 배너가 한 장뿐이면 반복 재생 */
  loop: boolean;

  /** 먼저 받아 둘지 (첫 장·다음 장) */
  eager: boolean;
}

/**
 * 직접 올린 영상 — 소리 없이·화면 안에서(playsInline) 재생한다. 일시정지는 그 자리에 멈추고, 활성 장이 아니면 멈추고 처음으로 돌린다.
 * 영상은 장식이라 화면 읽기에서 감추고(대체 텍스트는 아래 대표 이미지가 맡는다), 끝나면 슬라이더에 알린다.
 *
 * @param props 영상 속성
 * @returns 영상 요소
 */
export const HeroVideoMedia: React.FC<HeroVideoMediaProps> = ({ sources, forceDevice, poster, playing, active, loop, eager, onEnded, onFailed }) => {
  const ref = useRef<HTMLVideoElement>(null);
  const narrow = useNarrowScreen();
  const mobile = forceDevice ? forceDevice === 'mobile' : narrow;
  const source = (mobile ? sources.mobile ?? sources.pc : sources.pc) ?? null;

  useEffect(() => {
    const video = ref.current;
    if (!video || !source) {
      return;
    }
    if (playing) {
      // 자동 재생이 막히면(절전 모드 등) 대표 이미지·첫 프레임이 남고 간격 넘김으로 돌린다. 시작 전에 멈추거나 넘기거나 화면을 돌려
      // 재생 요청이 취소된 것(AbortError)은 실패가 아니다 — 실패로 보면 그 배너는 방문 내내 다시 재생되지 않는다
      const started = video.play();
      if (started && typeof started.catch === 'function') {
        started.catch((error: unknown) => {
          if ((error as { name?: string } | null)?.name !== 'AbortError') {
            onFailed();
          }
        });
      }
    } else {
      video.pause();
      if (!active && video.currentTime > 0 && !video.ended) {
        video.currentTime = 0;
      }
    }
  }, [playing, active, source?.url]);

  if (!source) {
    return null;
  }

  return (
    <video
      ref={ref}
      key={source.url}
      className="hs__video"
      src={source.url}
      poster={poster}
      muted
      playsInline
      loop={loop}
      preload={eager ? 'auto' : 'metadata'}
      aria-hidden="true"
      tabIndex={-1}
      disablePictureInPicture
      onEnded={onEnded}
      onError={onFailed}
    />
  );
};

interface HeroYoutubeMediaProps extends MediaCallbacks {
  /** 유튜브 영상 ID */
  videoId: string;

  /** iframe 이름(배너 제목) */
  title: string;

  /** 지금 재생할지 */
  playing: boolean;

  /** 배너가 한 장뿐이면 반복 재생 */
  loop: boolean;

  /** 불러와도 되는지 (마케팅 동의·동의 관리 없음·방문자가 재생 단추를 누름) */
  allowed: boolean;

  /** 재생 단추를 눌렀다 — 이 배너만 불러오도록 허락 */
  onAllow: () => void;

  /** 재생 단추 이름 */
  playLabel: string;

  /** 재생 단추 아래 안내 */
  notice: string;

  /** 활성 장인지 (재생 단추 탭 순서) */
  active: boolean;
}

/**
 * 유튜브 영상 — 허락 전에는 대표 이미지 위에 재생 단추만 두고(외부 요청 없음), 허락되면 `youtube-nocookie.com` 플레이어를
 * 소리 없이 띄운다. iframe 은 React 가 아니라 이 컴포넌트가 직접 만든다 — 개인정보 플러그인의 차단기가 iframe 을 바꿔 끼워도
 * React 가 모르는 노드라 화면이 깨지지 않는다. 재생·멈춤·끝 감지는 플레이어 메시지(postMessage)로 한다.
 *
 * @param props 유튜브 속성
 * @returns 유튜브 자리
 */
export const HeroYoutubeMedia: React.FC<HeroYoutubeMediaProps> = ({ videoId, title, playing, loop, allowed, onAllow, playLabel, notice, active, onEnded, onFailed }) => {
  const hostRef = useRef<HTMLDivElement>(null);
  const frameRef = useRef<HTMLIFrameElement | null>(null);
  const [ready, setReady] = useState(false);
  const callbacks = useRef({ onEnded, onFailed, playing });
  callbacks.current = { onEnded, onFailed, playing };

  // 허락되면 iframe 을 만들고, 메시지 구독을 붙인다
  useEffect(() => {
    const host = hostRef.current;
    if (!allowed || !host || !isYoutubeId(videoId)) {
      return;
    }
    // 관리자 미리보기는 슬라이더를 iframe 문서에 그린다 — 플레이어 메시지는 그 문서의 창으로 온다
    const view = host.ownerDocument.defaultView ?? window;
    const params = new URLSearchParams({
      autoplay: callbacks.current.playing ? '1' : '0', mute: '1', playsinline: '1', controls: '0', rel: '0', modestbranding: '1', iv_load_policy: '3',
      disablekb: '1', enablejsapi: '1', origin: view.location.origin !== 'null' ? view.location.origin : window.location.origin,
      ...(loop ? { loop: '1', playlist: videoId } : {}),
    });
    const frame = host.ownerDocument.createElement('iframe');
    frame.className = 'hs__youtube-frame';
    frame.src = `${YOUTUBE_ORIGIN}/embed/${videoId}?${params.toString()}`;
    frame.title = title;
    frame.allow = 'autoplay; encrypted-media; picture-in-picture';
    frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    frame.setAttribute('tabindex', '-1');
    frame.setAttribute('aria-hidden', 'true');
    frameRef.current = frame;
    setReady(false);

    const post = (func: string, args: unknown[] = []) => {
      frame.contentWindow?.postMessage(JSON.stringify({ event: 'command', func, args }), YOUTUBE_ORIGIN);
    };
    const onLoad = () => {
      // 플레이어에 상태 알림을 보내 달라고 등록한다(IFrame API 와 같은 첫 인사)
      frame.contentWindow?.postMessage(JSON.stringify({ event: 'listening', id: videoId, channel: 'widget' }), YOUTUBE_ORIGIN);
      post('addEventListener', ['onStateChange']);
      if (callbacks.current.playing) {
        post('playVideo');
      }
    };
    const onMessage = (event: MessageEvent) => {
      if (event.origin !== YOUTUBE_ORIGIN || event.source !== frame.contentWindow || typeof event.data !== 'string') {
        return;
      }
      let data: { event?: string; info?: unknown };
      try {
        data = JSON.parse(event.data);
      } catch {
        return;
      }
      const state = data.event === 'onStateChange'
        ? data.info
        : (data.event === 'infoDelivery' && data.info && typeof data.info === 'object' ? (data.info as { playerState?: unknown }).playerState : undefined);
      if (state === 1) {
        setReady(true);
      } else if (state === 0 && !loop) {
        callbacks.current.onEnded();
      }
    };
    frame.addEventListener('load', onLoad);
    view.addEventListener('message', onMessage);
    host.appendChild(frame);

    return () => {
      frame.removeEventListener('load', onLoad);
      view.removeEventListener('message', onMessage);
      frame.remove();
      frameRef.current = null;
    };
  }, [allowed, videoId, loop]);

  // 재생·멈춤 — 일시정지는 그 자리에 멈추고, 활성 장이 아니게 되면 처음으로 돌려 다음 차례에 처음부터 본다. 재생 신호가 오지 않으면 간격 넘김으로 돌린다
  useEffect(() => {
    const frame = frameRef.current;
    if (!allowed || !frame) {
      return;
    }
    const post = (func: string, args: unknown[] = []) => {
      frame.contentWindow?.postMessage(JSON.stringify({ event: 'command', func, args }), YOUTUBE_ORIGIN);
    };
    if (!playing) {
      post('pauseVideo');
      if (!active) {
        post('seekTo', [0, true]);
      }
      return;
    }
    post('playVideo');
    if (ready) {
      return;
    }
    const timer = setTimeout(() => callbacks.current.onFailed(), YOUTUBE_START_TIMEOUT_MS);
    return () => clearTimeout(timer);
  }, [allowed, playing, active, ready]);

  return (
    <div className={`hs__youtube${ready ? ' hs__youtube--ready' : ''}`}>
      <div ref={hostRef} className="hs__youtube-host" />
      {!allowed && (
        <div className="hs__youtube-gate">
          <button
            type="button"
            className="hs__youtube-play"
            aria-label={playLabel}
            tabIndex={active ? undefined : -1}
            onClick={(event) => {
              event.stopPropagation();
              onAllow();
            }}
          >
            <span aria-hidden="true">▶</span>
          </button>
          <span className="hs__youtube-notice">{notice}</span>
        </div>
      )}
    </div>
  );
};
