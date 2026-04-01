/**
 * sirsoft-basic 템플릿 Composite 컴포넌트 등록
 *
 * 이 파일에서 모든 Composite 컴포넌트를 export합니다.
 * G7Core에서 자동으로 로드하여 레이아웃 JSON에서 사용할 수 있습니다.
 */
export { default as Header } from './Header';
export { default as Footer } from './Footer';
export { default as MobileNav } from './MobileNav';
export { default as ProductCard } from './ProductCard';
export { default as ImageGallery } from './ImageGallery';
export { default as ProductImageViewer } from './ProductImageViewer';
export { default as QuantitySelector } from './QuantitySelector';
export { default as PostReactions } from './PostReactions';
export { default as RichTextEditor } from './RichTextEditor';
export { HtmlContent } from './HtmlContent';
export { HtmlEditor } from './HtmlEditor';
export { ExpandableContent } from './ExpandableContent';
export { default as FileUploader } from './FileUploader';
export { ConfirmDialog } from './ConfirmDialog';
export { default as SocialLoginButtons } from './SocialLoginButtons';
export { default as Toast } from './Toast';
export { default as PageTransitionIndicator } from './PageTransitionIndicator';
export { default as PageTransitionBlur } from './PageTransitionBlur';
export { default as PageSkeleton } from './PageSkeleton';
export { default as PageLoading } from './PageLoading';
export { default as ThemeToggle } from './ThemeToggle';
export { Pagination } from './Pagination';
export { SearchBar } from './SearchBar';
export { Avatar } from './Avatar';
export { AvatarUploader } from './AvatarUploader';
export { UserInfo } from './UserInfo';
export { Modal } from './Modal';
export { TabNavigation } from './TabNavigation';
/**
 * 컴포넌트 등록 맵
 *
 * G7Core 템플릿 엔진에서 레이아웃 JSON의 컴포넌트 이름을
 * 실제 컴포넌트로 매핑할 때 사용합니다.
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "Header",
 *   "props": { ... }
 * }
 */
export declare const compositeComponents: {
    Header: () => Promise<typeof import("./Header")>;
    Footer: () => Promise<typeof import("./Footer")>;
    MobileNav: () => Promise<typeof import("./MobileNav")>;
    ProductCard: () => Promise<typeof import("./ProductCard")>;
    ImageGallery: () => Promise<typeof import("./ImageGallery")>;
    ProductImageViewer: () => Promise<typeof import("./ProductImageViewer")>;
    QuantitySelector: () => Promise<typeof import("./QuantitySelector")>;
    PostReactions: () => Promise<typeof import("./PostReactions")>;
    RichTextEditor: () => Promise<typeof import("./RichTextEditor")>;
    HtmlContent: () => Promise<typeof import("./HtmlContent")>;
    HtmlEditor: () => Promise<typeof import("./HtmlEditor")>;
    ExpandableContent: () => Promise<typeof import("./ExpandableContent")>;
    FileUploader: () => Promise<typeof import("./FileUploader")>;
    ConfirmDialog: () => Promise<typeof import("./ConfirmDialog")>;
    SocialLoginButtons: () => Promise<typeof import("./SocialLoginButtons")>;
    Toast: () => Promise<typeof import("./Toast")>;
    PageTransitionIndicator: () => Promise<typeof import("./PageTransitionIndicator")>;
    PageTransitionBlur: () => Promise<typeof import("./PageTransitionBlur")>;
    PageSkeleton: () => Promise<typeof import("./PageSkeleton")>;
    PageLoading: () => Promise<typeof import("./PageLoading")>;
    ThemeToggle: () => Promise<typeof import("./ThemeToggle")>;
    Pagination: () => Promise<typeof import("./Pagination")>;
    SearchBar: () => Promise<typeof import("./SearchBar")>;
    Avatar: () => Promise<typeof import("./Avatar")>;
    AvatarUploader: () => Promise<typeof import("./AvatarUploader")>;
    UserInfo: () => Promise<typeof import("./UserInfo")>;
    Modal: () => Promise<typeof import("./Modal")>;
    TabNavigation: () => Promise<typeof import("./TabNavigation")>;
};
/**
 * 컴포넌트 타입 정의 (TypeScript 자동완성용)
 */
export type CompositeComponentName = keyof typeof compositeComponents;
