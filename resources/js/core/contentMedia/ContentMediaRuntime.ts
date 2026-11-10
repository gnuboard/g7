/**
 * 본문 미디어 업로드 런타임 (engine-v1.89.0, #883)
 *
 * 편집기 플러그인이 확장의 업로드 계약(`ContentMediaUploadTarget`)을 검사·사용하는 공용 함수 묶음이다.
 * `G7Core.contentMedia` 파사드가 이 런타임 하나를 감싼다(`initContentMediaAPI`).
 *
 * - `normalizeTarget(raw)` — 형식이 틀리거나 다른 출처 주소면 null. 헤더는 허용 목록만 남긴다.
 * - `validateFile(target, file)` — 종류·확장자·크기를 업로드 전에 판정한다(서버도 같은 규칙으로 다시 본다).
 * - `countReferences(html, target)` — 본문이 이미 참조하는 미디어 수(문서당 개수 사전 차단).
 * - `upload(target, file, ctx)` — 어댑터를 골라 올리고 `{url, id?, kind}` 를 돌려준다. 기본 어댑터는 ApiClient +
 *   FormData 이고, 토큰 없이 보낸 요청의 401 은 그대로 거절로 전달한다(engine-v1.88.2 — 로그인 화면으로 옮기지 않는다).
 * - `registerAdapter(adapter)` → 해제 함수, `listAdapters()`.
 */

import type {
  ContentMediaAdapter,
  ContentMediaKind,
  ContentMediaUploadContext,
  ContentMediaUploadResult,
  ContentMediaUploadTarget,
  ContentMediaValidation,
  KindPolicy,
} from './types';

/** 계약 버전 */
export const CONTENT_MEDIA_CONTRACT_VERSION = 1;

/** 계약이 실을 수 있는 헤더 (서버 `ContentMediaUploadTarget::ALLOWED_HEADERS` 와 같음) */
export const CONTENT_MEDIA_ALLOWED_HEADERS = ['X-Board-Post-Verify-Token'];

/** 기본 어댑터 id */
export const DEFAULT_ADAPTER_ID = 'core-api';

/**
 * 종류별 후보 확장자 — 서버 `App\Enums\ContentMediaKind::candidateExtensions()` 와 같은 닫힌 집합.
 * 정책은 이 안에서만 고를 수 있다.
 */
export const CONTENT_MEDIA_CANDIDATE_EXTENSIONS: Readonly<Record<ContentMediaKind, readonly string[]>> = Object.freeze({
  image: Object.freeze(['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']),
  video: Object.freeze(['mp4', 'webm', 'ogv']),
  audio: Object.freeze(['mp3', 'm4a', 'ogg', 'oga', 'wav']),
});

const KINDS: ContentMediaKind[] = ['image', 'video', 'audio'];

/** 런타임이 쓰는 HTTP 클라이언트 (ApiClient 의 post 와 같은 모양) */
export interface ContentMediaHttpClient {
  post<T = any>(url: string, data?: any, config?: Record<string, unknown>): Promise<T>;
}

/** 런타임 의존성 */
export interface ContentMediaRuntimeDeps {
  /** 호출 시점의 HTTP 클라이언트 (ApiClient 싱글톤) */
  getHttpClient: () => ContentMediaHttpClient;
}

/**
 * 파일 이름의 확장자(소문자)를 꺼냅니다.
 *
 * @param name 파일 이름
 * @returns 확장자 (없으면 빈 문자열)
 */
function extensionOf(name: string): string {
  const dot = name.lastIndexOf('.');
  return dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
}

/**
 * 점 경로로 값을 꺼냅니다.
 *
 * @param source 원본 객체
 * @param path 점 경로 (예: `data.url`)
 * @returns 값 (없으면 undefined)
 */
function readPath(source: unknown, path: string | undefined): unknown {
  if (!path) return undefined;
  return path.split('.').reduce<unknown>((acc, key) => (acc != null && typeof acc === 'object' ? (acc as Record<string, unknown>)[key] : undefined), source);
}

/**
 * 종류 정책 하나를 정규화합니다.
 *
 * @param raw 원본
 * @param kind 종류
 * @returns 정규화한 정책 (형식이 틀리면 null)
 */
function normalizeKindPolicy(raw: unknown, kind: ContentMediaKind): KindPolicy | null {
  if (raw == null || typeof raw !== 'object') return null;
  const value = raw as Record<string, unknown>;
  const candidates = CONTENT_MEDIA_CANDIDATE_EXTENSIONS[kind];
  const extensions = Array.isArray(value.extensions)
    ? value.extensions.map((e) => String(e).toLowerCase()).filter((e) => candidates.includes(e))
    : [];
  const maxSizeMb = Number(value.maxSizeMb);

  return {
    enabled: value.enabled === true && extensions.length > 0,
    maxSizeMb: Number.isFinite(maxSizeMb) && maxSizeMb > 0 ? maxSizeMb : 0,
    extensions,
    mimeTypes: Array.isArray(value.mimeTypes) ? value.mimeTypes.map((m) => String(m)) : [],
  };
}

/**
 * 같은 출처 상대 경로인지 판정합니다(`//host`·`scheme:` 거부).
 *
 * @param endpoint 주소
 * @returns 같은 출처 상대 경로면 true
 */
function isSameOriginPath(endpoint: unknown): endpoint is string {
  return typeof endpoint === 'string' && endpoint.startsWith('/') && !endpoint.startsWith('//') && !endpoint.includes('\\') && !/^[a-z][a-z0-9+.-]*:/i.test(endpoint);
}

/**
 * 본문 미디어 런타임
 */
export class ContentMediaRuntime {
  private adapters: ContentMediaAdapter[] = [];

  /**
   * @param deps 의존성 (HTTP 클라이언트)
   */
  constructor(private readonly deps: ContentMediaRuntimeDeps) {
    this.adapters.push({
      id: DEFAULT_ADAPTER_ID,
      priority: 0,
      supports: () => true,
      upload: (target, file, ctx) => this.defaultUpload(target, file, ctx),
    });
  }

  /**
   * 계약을 검사해 정규화합니다.
   *
   * @param raw 확장이 넘긴 값
   * @returns 정규화한 계약 (형식이 틀리거나 다른 출처 주소면 null)
   */
  normalizeTarget(raw: unknown): ContentMediaUploadTarget | null {
    if (raw == null || typeof raw !== 'object') return null;
    const value = raw as Record<string, unknown>;
    if (Number(value.version) !== CONTENT_MEDIA_CONTRACT_VERSION || !isSameOriginPath(value.endpoint)) return null;

    const response = value.response as Record<string, unknown> | undefined;
    if (!response || typeof response.urlPath !== 'string' || response.urlPath === '') return null;

    if (typeof value.mediaUrlPattern !== 'string' || value.mediaUrlPattern === '') return null;
    try {
      new RegExp(value.mediaUrlPattern);
    } catch {
      return null;
    }

    const rawPolicy = value.policy as Record<string, unknown> | undefined;
    if (!rawPolicy || typeof rawPolicy !== 'object') return null;
    const kinds = {} as Record<ContentMediaKind, KindPolicy>;
    for (const kind of KINDS) {
      const normalized = normalizeKindPolicy(rawPolicy[kind], kind);
      if (!normalized) return null;
      kinds[kind] = normalized;
    }
    const maxPerDocument = Number(rawPolicy.maxPerDocument);

    const fields: Record<string, string | number> = {};
    if (value.fields && typeof value.fields === 'object') {
      for (const [key, v] of Object.entries(value.fields as Record<string, unknown>)) {
        if (typeof v === 'string' || typeof v === 'number') fields[key] = v;
      }
    }

    const headers: Record<string, string> = {};
    if (value.headers && typeof value.headers === 'object') {
      for (const [key, v] of Object.entries(value.headers as Record<string, unknown>)) {
        if (typeof v === 'string' && v !== '' && CONTENT_MEDIA_ALLOWED_HEADERS.includes(key)) headers[key] = v;
      }
    }

    return {
      version: 1,
      endpoint: value.endpoint,
      fileField: typeof value.fileField === 'string' && value.fileField !== '' ? value.fileField : 'file',
      fields,
      headers,
      response: {
        urlPath: response.urlPath,
        idPath: typeof response.idPath === 'string' ? response.idPath : undefined,
        kindPath: typeof response.kindPath === 'string' ? response.kindPath : undefined,
      },
      mediaUrlPattern: value.mediaUrlPattern,
      policy: { ...kinds, maxPerDocument: Number.isFinite(maxPerDocument) && maxPerDocument > 0 ? Math.floor(maxPerDocument) : 0 },
    };
  }

  /**
   * 파일 이름으로 종류를 판정합니다(후보 집합 기준).
   *
   * @param file 파일
   * @returns 종류 (후보 밖이면 null)
   */
  kindOf(file: Pick<File, 'name'>): ContentMediaKind | null {
    const extension = extensionOf(file.name);
    return KINDS.find((kind) => CONTENT_MEDIA_CANDIDATE_EXTENSIONS[kind].includes(extension)) ?? null;
  }

  /**
   * 업로드 전에 파일을 판정합니다.
   *
   * @param target 계약
   * @param file 파일
   * @returns 판정 결과
   */
  validateFile(target: ContentMediaUploadTarget, file: Pick<File, 'name' | 'size'>): ContentMediaValidation {
    const kind = this.kindOf(file);
    const allowed = KINDS.filter((k) => target.policy[k].enabled)
      .map((k) => target.policy[k].extensions.join(', '))
      .join(' · ');

    if (kind === null || !target.policy[kind].enabled) {
      return { ok: false, kind, messageKey: 'core.content_media.client.kind_not_allowed', params: { allowed: allowed || '-' } };
    }

    const policy = target.policy[kind];
    if (!policy.extensions.includes(extensionOf(file.name))) {
      return { ok: false, kind, messageKey: 'core.content_media.client.extension_not_allowed', params: { extensions: policy.extensions.join(', ') } };
    }

    if (policy.maxSizeMb > 0 && file.size > policy.maxSizeMb * 1024 * 1024) {
      return { ok: false, kind, messageKey: 'core.content_media.client.file_too_large', params: { max: policy.maxSizeMb } };
    }

    return { ok: true, kind };
  }

  /**
   * 본문이 참조하는 이 계약의 미디어 수(서로 다른 해시).
   *
   * @param html 본문 HTML
   * @param target 계약
   * @returns 개수
   */
  countReferences(html: string | null | undefined, target: ContentMediaUploadTarget): number {
    if (!html) return 0;
    let regex: RegExp;
    try {
      regex = new RegExp(target.mediaUrlPattern, 'g');
    } catch {
      return 0;
    }
    const hashes = new Set<string>();
    for (const match of html.matchAll(regex)) {
      hashes.add(match.groups?.hash ?? match[0]);
    }
    return hashes.size;
  }

  /**
   * 문서당 개수 상한에 이미 닿았는지 판정합니다.
   *
   * @param html 본문 HTML
   * @param target 계약
   * @returns 더 넣을 수 없으면 true
   */
  isAtLimit(html: string | null | undefined, target: ContentMediaUploadTarget): boolean {
    return target.policy.maxPerDocument > 0 && this.countReferences(html, target) >= target.policy.maxPerDocument;
  }

  /**
   * 파일을 올립니다 — 우선순위가 높은 어댑터부터 `supports` 가 참인 첫 어댑터를 쓴다.
   *
   * @param target 계약
   * @param file 파일
   * @param ctx 취소 신호·진행 콜백
   * @returns 업로드 결과
   */
  async upload(target: ContentMediaUploadTarget, file: File, ctx: ContentMediaUploadContext = {}): Promise<ContentMediaUploadResult> {
    const adapter = this.listAdapters().find((a) => {
      try {
        return a.supports(target, file);
      } catch {
        return false;
      }
    });
    if (!adapter) throw new Error('content_media: no adapter');
    return adapter.upload(target, file, ctx);
  }

  /**
   * 어댑터를 등록합니다. 같은 id 가 있으면 바꾼다.
   *
   * @param adapter 어댑터
   * @returns 해제 함수
   */
  registerAdapter(adapter: ContentMediaAdapter): () => void {
    if (!adapter || typeof adapter.id !== 'string' || typeof adapter.supports !== 'function' || typeof adapter.upload !== 'function') {
      return () => {};
    }
    this.adapters = this.adapters.filter((a) => a.id !== adapter.id).concat(adapter);
    return () => {
      this.adapters = this.adapters.filter((a) => a !== adapter);
    };
  }

  /**
   * 어댑터 목록(우선순위 내림차순, 같으면 나중 등록이 먼저).
   *
   * @returns 어댑터 사본 목록
   */
  listAdapters(): ContentMediaAdapter[] {
    return this.adapters
      .map((adapter, index) => ({ adapter, index }))
      .sort((a, b) => (b.adapter.priority ?? 0) - (a.adapter.priority ?? 0) || b.index - a.index)
      .map(({ adapter }) => adapter);
  }

  /**
   * 기본 어댑터 — ApiClient + FormData.
   *
   * @param target 계약
   * @param file 파일
   * @param ctx 문맥
   * @returns 업로드 결과
   */
  private async defaultUpload(target: ContentMediaUploadTarget, file: File, ctx: ContentMediaUploadContext): Promise<ContentMediaUploadResult> {
    const form = new FormData();
    for (const [key, value] of Object.entries(target.fields ?? {})) {
      form.append(key, String(value));
    }
    form.append(target.fileField || 'file', file);

    // ApiClient 는 `/api` 를 기준 주소로 쓴다 — 계약 주소의 앞부분을 뗀다
    const endpoint = target.endpoint.startsWith('/api/') ? target.endpoint.slice(4) : target.endpoint;
    const body = await this.deps.getHttpClient().post(endpoint, form, {
      headers: { ...(target.headers ?? {}), 'Content-Type': 'multipart/form-data' },
      signal: ctx.signal,
      onUploadProgress: ctx.onProgress
        ? (event: { loaded: number; total?: number }) => ctx.onProgress?.(event.loaded, event.total ?? file.size)
        : undefined,
    });

    const url = readPath(body, target.response.urlPath);
    if (typeof url !== 'string' || url === '') {
      throw new Error('content_media: upload response has no url');
    }
    const id = readPath(body, target.response.idPath);
    const kind = readPath(body, target.response.kindPath);

    return {
      url,
      id: typeof id === 'string' || typeof id === 'number' ? id : undefined,
      kind: KINDS.includes(kind as ContentMediaKind) ? (kind as ContentMediaKind) : (this.kindOf(file) ?? 'image'),
    };
  }
}
