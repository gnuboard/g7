/**
 * Swiper 라이브러리 CSS(넘김·전환 효과 뼈대)를 싣는다.
 *
 * 확장 CSS(`dist/css/plugin.css`)는 자기 앞글자(`hs-…`·`hs:…`) 선택자만 담는다 — Swiper 가 요소에 거는 `swiper-*`
 * 클래스 규칙은 라이브러리 몫이라 동봉 자산(`dist/vendor/swiper/{판}/`)으로 따로 싣는다(확장 CSS 자기 공급 규약 ·
 * 외부 라이브러리 절). 싣는 자리(`<head>` 끝)는 그대로 둔다 — 슬라이더 손 CSS 는 Swiper 가 꾸미는 요소(.swiper·.swiper-wrapper·
 * .swiper-slide)의 같은 속성을 건드리지 않아 순서가 결과를 바꾸지 않고, 실린 `<link>` 를 옮기면 브라우저가 시트를 다시 적용하는
 * 사이에 막 생긴 Swiper 가 끌기를 놓친다(E2E 실측).
 */
import { useEffect, useState } from 'react';

/** 동봉한 Swiper 판 — `package.json` 의 swiper 와 같아야 한다(시험이 대조) */
export const SWIPER_VERSION = '14.3.0';

/** 실은 `<link>` 의 id — 미리보기(iframe)가 같은 요소를 복사할 때도 쓴다 */
export const SWIPER_STYLES_ID = 'hs-swiper-css';

const PLUGIN = 'sirsoft-hero_section';
const PATH = `dist/vendor/swiper/${SWIPER_VERSION}/swiper-bundle.min.css`;

type AssetApi = {
  plugin?: (identifier: string, path: string) => string;
  loadStylesheet?: (url: string, attrs?: Record<string, string>, options?: { label?: string }) => Promise<void>;
};

type CoreApi = {
  asset?: AssetApi;
  assets?: { notifyFailure?: (failure: { id: string; label: string; retry: () => void }) => void };
  t?: (key: string) => string;
};

let done = false;
let pending: Promise<void> | null = null;
/** 싣기가 끝난 횟수 — 실패 알림의 다시 시도로 또 끝나면 늘어, 이미 끝난 것으로 본 화면(미리보기)도 다시 알게 한다 */
let revision = 0;
const listeners = new Set<() => void>();
/** 미리보기 문서에 둔 사본 → 그 사본을 만든 바깥 원본 `<link>` */
const copies = new WeakMap<Element, HTMLLinkElement>();

/**
 * 끝났다고 표시하고 기다리는 화면에 알립니다.
 *
 * @returns 반환값 없음
 */
function finish(): void {
  done = true;
  revision += 1;
  listeners.forEach((listener) => listener());
}

/**
 * Swiper CSS 를 한 번만 싣습니다. 코어 자산 API 가 없으면(시험·코어 밖) 싣지 않고 끝난 것으로 봅니다.
 * 끝내 못 실으면 코어 실패 알림에 다시 시도를 걸고, 슬라이더는 전환 효과 없이라도 보이게 끝난 것으로 둡니다.
 *
 * @returns 끝나면 풀리는 약속(실패해도 풀린다)
 */
export function loadSwiperStyles(): Promise<void> {
  if (pending) {
    return pending;
  }

  const core = (window as { G7Core?: CoreApi }).G7Core;
  const asset = core?.asset;

  if (typeof asset?.plugin !== 'function' || typeof asset?.loadStylesheet !== 'function' || document.getElementById(SWIPER_STYLES_ID)) {
    finish();
    pending = Promise.resolve();
    return pending;
  }

  const key = `${PLUGIN}.slider.styles_label`;
  const translated = core?.t?.(key);
  const label = translated && translated !== key ? translated : '배너 슬라이더 모양';

  pending = asset.loadStylesheet(asset.plugin(PLUGIN, PATH), { id: SWIPER_STYLES_ID }, { label })
    .catch((error: unknown) => {
      console.warn('[sirsoft-hero_section] Swiper CSS 로드 실패:', error);
      core?.assets?.notifyFailure?.({
        id: `${PLUGIN}-swiper-css`,
        label,
        retry: () => {
          pending = null;
          void loadSwiperStyles();
        },
      });
    })
    .finally(finish);

  return pending;
}

/**
 * Swiper CSS 가 실렸는지(또는 실을 수 없어 끝났는지) 알려 주는 훅. 처음 부르면 싣기 시작한다.
 *
 * @returns 끝났으면 true
 */
export function useSwiperStyles(): boolean {
  const [ready, setReady] = useState(() => {
    void loadSwiperStyles();
    return done;
  });

  useEffect(() => {
    if (ready) {
      return undefined;
    }

    const listener = () => setReady(true);
    listeners.add(listener);

    if (done) {
      setReady(true);
    }

    return () => {
      listeners.delete(listener);
    };
  }, [ready]);

  return ready;
}

/**
 * Swiper CSS 싣기가 끝날 때마다 바뀌는 차례 번호를 알려 주는 훅. 처음 부르면 싣기 시작한다.
 *
 * `useSwiperStyles` 는 한 번 끝나면 더 알리지 않는다. 미리보기(iframe)는 실패 알림의 다시 시도로 나중에 실린 CSS 도 복사해야
 * 하므로 끝날 때마다 바뀌는 이 값을 지켜본다.
 *
 * @returns 끝난 횟수(아직이면 0)
 */
export function useSwiperStylesRevision(): number {
  const [value, setValue] = useState(() => {
    void loadSwiperStyles();
    return revision;
  });

  useEffect(() => {
    const listener = () => setValue(revision);
    listeners.add(listener);
    setValue(revision);

    return () => {
      listeners.delete(listener);
    };
  }, []);

  return value;
}

/**
 * 바깥 문서에 실린 Swiper CSS 를 다른 문서(미리보기 iframe)의 `<head>` 에도 복사합니다.
 *
 * 같은 바깥 `<link>` 의 사본이 이미 있으면 그대로 둔다(다시 받느라 미리보기를 가리지 않게). 코어 로더는 실패한 시도의 `<link>` 를
 * 지우고 다음 시도에 새 `<link>` 를 붙이므로, 사본이 지금의 원본에서 나온 것이 아니면(실패한 시도의 사본) 지우고 새로 복사한다 —
 * 실패한 사본은 스스로 다시 받지 않아, 그대로 두면 바깥은 실렸는데 미리보기만 Swiper CSS 없이 남는다.
 *
 * @param doc 대상 문서
 * @returns 반환값 없음
 */
export function copySwiperStyles(doc: Document): void {
  const source = document.getElementById(SWIPER_STYLES_ID) as HTMLLinkElement | null;

  if (!source || doc === document) {
    return;
  }

  const existing = doc.getElementById(SWIPER_STYLES_ID);

  if (existing && copies.get(existing) === source) {
    return;
  }

  existing?.remove();
  const copy = doc.createElement('link');
  copy.id = SWIPER_STYLES_ID;
  copy.rel = 'stylesheet';
  copy.href = source.href;
  copies.set(copy, source);
  doc.head.appendChild(copy);
}
