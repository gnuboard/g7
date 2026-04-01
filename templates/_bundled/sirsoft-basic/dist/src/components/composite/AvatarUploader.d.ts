/**
 * AvatarUploader 컴포넌트
 *
 * 프로필 아바타 이미지 업로드를 위한 컴포넌트입니다.
 * - 원형 아바타 UI
 * - 파일 선택 시 즉시 업로드
 * - 업로드/삭제 API 지원
 * - 업로드 오류 표시
 * - 삭제 확인 다이얼로그
 *
 * @module composite/AvatarUploader
 */
export interface AvatarUploaderProps {
    /** 현재 아바타 URL */
    src?: string;
    /** 이미지 없을 때 표시할 텍스트 (이니셜) */
    fallbackText?: string;
    /** 아바타 크기 */
    size?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
    /** 업로드 API 엔드포인트 (기본: /api/me/avatar) */
    uploadEndpoint?: string;
    /** 삭제 API 엔드포인트 (기본: /api/me/avatar) */
    deleteEndpoint?: string;
    /** 업로드 성공 콜백 */
    onUploadSuccess?: (data: {
        avatar: string;
        attachment_id: number;
    }) => void;
    /** 업로드 에러 콜백 */
    onUploadError?: (error: Error) => void;
    /** 삭제 성공 콜백 */
    onDeleteSuccess?: () => void;
    /** 삭제 에러 콜백 */
    onDeleteError?: (error: Error) => void;
    /** 삭제 버튼 표시 여부 */
    showDeleteButton?: boolean;
    /** 허용 파일 타입 (기본: image/*) */
    accept?: string;
    /** 최대 파일 크기 (MB, 기본: 5) */
    maxSize?: number;
    /** 추가 CSS 클래스 */
    className?: string;
    /** 업로드 버튼 텍스트 */
    uploadButtonText?: string;
    /** 삭제 버튼 텍스트 */
    deleteButtonText?: string;
    /** 읽기 전용 모드 */
    readOnly?: boolean;
    /** 삭제 확인 다이얼로그 표시 여부 (기본: true) */
    confirmDelete?: boolean;
    /** 삭제 확인 메시지 */
    deleteConfirmMessage?: string;
    /** 업로드 확인 다이얼로그 표시 여부 (기본: true) */
    confirmUpload?: boolean;
    /** 업로드 확인 메시지 */
    uploadConfirmMessage?: string;
    /** 업로드 성공 시 실행할 액션 배열 (G7Core.dispatch) */
    uploadSuccessActions?: Array<Record<string, any>>;
    /** 업로드 실패 시 실행할 액션 배열 */
    uploadErrorActions?: Array<Record<string, any>>;
    /** 삭제 성공 시 실행할 액션 배열 */
    deleteSuccessActions?: Array<Record<string, any>>;
    /** 삭제 실패 시 실행할 액션 배열 */
    deleteErrorActions?: Array<Record<string, any>>;
}
export declare function AvatarUploader({ src, fallbackText, size, uploadEndpoint, deleteEndpoint, onUploadSuccess, onUploadError, onDeleteSuccess, onDeleteError, showDeleteButton, accept, maxSize, className, uploadButtonText, deleteButtonText, readOnly, confirmDelete, deleteConfirmMessage, confirmUpload, uploadConfirmMessage, uploadSuccessActions, uploadErrorActions, deleteSuccessActions, deleteErrorActions, }: AvatarUploaderProps): import("react/jsx-runtime").JSX.Element;
export default AvatarUploader;
