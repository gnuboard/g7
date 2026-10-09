# 게시판 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `Attachment` | `board_attachments` | 15 | board→Board, post→Post, creator→User | SoftDeletes |
| `Board` | `boards` | 41 | creator→User, updater→User, posts→Post, comments→Comment, attachments→Attachment, reports→Report | - |
| `BoardStat` | `board_stats` | 3 | - | - |
| `BoardType` | `board_types` | 3 | - | HasUserOverrides |
| `Comment` | `board_comments` | 15 | board→Board, post→Post, user→User, parent→self, replies→self | SoftDeletes |
| `Post` | `board_posts` | 23 | board→Board, user→User, parent→self, replies→self, comments→Comment, attachments→Attachment, 외 1개 | SoftDeletes, 검색 색인 |
| `PostScrap` | `board_post_scraps` | 3 | user→User, post→Post, board→Board | - |
| `Reaction` | `board_reactions` | 5 | user→User, reactionType→ReactionType, board→Board, post→Post, comment→Comment | - |
| `ReactionType` | `board_reaction_types` | 7 | iconAttachment→Attachment, reactions→Reaction | HasUserOverrides |
| `Report` | `boards_reports` | 11 | board→Board, author→User, logs→ReportLog, processor→User | SoftDeletes |
| `ReportLog` | `boards_report_logs` | 6 | report→Report, reporter→User | - |
| `UserNotificationSetting` | `board_user_notification_settings` | 5 | user→User | - |
<!-- @generated:models END -->

<!-- @intent START -->
`Post`·`Comment` 모두 `parent`/`replies` 자기참조 관계를 갖습니다 — `Post` 의 자기참조는
"답변형 게시판"(원글에 대한 관리자 답변)을, `Comment` 의 자기참조는 대댓글을 표현합니다. 둘은
서로 다른 기능이라 관계 이름은 같아도 코드에서 섞어 쓰지 않습니다. `Board` 는 `HasUserOverrides`
를 쓰지 **않습니다** — 게시판 자체는 운영자가 직접 소유·수정하는 리소스라 "모듈 재설치 시
운영자 수정 보존"이 필요 없는 반면, `BoardType`(게시판 유형 프리셋)은 모듈이 시딩한 기본값을
운영자가 손댈 수 있어야 하므로 그 트레이트를 씁니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `board_attachments` | `Attachment` |
| `board_comments` | `Comment` |
| `board_mail_templates` | - |
| `board_post_scraps` | `PostScrap` |
| `board_posts` | `Post` |
| `board_reaction_types` | `ReactionType` |
| `board_reactions` | `Reaction` |
| `board_stats` | `BoardStat` |
| `board_types` | `BoardType` |
| `board_user_notification_settings` | `UserNotificationSetting` |
| `boards` | `Board` |
| `boards_report_logs` | `ReportLog` |
| `boards_reports` | `Report` |
<!-- @generated:tables END -->

<!-- @intent START -->
`board_mail_templates` 는 모델이 없는 채로 남아 있습니다 — 아래 마이그레이션 표의
`drop_board_mail_templates_table`(2026-04-13)이 보여주듯, 메일 템플릿을 자체 테이블로
관리하던 초기 설계를 코어 `GenericNotification` 알림 정의(§알림 정의)로 이관하며 테이블만
드롭하고 이름은 이력상 남아 있는 상태입니다. 신규 코드에서 이 이름을 참조하지 않습니다.
테이블 접두어가 `board_*`와 `boards_*` 두 가지로 섞여 있는 것은 설계 의도가 아니라 이력입니다
— 새 테이블을 추가할 때는 `board_*`(단수)를 따릅니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 44개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_04_01_000001_create_board_types_table.php` | `board_types` | - | ✅ |
| `2026_04_01_000002_create_boards_table.php` | `boards` | `boards` | ✅ |
| `2026_04_01_000003_create_board_user_notification_settings_table.php` | `board_user_notification_settings` | `board_user_notification_settings` | ✅ |
| `2026_04_01_000004_create_board_posts_table.php` | `board_posts` | - | ✅ |
| `2026_04_01_000005_create_board_comments_table.php` | `board_comments` | - | ✅ |
| `2026_04_01_000006_create_board_attachments_table.php` | `board_attachments` | - | ✅ |
| `2026_04_01_000007_create_boards_reports_table.php` | `boards_reports` | `boards_reports` | ✅ |
| `2026_04_01_000008_create_boards_report_logs_table.php` | `boards_report_logs` | `boards_report_logs` | ✅ |
| `2026_04_01_000009_create_board_mail_templates_table.php` | `board_mail_templates` | - | ✅ |
| `2026_04_01_000010_add_fulltext_indexes_to_boards_table.php` | - | `boards` | ✅ |
| `2026_04_01_000011_add_fulltext_indexes_to_boards_report_logs_table.php` | - | `boards_report_logs` | ✅ |
| `2026_04_01_000012_add_indexes_to_board_posts_table.php` | - | `board_posts` | ✅ |
| `2026_04_13_000001_drop_board_mail_templates_table.php` | `board_mail_templates` | - | ✅ |
| `2026_04_13_000002_add_user_overrides_to_board_types_table.php` | - | `board_types` | ✅ |
| `2026_04_14_000001_drop_channel_columns_from_boards_table.php` | - | `boards` | ✅ |
| `2026_04_17_000001_remove_partitions_from_board_tables.php` | - | - | ✅ |
| `2026_04_17_000002_add_count_columns_to_board_tables.php` | - | `board_posts`, `board_comments` | ✅ |
| `2026_04_17_000003_add_posts_count_and_comments_count_to_boards_table.php` | - | `boards` | ✅ |
| `2026_04_17_000004_update_indexes_in_board_tables.php` | - | `board_posts`, `board_comments`, `board_attachments` | ✅ |
| `2026_05_29_000001_create_board_stats_table.php` | `board_stats` | - | ✅ |
| `2026_06_08_000001_add_recent_across_boards_index_to_board_posts.php` | - | `board_posts` | ✅ |
| `2026_06_26_000001_modify_trigger_type_in_board_comments_table.php` | - | - | ✅ |
| `2026_06_26_000002_add_trigger_type_to_board_attachments_table.php` | - | `board_attachments` | ✅ |
| `2026_08_01_000001_add_tiebreak_to_board_posts_list_index.php` | - | - | ✅ |
| `2026_08_01_000002_add_list_sort_index_to_boards_reports_table.php` | - | - | ✅ |
| `2026_08_04_000001_create_board_reaction_types_table.php` | `board_reaction_types` | `board_reaction_types` | ✅ |
| `2026_08_04_000002_create_board_reactions_table.php` | `board_reactions` | `board_reactions` | ✅ |
| `2026_08_04_000003_add_use_reaction_to_boards_table.php` | - | `boards` | ✅ |
| `2026_08_04_000004_add_active_reaction_types_to_boards_table.php` | - | `boards` | ✅ |
| `2026_08_04_000005_add_reaction_counts_to_board_posts_table.php` | - | `board_posts` | ✅ |
| `2026_08_06_000001_update_max_reply_depth_comment_in_boards_table.php` | - | - | ✅ |
| `2026_08_06_000002_add_view_count_sort_index_to_board_posts_table.php` | - | - | ✅ |
| `2026_08_10_000001_create_board_post_scraps_table.php` | `board_post_scraps` | `board_post_scraps` | ✅ |
| `2026_08_10_000002_add_use_scrap_to_boards_table.php` | - | `boards` | ✅ |
| `2026_08_10_000003_add_scrap_count_to_board_posts_table.php` | - | `board_posts` | ✅ |
| `2026_08_17_000001_add_reply_delete_policy_to_boards_table.php` | - | `boards` | ✅ |
| `2026_08_17_000002_modify_trigger_type_in_board_posts_table.php` | - | - | ✅ |
| `2026_08_22_000001_add_content_thumbnail_url_to_board_posts_table.php` | - | `board_posts` | ✅ |
| `2026_09_14_000001_add_admin_indexes_to_board_post_scraps_table.php` | - | `board_post_scraps` | ✅ |
| `2026_09_15_000001_add_icon_attachment_id_to_board_reaction_types_table.php` | - | `board_reaction_types` | ✅ |
| `2026_09_15_000002_add_reaction_counts_to_board_comments_table.php` | - | `board_comments` | ✅ |
| `2026_09_15_000003_add_use_comment_reaction_to_boards_table.php` | - | `boards` | ✅ |
| `2026_09_15_000004_add_admin_indexes_to_board_reactions_table.php` | - | `board_reactions` | ✅ |
| `2026_09_23_000001_make_ip_address_nullable_in_board_posts_table.php` | - | `board_posts` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
목록 성능 마이그레이션이 지속적으로 추가되는 것(인덱스 4건 + 정렬 타이브레이크 2건)은 우연이
아닙니다 — 게시글 목록은 공지 제외·답변 제외 필터가 항상 걸린 채로 `created_at`/`view_count`
2가지 정렬을 지원해야 하므로, 새 정렬 옵션을 추가할 때마다 그 정렬에 맞는 복합 인덱스를 함께
마이그레이션합니다(`getBenchmarkProfiles()` 의 `board_posts_by_view_count` 프로파일이 바로 이
목적으로 존재합니다). `remove_partitions_from_board_tables`(2026-04-17)는 `board_posts`/`board_comments`/
`board_attachments` 3개 테이블에 적용했던 파티셔닝을 되돌린 이력입니다 — 그 마이그레이션의
`down()` 은 "파티션 복원은 데이터 재배치가 필요해 자동 롤백 불가"라고 명시하므로, 파티셔닝을
다시 도입할 때는 이 파일을 그대로 재실행하는 방식이 아니라 새 마이그레이션으로 설계해야 합니다.

`make_ip_address_nullable_in_board_posts_table`(2026-09-23, 이슈 #636)은 `board_posts.ip_address` 를
NOT NULL → nullable 로 바꿔 `board_comments.ip_address`(처음부터 nullable)와 맞춘 것입니다 —
개인정보 보유기간이 지난 작성 IP 를 NULL 로 파기하려면 필요합니다. 이커머스 상품문의 연동이
NOT NULL 을 채우려고 넣던 자리표시값 `'0.0.0.0'` 을 NULL 로 되돌리는 기존 행 정리는 마이그레이션이
아니라 1.2.0 업그레이드 스텝(`upgrades/data/1.2.0/migrations/01_NormalizePlaceholderPostIp.php`)이
맡고(스키마 변경과 데이터 변환을 나눈다 — 확장 갱신은 마이그레이션 → 업그레이드 스텝 순서), 연동
리스너(`EcommerceInquiryHookListener`)도 이제 IP 를 모르면 NULL 로 둡니다. 따라서 게시글·댓글
응답의 `ip_address` 는 **nullable 문자열**이고, 관리자 화면(`_post_card_content`·`_comment`·
`_reply_card_content`)은 이미 `data-if` 로 빈 값을 숨깁니다. `down()` 은 NULL 행을
`'0.0.0.0'` 으로 채운 뒤 NOT NULL 로 되돌립니다(파기된 IP 는 되살릴 수 없으므로 「모름」 값).

인덱스만 더하는 마이그레이션 2건(`add_recent_across_boards_index_to_board_posts` ·
`add_trigger_type_to_board_attachments_table` 의 인덱스 단계)은 `Schema::hasIndex()` 가드 뒤에서 실행됩니다.
MySQL 은 DDL 을 롤백하지 않으므로 한 파일이 컬럼 → 인덱스 순으로 진행하다 인덱스 단계에서 죽으면 컬럼만 남고,
재실행 시 컬럼 가드는 통과하지만 인덱스가 `Duplicate key name` 으로 다시 죽어 그 설치본은 재실행으로 복구되지
않습니다. 새 DDL 단계를 더할 때는 컬럼뿐 아니라 인덱스·외래키 단계에도 각각 가드를 겁니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| Enum | backing | case 수 | case |
|---|---|---|---|
| `BoardOrderBy` | `string` | 4 | `created_at`, `view_count`, `title`, `author` |
| `OrderDirection` | `string` | 2 | `ASC`, `DESC` |
| `PostStatus` | `string` | 3 | `published`, `blinded`, `deleted` |
| `ReplyDeletePolicy` | `string` | 2 | `block`, `cascade` |
| `ReportReasonType` | `string` | 9 | `abuse`, `hate_speech`, `spam`, `copyright`, `privacy`, `misinformation`, `sexual`, `violence`, `외 1개` |
| `ReportStatus` | `string` | 5 | `pending`, `review`, `rejected`, `suspended`, `deleted` |
| `ReportType` | `string` | 2 | `post`, `comment` |
| `SecretMode` | `string` | 3 | `disabled`, `enabled`, `always` |
| `TriggerType` | `string` | 6 | `report`, `admin`, `system`, `auto_hide`, `user`, `cascade` |
<!-- @generated:enums END -->

<!-- @intent START -->
`TriggerType`(6 case: report/admin/system/auto_hide/user/cascade)은 "이 콘텐츠가 왜 지금
상태가 됐는가"를 기록하는 감사(audit) 축입니다 — 예를 들어 게시글 블라인드가 `report`(신고
처리 결과)인지 `admin`(관리자 직접 조치)인지에 따라 `post_action`/`report_action` 두 알림이
갈라집니다(§확장점 "알림 정의" 참고). `cascade` 는 부모(게시글)가 지워질 때 자식(댓글)이
함께 지워진 경우이며, `ReplyDeletePolicy`(`block`/`cascade`)가 게시판별로 부모 삭제 시
자식을 막을지 함께 지울지를 결정합니다 — 이 정책과 `TriggerType::Cascade` 는 같은 흐름의
서로 다른 절반(정책 설정 vs 결과 기록)입니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `AttachmentRepository` | 구현 | 게시판 첨부파일 Repository 구현체 |
| `AttachmentRepositoryInterface` | 인터페이스 | 게시판 첨부파일 Repository 인터페이스 |
| `BoardRepository` | 구현 | 게시판 Repository |
| `BoardRepositoryInterface` | 인터페이스 | 게시판 Repository 인터페이스 |
| `BoardStatRepository` | 구현 | 게시판 일별 집계 Repository |
| `BoardStatRepositoryInterface` | 인터페이스 | 게시판 일별 집계 Repository 인터페이스 |
| `BoardTypeRepository` | 구현 | - |
| `BoardTypeRepositoryInterface` | 인터페이스 | - |
| `CommentRepository` | 구현 | 댓글 Repository |
| `CommentRepositoryInterface` | 인터페이스 | 댓글 Repository 인터페이스 |
| `PostRepository` | 구현 | 게시글 Repository |
| `PostRepositoryInterface` | 인터페이스 | 게시글 Repository 인터페이스 |
| `PostScrapRepository` | 구현 | 게시글 스크랩 Repository 구현체 |
| `PostScrapRepositoryInterface` | 인터페이스 | 게시글 스크랩 Repository 인터페이스 |
| `ReactionRepository` | 구현 | 반응 이력 Repository |
| `ReactionRepositoryInterface` | 인터페이스 | 반응 이력 Repository 인터페이스 |
| `ReactionTypeRepository` | 구현 | 반응 유형 Repository |
| `ReactionTypeRepositoryInterface` | 인터페이스 | 반응 유형 Repository 인터페이스 |
| `ReportRepository` | 구현 | 신고 Repository |
| `ReportRepositoryInterface` | 인터페이스 | 신고 Repository 인터페이스 |
| `UserNotificationSettingRepository` | 구현 | 사용자 알림 설정 Repository |
| `UserNotificationSettingRepositoryInterface` | 인터페이스 | 사용자 알림 설정 Repository 인터페이스 |
<!-- @generated:repositories END -->

<!-- @intent START -->
`BoardStatRepository`(일별 집계)는 별도 Repository 로 분리돼 있습니다 — `sirsoft-board:aggregate-stats`
스케줄이 매시간 `board_stats` 를 갱신하는데, 이 집계 쿼리를 `PostRepository`/`CommentRepository`
에 섞으면 대시보드 조회 경로와 실시간 CRUD 경로가 같은 클래스 안에서 뒤엉킵니다. 새 Repository
를 추가할 때는 반드시 인터페이스를 함께 만들고 `CoreServiceProvider`(또는 이 모듈의
서비스 프로바이더)에서 바인딩합니다 — Service 가 구체 클래스를 직접 타입힌트하면 안 됩니다.

`board_stats.date`(대시보드 일 집계)의 「오늘」은 **사이트 표준시의 하루**입니다(`BoardDashboardService::siteToday()` —
`general.timezone` → `app.default_user_timezone`). 일일 신고 한도의 「오늘」은 **신고하는 사람의 계정 시간대**의
하루이고, 계정 시간대가 없으면 사이트 기본 시간대입니다(코어 `FiltersByDateRange::applyViewerTodayFilter`). 저장값과
앱 시간대(`app.timezone`)는 UTC 이므로, 하루를 앱 시간대로 자르면 한국 사이트에서는 오전 9시에 날짜가 바뀌어 새벽 글이
전날 집계로 가고 일일 신고 한도가 오전 9시에 풀립니다. 하루 단위로 세는 쿼리는 그 하루를 UTC 범위로 바꿔 비교합니다.
<!-- @intent END -->
