/**
 * Font Awesome 아이콘 스타일
 */
export type IconStyle = 'solid' | 'regular' | 'light' | 'duotone' | 'brands';
/**
 * Font Awesome 아이콘 크기
 *
 * `Icon` 이 `fa-{size}` 로 그대로 방출하므로 값 집합은 Font Awesome 어휘다(Tailwind 아님).
 * 상대 스케일 `xs`~`2xl` 과 배수 스케일 `2x`~`5x` 가 함께 있다.
 *
 * 이 타입이 레이아웃 작성 `<icon size>` 의 SSoT 이며 audit 레지스트리
 * (`layout-schema/component-registry.cjs` 의 `Icon.enums.size`)가 이 목록과 대조된다 —
 * 값을 늘리거나 줄이면 `icon-size-registry-sync` 가드가 두 하네스에서 실패한다.
 */
export type IconSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '2x' | '3x' | '4x' | '5x';
/**
 * 자주 사용되는 Font Awesome 아이콘 이름
 *
 * @example
 * // 사용 예시
 * <Icon name="user" />
 * <Icon name="search" />
 * <Icon name="shopping-cart" />
 */
export declare enum IconName {
    User = "fa-user",
    Users = "fa-users",
    UserCircle = "fa-user-circle",
    UserPlus = "fa-user-plus",
    UserMinus = "fa-user-minus",
    UserShield = "fa-user-shield",
    Search = "fa-search",
    Edit = "fa-edit",
    Pencil = "fa-pencil-alt",
    Trash = "fa-trash",
    Save = "fa-save",
    Plus = "fa-plus",
    Minus = "fa-minus",
    Check = "fa-check",
    Times = "fa-times",
    Download = "fa-download",
    Upload = "fa-upload",
    Copy = "fa-copy",
    ExternalLink = "fa-external-link-alt",
    Home = "fa-home",
    CaretRight = "fa-caret-right",
    CaretDown = "fa-caret-down",
    ChevronLeft = "fa-chevron-left",
    ChevronRight = "fa-chevron-right",
    ChevronUp = "fa-chevron-up",
    ChevronDown = "fa-chevron-down",
    AngleLeft = "fa-angle-left",
    AngleRight = "fa-angle-right",
    AngleUp = "fa-angle-up",
    AngleDown = "fa-angle-down",
    ArrowUp = "fa-arrow-up",
    ArrowDown = "fa-arrow-down",
    ArrowLeft = "fa-arrow-left",
    ArrowRight = "fa-arrow-right",
    Bars = "fa-bars",
    EllipsisVertical = "fa-ellipsis-v",
    EllipsisHorizontal = "fa-ellipsis-h",
    CheckCircle = "fa-check-circle",
    TimesCircle = "fa-times-circle",
    ExclamationCircle = "fa-exclamation-circle",
    ExclamationTriangle = "fa-exclamation-triangle",
    InfoCircle = "fa-info-circle",
    QuestionCircle = "fa-question-circle",
    XCircle = "fa-times-circle",
    ShoppingCart = "fa-shopping-cart",
    ShoppingBag = "fa-shopping-bag",
    CreditCard = "fa-credit-card",
    Tag = "fa-tag",
    Tags = "fa-tags",
    File = "fa-file",
    FileAlt = "fa-file-alt",
    Folder = "fa-folder",
    FolderOpen = "fa-folder-open",
    Envelope = "fa-envelope",
    Phone = "fa-phone",
    Comment = "fa-comment",
    Comments = "fa-comments",
    Cog = "fa-cog",
    Cogs = "fa-cogs",
    Settings = "fa-cog",
    Wrench = "fa-wrench",
    Sliders = "fa-sliders-h",
    Image = "fa-image",
    Images = "fa-images",
    Video = "fa-video",
    Camera = "fa-camera",
    Heart = "fa-heart",
    Star = "fa-star",
    ThumbsUp = "fa-thumbs-up",
    ThumbsDown = "fa-thumbs-down",
    Share = "fa-share",
    Clock = "fa-clock",
    Calendar = "fa-calendar",
    CalendarAlt = "fa-calendar-alt",
    Lock = "fa-lock",
    Unlock = "fa-unlock",
    Eye = "fa-eye",
    EyeSlash = "fa-eye-slash",
    Bell = "fa-bell",
    Flag = "fa-flag",
    Map = "fa-map",
    MapMarker = "fa-map-marker-alt",
    Globe = "fa-globe",
    Link = "fa-link",
    Unlink = "fa-unlink",
    Sun = "fa-sun",
    Moon = "fa-moon",
    ChartBar = "fa-chart-bar",
    ChartLine = "fa-chart-line",
    ChartPie = "fa-chart-pie",
    ChartArea = "fa-chart-area",
    Spinner = "fa-spinner",
    CircleNotch = "fa-circle-notch",
    CheckBadge = "fa-badge-check",
    Cube = "fa-cube",
    Cubes = "fa-cubes",
    Plug = "fa-plug",
    PuzzlePiece = "fa-puzzle-piece",
    Palette = "fa-palette",
    FileLines = "fa-file-lines",
    MoneyBill = "fa-money-bill",
    Pause = "fa-pause",
    Ban = "fa-ban",
    BoxOpen = "fa-box-open",
    Truck = "fa-truck",
    Warehouse = "fa-warehouse",
    Box = "fa-box",
    TruckFast = "fa-truck-fast",
    House = "fa-house",
    CircleCheck = "fa-circle-check",
    RotateLeft = "fa-rotate-left",
    HourglassHalf = "fa-hourglass-half",
    BoxesPacking = "fa-boxes-packing",
    RightLeft = "fa-right-left",
    Reply = "fa-reply",
    ArrowsRotate = "fa-arrows-rotate",
    BoxArchive = "fa-box-archive",
    CircleXmark = "fa-circle-xmark"
}
/**
 * 문자열 아이콘 이름을 IconName enum으로 매핑합니다.
 * JSON 레이아웃에서 문자열로 아이콘 이름을 지정할 때 사용합니다.
 */
export declare const iconNameMap: Record<string, IconName>;
