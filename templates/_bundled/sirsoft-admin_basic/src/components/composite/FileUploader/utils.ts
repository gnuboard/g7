import { translate } from '../../../utils/translate';
import type { ApiEndpoints, FileUploaderProps } from './types';

/**
 * FileUploader 유틸리티 함수
 *
 * @module composite/FileUploader/utils
 */

/**
 * 레이아웃이 넘긴 주소 묶음에 기본값을 채웁니다.
 *
 * 정렬 주소는 `reorder` 키가 **있으면** 그 값을 쓰고(`null`·빈 문자열이면 정렬 요청을 보내지 않는다), 키가 없을 때만
 * 코어 첨부 정렬 주소로 떨어진다. 종전 `??` 는 `null` 도 기본값으로 바꿔, 정렬 API 가 없는 화면(카테고리·반응 아이콘·
 * 배너·팝업)에서 끌어 옮기면 코어 정렬 API 로 요청이 나갔다.
 *
 * @param apiEndpoints 레이아웃이 넘긴 주소 묶음
 * @returns 업로드·삭제·정렬 주소(정렬하지 않으면 reorder 가 null)
 */
export const resolveApiEndpoints = (apiEndpoints: FileUploaderProps['apiEndpoints']): ApiEndpoints => {
  const hasReorderKey = apiEndpoints != null && Object.prototype.hasOwnProperty.call(apiEndpoints, 'reorder');

  return {
    upload: apiEndpoints?.upload ?? '/api/admin/attachments',
    delete: apiEndpoints?.delete ?? '/api/admin/attachments/:id',
    reorder: hasReorderKey ? (apiEndpoints?.reorder || null) : '/api/admin/attachments/reorder',
  };
};

/**
 * 파일 크기를 읽기 쉬운 형식으로 변환합니다.
 */
export const formatFileSize = (bytes: number): string => {
  const units = ['B', 'KB', 'MB', 'GB'];
  let i = 0;
  let size = bytes;
  while (size >= 1024 && i < units.length - 1) {
    size /= 1024;
    i++;
  }
  return `${size.toFixed(2)} ${units[i]}`;
};

/**
 * API 에러 응답에서 상세 메시지를 추출합니다.
 */
export const extractErrorMessage = (error: unknown): string => {
  // Axios 에러 응답 구조 처리
  if (error && typeof error === 'object') {
    const axiosError = error as {
      response?: {
        data?: {
          message?: string;
          errors?: Record<string, string[]>;
        };
      };
      message?: string;
    };

    // API 응답의 message 필드
    if (axiosError.response?.data?.message) {
      return axiosError.response.data.message;
    }

    // Laravel validation 에러 (errors 객체의 첫 번째 메시지)
    if (axiosError.response?.data?.errors) {
      const firstError = Object.values(axiosError.response.data.errors)[0];
      if (Array.isArray(firstError) && firstError[0]) {
        return firstError[0];
      }
    }

    // 일반 에러 메시지
    if (axiosError.message) {
      return axiosError.message;
    }
  }

  // Error 인스턴스
  if (error instanceof Error) {
    return error.message;
  }

  return '업로드 실패';
};

/**
 * MIME 타입에 따른 Font Awesome 아이콘 클래스를 반환합니다.
 */
export const getFileIcon = (mimeType: string): string => {
  if (!mimeType) return 'fa-file';
  if (mimeType.startsWith('image/')) return 'fa-file-image';
  if (mimeType.startsWith('video/')) return 'fa-file-video';
  if (mimeType.startsWith('audio/')) return 'fa-file-audio';
  if (mimeType === 'application/pdf') return 'fa-file-pdf';
  if (mimeType.includes('word') || mimeType.includes('document')) return 'fa-file-word';
  if (mimeType.includes('excel') || mimeType.includes('spreadsheet')) return 'fa-file-excel';
  if (mimeType.includes('zip') || mimeType.includes('compressed')) return 'fa-file-zipper';
  return 'fa-file';
};

/**
 * G7Core.t() 번역 함수 참조
 *
 * 호출 시점의 `window.G7Core` 를 읽는다 — 모듈을 불러온 시점에 잡아 두면 그 뒤에 준비된 번역 함수를 쓰지 못한다.
 */
export const t = (key: string, params?: Record<string, string | number>) =>
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  translate(key, params) ?? key;

/**
 * 파일이 업로더의 `accept` 값에 맞는지 판정합니다.
 *
 * `accept` 는 HTML 파일 입력과 같은 표기를 받는다 — 확장자(`.png`), MIME(`text/plain`), MIME 계열
 * 와일드카드(`image/*`). 파일 선택창이 세 표기를 모두 따르므로 사전 검사도 같아야 한다. 확장자만 보면
 * 운영자가 허용 형식을 MIME 으로 둔 경우 서버가 받아 주는 파일을 화면이 먼저 거절한다.
 *
 * @param file 검사할 파일
 * @param accept 쉼표로 구분한 허용 표기 목록 (비면 모두 허용)
 * @returns 허용되면 true
 */
export const isFileAccepted = (file: File, accept?: string): boolean => {
  const tokens = (accept ?? '')
    .split(',')
    .map((token) => token.trim().toLowerCase())
    .filter((token) => token !== '');

  if (tokens.length === 0) {
    return true;
  }

  const name = file.name.toLowerCase();
  const dot = name.lastIndexOf('.');
  const extension = dot >= 0 ? name.slice(dot) : '';
  const mimeType = (file.type || '').toLowerCase();

  return tokens.some((token) => {
    if (token.startsWith('.')) {
      return extension === token;
    }
    if (token.endsWith('/*')) {
      return mimeType !== '' && mimeType.startsWith(token.slice(0, -1));
    }
    return mimeType !== '' && mimeType === token;
  });
};

/** 움직이는 이미지 판정에서 읽는 청크 수 상한 — 깨진 파일에서 무한히 돌지 않게 한다(서버 판정과 같은 값) */
const ANIMATION_MAX_CHUNKS = 10000;

/**
 * 파일 바이트를 읽는다 — `Blob.arrayBuffer` 가 없는 환경(구형 브라우저·테스트 DOM)은 FileReader 로 읽는다.
 *
 * @param file 읽을 파일
 * @returns 파일 바이트
 */
const readFileBytes = async (file: Blob): Promise<Uint8Array> => {
  if (typeof (file as Blob).arrayBuffer === 'function') {
    return new Uint8Array(await file.arrayBuffer());
  }

  return new Promise<Uint8Array>((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(new Uint8Array(reader.result as ArrayBuffer));
    reader.onerror = () => reject(reader.error);
    reader.readAsArrayBuffer(file);
  });
};

const ascii = (data: Uint8Array, offset: number, length: number): string =>
  String.fromCharCode(...data.subarray(offset, offset + length));

/**
 * 움직이는 GIF 인지 — 그래픽 제어 확장 블록(`21 F9 04 …4바이트… 00`) 뒤에 이미지 서술자(`2C`)나 다른 확장(`21`)이
 * 오는 자리가 둘 이상이면 움직인다. 블록 앞 바이트는 보지 않는다(반복 표시 블록이 없는 GIF 는 첫 블록 앞이 색상표다).
 */
const isAnimatedGifBytes = (data: Uint8Array): boolean => {
  let count = 0;
  for (let i = 0; i + 8 < data.length && count < 2; i++) {
    if (data[i] === 0x21 && data[i + 1] === 0xf9 && data[i + 2] === 0x04 && data[i + 7] === 0x00 && (data[i + 8] === 0x2c || data[i + 8] === 0x21)) {
      count++;
      i += 7;
    }
  }
  return count > 1;
};

/** 움직이는 WebP 인지 — RIFF 청크 머리를 따라가며 `ANMF`(프레임) 청크가 둘 이상이면 움직인다 */
const isAnimatedWebpBytes = (data: Uint8Array): boolean => {
  const view = new DataView(data.buffer, data.byteOffset, data.byteLength);
  let offset = 12;
  let frames = 0;
  for (let i = 0; i < ANIMATION_MAX_CHUNKS && frames < 2 && offset + 8 <= data.length; i++) {
    const length = view.getUint32(offset + 4, true);
    if (ascii(data, offset, 4) === 'ANMF') frames++;
    offset += 8 + length + (length % 2);
  }
  return frames > 1;
};

/** 움직이는 PNG(APNG) 인지 — 첫 `IDAT` 앞에 `acTL` 이 있고 그 프레임 수가 둘 이상이면 움직인다 */
const isAnimatedPngBytes = (data: Uint8Array): boolean => {
  const view = new DataView(data.buffer, data.byteOffset, data.byteLength);
  let offset = 8;
  for (let i = 0; i < ANIMATION_MAX_CHUNKS && offset + 8 <= data.length; i++) {
    const length = view.getUint32(offset, false);
    const type = ascii(data, offset + 4, 4);
    if (type === 'acTL') {
      return offset + 12 <= data.length && view.getUint32(offset + 8, false) > 1;
    }
    if (type === 'IDAT' || type === 'IEND') {
      return false;
    }
    // 길이·형식(8) + 본문 + CRC(4)
    offset += 12 + length;
  }
  return false;
};

/**
 * 움직이는 이미지 형식을 파일 바이트로 가린다 — MIME·확장자는 믿지 않는다.
 *
 * 판정 규칙은 서버 썸네일 생성기(`ThumbnailGenerator::animatedFormat`)와 같다: GIF 는 프레임 2개 이상,
 * WebP 는 `ANMF` 프레임 청크 2개 이상(`ANIM` 표시만 있고 프레임이 하나면 정지 그림), PNG 는 `IDAT` 앞 `acTL` 의
 * 프레임 수 2 이상. 압축기는 첫 프레임만 다시 인코딩하므로 움직이는 이미지는 압축을 건너뛴다(#869 P4).
 *
 * @param file 검사할 파일
 * @returns 움직이면 'gif' | 'webp' | 'png', 아니면(정지 그림·다른 형식·읽기 실패) null
 */
export const detectAnimatedImageFormat = async (file: Blob): Promise<'gif' | 'webp' | 'png' | null> => {
  let data: Uint8Array;
  try {
    data = await readFileBytes(file);
  } catch {
    return null;
  }

  if (data.length >= 6 && ascii(data, 0, 4) === 'GIF8') {
    return isAnimatedGifBytes(data) ? 'gif' : null;
  }
  if (data.length >= 12 && ascii(data, 0, 4) === 'RIFF' && ascii(data, 8, 4) === 'WEBP') {
    return isAnimatedWebpBytes(data) ? 'webp' : null;
  }
  if (data.length >= 8 && data[0] === 0x89 && ascii(data, 1, 3) === 'PNG' && data[4] === 0x0d && data[5] === 0x0a && data[6] === 0x1a && data[7] === 0x0a) {
    return isAnimatedPngBytes(data) ? 'png' : null;
  }

  return null;
};
