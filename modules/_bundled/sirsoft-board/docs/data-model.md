# 게시판 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `Attachment` | `board_attachments` | 16 | board→Board, post→Post, creator→User | SoftDeletes |
| `Board` | `boards` | 44 | creator→User, updater→User, posts→Post, comments→Comment, attachments→Attachment, reports→Report | - |
| `BoardStat` | `board_stats` | 3 | - | - |
| `BoardType` | `board_types` | 3 | - | HasUserOverrides |
| `Comment` | `board_comments` | 15 | board→Board, post→Post, user→User, parent→self, replies→self | SoftDeletes |
| `Post` | `board_posts` | 23 | board→Board, user→User, parent→self, replies→self, comments→Comment, attachments→Attachment, 외 2개 | SoftDeletes, 검색 색인 |
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

`board_attachments.guest_owner_hash`(1.2.3)는 비회원 업로드 소유 쿠키(`g7_board_guest_upload`) 값의 SHA-256 입니다. 비회원이
글에 연결되기 전의 자기 임시 첨부를 지우고 순서를 바꿀 때만 쓰고, 연결되면 지웁니다. 모델 `$hidden` 이라 응답에 실리지 않습니다.
개인정보 동의 플러그인은 기능 쿠키 미동의 방문자의 응답에서 허용목록 밖 쿠키를 걷어내므로, 이 쿠키 이름은
`ProvideNecessaryStorageKeysListener` 가 `cookie` 스코프 필수 키로 제공합니다(빠지면 비회원 본인 삭제가 403).
`collection='content_media'`(예약, `Attachment::RESERVED_COLLECTIONS`)는 일반 첨부 업로드로 쓸 수 없고 첨부 개수 상한에 세지 않습니다.
본문 미디어(`collection='content_media'`, 1.2.3)는 편집기 본문에 넣은 이미지·동영상·오디오입니다. 파일은 처음부터 최종 경로
`{slug}/content-media/Y/m/d/{uuid}.ext` 에 두고, 행은 글에 잇기 전까지 `board_id=0` + `temp_key` 입니다(임시 첨부의 `{slug}/temp/` 범위 밖이라
임시 키·번호 연결이 집어 가지 않습니다). 글 저장 트랜잭션 안에서 `PostContentMediaService::syncWithContent` 가 본문이 참조한 것만 DB 갱신으로
잇고, 본문에서 빠진 것은 소프트 삭제(`trigger_type` user|admin)합니다. `Post::attachments()`·`thumbnailAttachment()`·`attachments_count` 는
본문 미디어를 빼고, `Post::contentMedia()` 가 따로 봅니다. 게시글 삭제 연쇄·보유기간 파기는 첨부와 같이 함께 처리됩니다. 끝내 잇지 않은 임시 행은
`sirsoft-board:prune-attachments` 의 임시 정리가 지웁니다. 본문 미디어 행을 영구 삭제하는 경로(임시 정리·소프트 삭제 정리·보유기간 파기, 게시판 영구 삭제,
문의 샘플 글 영구 삭제)는 코어 본문 미디어 골격을 거쳐 행마다 `core.content_media.before_delete` 를 한 번 발화합니다(일반 첨부의 삭제·훅은 그대로).
흐름(업로드·연결 → 복제 → 제거·서명·서빙·정리)은 코어 골격 `AbstractContentMediaService` 가 돌리고, 게시판은 어댑터
`Services\ContentMedia\BoardContentMediaStore`(이 표의 행 모양·저장 경로·권한)와 골격 상속 `Services\ContentMedia\BoardContentMediaService`
를 둡니다. `PostContentMediaService` 는 게시판 호출자가 쓰던 시그니처를 지키는 위임 계층입니다(1.2.3, 코어 7.1.5 #887).
본문이 **다른 글**(다른 게시판 글 포함)의 본문 미디어 주소(답글 인용·복사·붙여넣기)나 요청자 본인이 다른 임시 키로 올린 임시 행(회원은 본인 업로드,
비회원은 업로드 소유 쿠키 해시가 맞을 때)을 쓰면, 저장하는 사람이 원본을 서명 없이 받을 수 있을 때(원본 글이 속한 게시판의 글 읽기 권한 또는 게시판
관리 + 삭제·비밀·블라인드 게이트)만 파일을 복사해 이 글 소유의 새 행·새 해시를 만들고 본문 주소를 이 게시판 주소로 바꿔 다시 저장합니다(#887).
볼 수 없으면 주소를 그대로 두고, 글 응답(`PostResource`)은 그 글 소유 해시만 서명하므로 그 미디어는 원본 글의 게이트를 따릅니다(관리자 증거 열람인
신고 상세만 모두 서명). 복제할 것도 문서당 최대 개수에 듭니다. 복제 재저장이 있어도 글 저장은 한 번으로 기록됩니다(after_* 1회·활동 로그 1건).

`boards.content_media_policy`(json, 1.2.3)는 게시판별 본문 미디어 정책 `{image|video|audio: {enabled, max_size_mb, extensions[]}, max_per_document}`
입니다. null 이면 그 게시판 첨부 정책(파일당 크기·허용 확장자 중 이미지)으로 만든 대체 정책을 씁니다 — 동영상·오디오는 코어 「본문 콘텐츠」 스위치를 따릅니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 48개.

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
| `2026_10_15_000001_add_secret_reply_visibility_to_boards_table.php` | - | `boards` | ✅ |
| `2026_10_19_000001_add_display_order_to_boards_table.php` | - | `boards` | ✅ |
| `2026_11_09_000001_add_guest_owner_hash_to_board_attachments_table.php` | - | `board_attachments` | ✅ |
| `2026_11_10_000001_add_content_media_policy_to_boards_table.php` | - | `boards` | ✅ |
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
| `SecretReplyVisibility` | `string` | 3 | `thread_path`, `descendants_only`, `per_post` |
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

게시글·댓글의 처리 이력(`action_logs` JSON)의 `created_at` 은 **오프셋 없는 UTC 문자열**로 저장합니다(신고 처리 이력과 같은
관례). 화면에 내보낼 때는 `PostResource`·`CommentResource` 가 `Carbon::parse($v, 'UTC')` 로 읽어 보는 사람 시간대로
바꿉니다 — 저장 형식을 바꾸면 기존 행과 섞이므로 저장 쪽은 그대로 두고 표시 쪽에서만 변환합니다.
<!-- @intent END -->
