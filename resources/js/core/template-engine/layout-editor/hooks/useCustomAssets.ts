/**
 * useCustomAssets.ts — 사용자 추가 에셋(`custom/`) 조회·편집 hook
 *
 * 운영자가 자기 CSS·JS·폰트·이미지를 화면에서 직접 넣고 고칠 수 있게 하는 관리 API 를
 * 감싼다. 종전에는 FTP 나 서버 셸이 유일한 경로였고, 그 접근이 없는 운영자에게는 기능
 * 자체가 없는 것과 같았다.
 *
 *  - GET    /api/admin/extensions/{type}/{id}/custom-assets           (목록 + 편집기 메타)
 *  - GET    /api/admin/extensions/{type}/{id}/custom-assets/content   (본문)
 *  - PUT    /api/admin/extensions/{type}/{id}/custom-assets/content   (본문 저장·생성)
 *  - POST   /api/admin/extensions/{type}/{id}/custom-assets/upload    (바이너리 업로드)
 *  - DELETE /api/admin/extensions/{type}/{id}/custom-assets           (삭제)
 *
 * `{type}` 은 `module` | `plugin` | `template`. 세 타입이 한 엔드포인트를 공유하므로
 * 이 훅도 타입을 인자로 받는다 — 타입별 훅을 세 벌 두면 그중 하나만 고쳐 놓는 상태가 된다.
 *
 * 권한(`core.extensions.custom_assets.manage`)은 서버가 판정한다. 403 은 오류가 아니라
 * **상태**로 취급해 호출자가 안내 문구로 바꿀 수 있게 별도 플래그(`forbidden`)로 알린다 —
 * "HTTP 403" 을 그대로 띄우면 운영자는 무엇이 부족한지 알 수 없다.
 *
 * @since engine-v1.63.0
 */

import { useCallback, useState } from 'react';
import { buildAuthHeaders } from '../utils/authToken';

/** 목록 항목 1건 */
export interface CustomAssetFile {
  /** `custom/` 기준 상대 경로 */
  path: string;
  /** 파일명 */
  name: string;
  /** 소문자 확장자 */
  extension: string;
  /** 바이트 크기 */
  size: number;
  /** 최종 수정 시각 (ISO 8601) */
  modified_at: string;
  /** 편집기에서 본문을 열 수 있는지 */
  editable: boolean;
  /** 실제로 페이지에 실리는지 (규약 스캔·선언 파일 결과) */
  loaded: boolean;
}

/** 목록 응답의 편집기 메타 */
export interface CustomAssetMeta {
  editable_extensions: string[];
  uploadable_extensions: string[];
  max_text_bytes: number;
  max_upload_bytes: number;
}

/** 훅 반환 */
export interface UseCustomAssetsResult {
  files: CustomAssetFile[];
  meta: CustomAssetMeta | null;
  isLoading: boolean;
  /** 권한 부족 — 오류 문구가 아니라 안내로 바꿔야 하는 상태 */
  forbidden: boolean;
  error: string | null;
  reload: () => Promise<void>;
  read: (path: string) => Promise<string | null>;
  save: (path: string, content: string) => Promise<boolean>;
  upload: (file: File, directory?: string) => Promise<boolean>;
  remove: (path: string) => Promise<boolean>;
}

/** 관리 대상 확장 — 타입 + 식별자. */
export interface CustomAssetTarget {
  type: 'module' | 'plugin' | 'template';
  identifier: string;
}

/**
 * 관리 API base URL 을 만듭니다.
 *
 * @param target 관리 대상 확장
 * @returns base URL
 */
function baseUrl(target: CustomAssetTarget): string {
  return `/api/admin/extensions/${encodeURIComponent(target.type)}/${encodeURIComponent(target.identifier)}/custom-assets`;
}

/**
 * 응답 본문에서 사용자에게 보일 메시지를 뽑습니다.
 *
 * 서버가 다국어 키를 해석해 문장으로 내려주므로 그것을 그대로 쓴다. 없을 때만
 * 상태 코드로 폴백한다 — 그 경우에도 "HTTP 500" 이 안내로서 최소한의 단서는 된다.
 *
 * @param body 파싱된 응답 본문
 * @param status HTTP 상태 코드
 * @returns 메시지
 */
function messageOf(body: unknown, status: number): string {
  const message = (body as { message?: unknown })?.message;

  return typeof message === 'string' && message !== '' ? message : `HTTP ${status}`;
}

/**
 * 사용자 추가 에셋 관리 hook.
 *
 * @param target 관리 대상 확장 (타입 + 식별자)
 * @returns 목록 상태 + 조작 함수
 */
export function useCustomAssets(target: CustomAssetTarget): UseCustomAssetsResult {
  const [files, setFiles] = useState<CustomAssetFile[]>([]);
  const [meta, setMeta] = useState<CustomAssetMeta | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const reload = useCallback(async (): Promise<void> => {
    setIsLoading(true);
    setError(null);

    try {
      const response = await fetch(baseUrl(target), {
        credentials: 'same-origin',
        headers: buildAuthHeaders(),
      });
      const body = await response.json().catch(() => null);

      if (response.status === 401 || response.status === 403) {
        setForbidden(true);
        setFiles([]);

        return;
      }

      setForbidden(false);

      if (!response.ok) {
        setError(messageOf(body, response.status));
        setFiles([]);

        return;
      }

      const data = (body as { data?: Record<string, unknown> })?.data ?? {};
      const list = data.files;

      setFiles(Array.isArray(list) ? (list as CustomAssetFile[]) : []);
      setMeta({
        editable_extensions: (data.editable_extensions as string[]) ?? [],
        uploadable_extensions: (data.uploadable_extensions as string[]) ?? [],
        max_text_bytes: (data.max_text_bytes as number) ?? 0,
        max_upload_bytes: (data.max_upload_bytes as number) ?? 0,
      });
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'network error');
      setFiles([]);
    } finally {
      setIsLoading(false);
    }
  }, [target.type, target.identifier]);

  const read = useCallback(
    async (path: string): Promise<string | null> => {
      setError(null);

      try {
        const url = `${baseUrl(target)}/content?path=${encodeURIComponent(path)}`;
        const response = await fetch(url, {
          credentials: 'same-origin',
          headers: buildAuthHeaders(),
        });
        const body = await response.json().catch(() => null);

        if (!response.ok) {
          setError(messageOf(body, response.status));

          return null;
        }

        const content = (body as { data?: { content?: unknown } })?.data?.content;

        return typeof content === 'string' ? content : '';
      } catch (err: unknown) {
        setError(err instanceof Error ? err.message : 'network error');

        return null;
      }
    },
    [target.type, target.identifier],
  );

  const save = useCallback(
    async (path: string, content: string): Promise<boolean> => {
      setError(null);

      try {
        const response = await fetch(`${baseUrl(target)}/content`, {
          method: 'PUT',
          credentials: 'same-origin',
          headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
          body: JSON.stringify({ path, content }),
        });
        const body = await response.json().catch(() => null);

        if (!response.ok) {
          setError(messageOf(body, response.status));

          return false;
        }

        await reload();

        return true;
      } catch (err: unknown) {
        setError(err instanceof Error ? err.message : 'network error');

        return false;
      }
    },
    [target.type, target.identifier, reload],
  );

  const upload = useCallback(
    async (file: File, directory?: string): Promise<boolean> => {
      setError(null);

      try {
        const form = new FormData();
        form.append('file', file);

        if (directory) {
          form.append('directory', directory);
        }

        // multipart 는 boundary 를 브라우저가 붙이므로 Content-Type 을 명시하지 않는다.
        const response = await fetch(`${baseUrl(target)}/upload`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: buildAuthHeaders(),
          body: form,
        });
        const body = await response.json().catch(() => null);

        if (!response.ok) {
          setError(messageOf(body, response.status));

          return false;
        }

        await reload();

        return true;
      } catch (err: unknown) {
        setError(err instanceof Error ? err.message : 'network error');

        return false;
      }
    },
    [target.type, target.identifier, reload],
  );

  const remove = useCallback(
    async (path: string): Promise<boolean> => {
      setError(null);

      try {
        const url = `${baseUrl(target)}?path=${encodeURIComponent(path)}`;
        const response = await fetch(url, {
          method: 'DELETE',
          credentials: 'same-origin',
          headers: buildAuthHeaders(),
        });
        const body = await response.json().catch(() => null);

        if (!response.ok) {
          setError(messageOf(body, response.status));

          return false;
        }

        await reload();

        return true;
      } catch (err: unknown) {
        setError(err instanceof Error ? err.message : 'network error');

        return false;
      }
    },
    [target.type, target.identifier, reload],
  );

  return { files, meta, isLoading, forbidden, error, reload, read, save, upload, remove };
}
