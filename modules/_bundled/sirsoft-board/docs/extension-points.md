# 게시판 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 105종 / 호출 지점 111곳. 이 중 105종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `core.module_settings.after_save` | action | — | `src/Http/Controllers/Admin/BoardSettingsController.php:181` |
| `sirsoft-board.attachment.after_delete` | action | — | `src/Services/AttachmentService.php:608` |
| `sirsoft-board.attachment.after_download` | action | — | `src/Services/AttachmentService.php:977` |
| `sirsoft-board.attachment.after_link` | action | — | `src/Services/AttachmentService.php:227` 외 1곳 |
| `sirsoft-board.attachment.after_reorder` | action | — | `src/Services/AttachmentService.php:796` |
| `sirsoft-board.attachment.after_upload` | action | — | `src/Services/AttachmentService.php:202` |
| `sirsoft-board.attachment.before_delete` | action | — | `src/Services/AttachmentService.php:587` |
| `sirsoft-board.attachment.before_reorder` | action | — | `src/Services/AttachmentService.php:791` |
| `sirsoft-board.attachment.before_upload` | action | — | `src/Services/AttachmentService.php:127` |
| `sirsoft-board.attachment.filter_upload_file` | filter | — | `src/Services/AttachmentService.php:130` |
| `sirsoft-board.attachment.reorder_validation_rules` | filter | — | `src/Http/Requests/ReorderAttachmentsRequest.php:40` |
| `sirsoft-board.attachment.upload_validation_rules` | filter | — | `src/Http/Requests/UploadAttachmentRequest.php:80` |
| `sirsoft-board.author_ip.after_prune` | action | — | `src/Privacy/BoardIpRetentionHandler.php:135` |
| `sirsoft-board.author_ip.before_prune` | action | — | `src/Privacy/BoardIpRetentionHandler.php:130` |
| `sirsoft-board.board.after_add_to_menu` | action | — | `src/Services/BoardService.php:1262` |
| `sirsoft-board.board.after_bulk_members` | action | — | `src/Services/BoardService.php:1696` |
| `sirsoft-board.board.after_create` | action | — | `src/Services/BoardService.php:483` |
| `sirsoft-board.board.after_delete` | action | — | `src/Services/BoardService.php:730` |
| `sirsoft-board.board.after_remove_from_menu` | action | — | `src/Services/BoardService.php:1307` |
| `sirsoft-board.board.after_update` | action | — | `src/Services/BoardService.php:647` |
| `sirsoft-board.board.before_add_to_menu` | action | — | `src/Services/BoardService.php:1226` |
| `sirsoft-board.board.before_bulk_members` | action | — | `src/Services/BoardService.php:1607` |
| `sirsoft-board.board.before_copy` | action | — | `src/Services/BoardService.php:753` |
| `sirsoft-board.board.before_create` | action | — | `src/Services/BoardService.php:452` |
| `sirsoft-board.board.before_delete` | action | — | `src/Services/BoardService.php:679` |
| `sirsoft-board.board.before_remove_from_menu` | action | — | `src/Services/BoardService.php:1293` |
| `sirsoft-board.board.before_update` | action | — | `src/Services/BoardService.php:589` |
| `sirsoft-board.board.bulk_members_validation_rules` | filter | — | `src/Http/Requests/Admin/BulkBoardMembersRequest.php:53` |
| `sirsoft-board.board.filter_copy_data` | filter | — | `src/Services/BoardService.php:810` |
| `sirsoft-board.board.filter_create_data` | filter | — | `src/Services/BoardService.php:455` |
| `sirsoft-board.board.filter_menu_data` | filter | — | `src/Services/BoardService.php:1256` |
| `sirsoft-board.board.filter_update_data` | filter | — | `src/Services/BoardService.php:594` |
| `sirsoft-board.board.posts.before_force_delete` | action | — | `src/Services/BoardService.php:700` |
| `sirsoft-board.board.store_validation_rules` | filter | — | `src/Http/Requests/StoreBoardRequest.php:251` |
| `sirsoft-board.board.update_validation_rules` | filter | — | `src/Http/Requests/UpdateBoardRequest.php:251` |
| `sirsoft-board.board_type.after_create` | action | — | `src/Services/BoardTypeService.php:42` |
| `sirsoft-board.board_type.after_delete` | action | — | `src/Services/BoardTypeService.php:107` |
| `sirsoft-board.board_type.after_update` | action | — | `src/Services/BoardTypeService.php:70` |
| `sirsoft-board.board_type.before_create` | action | — | `src/Services/BoardTypeService.php:36` |
| `sirsoft-board.board_type.before_delete` | action | — | `src/Services/BoardTypeService.php:103` |
| `sirsoft-board.board_type.before_update` | action | — | `src/Services/BoardTypeService.php:62` |
| `sirsoft-board.board_type.filter_create_data` | filter | — | `src/Services/BoardTypeService.php:38` |
| `sirsoft-board.board_type.filter_update_data` | filter | — | `src/Services/BoardTypeService.php:66` |
| `sirsoft-board.comment.after_blind` | action | — | `src/Services/CommentService.php:536` |
| `sirsoft-board.comment.after_create` | action | — | `src/Services/CommentService.php:395` |
| `sirsoft-board.comment.after_delete` | action | — | `src/Services/CommentService.php:493` |
| `sirsoft-board.comment.after_restore` | action | — | `src/Services/CommentService.php:579` |
| `sirsoft-board.comment.after_update` | action | — | `src/Services/CommentService.php:430` |
| `sirsoft-board.comment.before_blind` | action | — | `src/Services/CommentService.php:527` |
| `sirsoft-board.comment.before_create` | action | — | `src/Services/CommentService.php:361` |
| `sirsoft-board.comment.before_delete` | action | — | `src/Services/CommentService.php:451` |
| `sirsoft-board.comment.before_restore` | action | — | `src/Services/CommentService.php:570` |
| `sirsoft-board.comment.before_update` | action | — | `src/Services/CommentService.php:419` |
| `sirsoft-board.comment.filter_create_data` | filter | — | `src/Services/CommentService.php:364` |
| `sirsoft-board.comment.filter_update_data` | filter | — | `src/Services/CommentService.php:424` |
| `sirsoft-board.comment.store_validation_rules` | filter | — | `src/Http/Requests/StoreCommentRequest.php:97` |
| `sirsoft-board.comment.update_validation_rules` | filter | — | `src/Http/Requests/UpdateCommentRequest.php:71` |
| `sirsoft-board.guest_author.after_prune` | action | — | `src/Privacy/BoardGuestRetentionHandler.php:192` |
| `sirsoft-board.guest_author.before_prune` | action | — | `src/Privacy/BoardGuestRetentionHandler.php:186` |
| `sirsoft-board.permissions.after_create` | action | — | `src/Services/BoardService.php:543` |
| `sirsoft-board.permissions.after_delete` | action | — | `src/Services/BoardService.php:707` |
| `sirsoft-board.permissions.after_update` | action | — | `src/Services/BoardService.php:620` |
| `sirsoft-board.post.after_blind` | action | — | `src/Services/PostService.php:583` |
| `sirsoft-board.post.after_bulk_action` | action | — | `src/Services/PostService.php:737` |
| `sirsoft-board.post.after_create` | action | — | `src/Services/PostService.php:289` |
| `sirsoft-board.post.after_delete` | action | — | `src/Services/PostService.php:519` |
| `sirsoft-board.post.after_restore` | action | — | `src/Services/PostService.php:663` |
| `sirsoft-board.post.after_update` | action | — | `src/Services/PostService.php:365` |
| `sirsoft-board.post.before_blind` | action | — | `src/Services/PostService.php:558` |
| `sirsoft-board.post.before_bulk_action` | action | — | `src/Services/PostService.php:697` |
| `sirsoft-board.post.before_create` | action | — | `src/Services/PostService.php:264` |
| `sirsoft-board.post.before_delete` | action | — | `src/Services/PostService.php:440` |
| `sirsoft-board.post.before_restore` | action | — | `src/Services/PostService.php:616` |
| `sirsoft-board.post.before_update` | action | — | `src/Services/PostService.php:338` |
| `sirsoft-board.post.filter_content_thumbnail` | filter | — | `src/Models/Post.php:148` |
| `sirsoft-board.post.filter_create_data` | filter | — | `src/Services/PostService.php:267` |
| `sirsoft-board.post.filter_update_data` | filter | — | `src/Services/PostService.php:343` |
| `sirsoft-board.post.store_validation_rules` | filter | — | `src/Http/Requests/StorePostRequest.php:123` |
| `sirsoft-board.post.update_validation_rules` | filter | — | `src/Http/Requests/UpdatePostRequest.php:81` |
| `sirsoft-board.reaction.after_react` | action | — | `src/Services/ReactionService.php:403` 외 1곳 |
| `sirsoft-board.reaction.before_react` | action | — | `src/Services/ReactionService.php:356` |
| `sirsoft-board.report.after_blind_content` | action | — | `src/Services/ReportService.php:743` |
| `sirsoft-board.report.after_bulk_update_status` | action | — | `src/Services/ReportService.php:502` |
| `sirsoft-board.report.after_cancel` | action | — | `src/Services/ReportService.php:1643` |
| `sirsoft-board.report.after_create` | action | — | `src/Services/ReportService.php:302` |
| `sirsoft-board.report.after_delete` | action | — | `src/Services/ReportService.php:569` |
| `sirsoft-board.report.after_delete_content` | action | — | `src/Services/ReportService.php:916` |
| `sirsoft-board.report.after_restore_content` | action | — | `src/Services/ReportService.php:706` |
| `sirsoft-board.report.after_update_status` | action | — | `src/Services/ReportService.php:379` |
| `sirsoft-board.report.before_bulk_update_status` | action | — | `src/Services/ReportService.php:395` |
| `sirsoft-board.report.before_cancel` | action | — | `src/Services/ReportService.php:1617` |
| `sirsoft-board.report.before_create` | action | — | `src/Services/ReportService.php:212` |
| `sirsoft-board.report.before_delete` | action | — | `src/Services/ReportService.php:564` |
| `sirsoft-board.report.before_update_status` | action | — | `src/Services/ReportService.php:334` |
| `sirsoft-board.report.filter_create_data` | filter | — | `src/Services/ReportService.php:236` |
| `sirsoft-board.roles.after_create` | action | — | `src/Services/BoardService.php:523` |
| `sirsoft-board.roles.after_delete` | action | — | `src/Services/BoardService.php:711` |
| `sirsoft-board.scrap.after_toggle` | action | — | `src/Services/PostScrapService.php:69` 외 4곳 |
| `sirsoft-board.scrap.before_toggle` | action | — | `src/Services/PostScrapService.php:57` |
| `sirsoft-board.search.post.index_should_update` | filter | — | `src/Models/Post.php:298` |
| `sirsoft-board.settings.after_bulk_apply` | action | — | `src/Services/BoardService.php:1575` |
| `sirsoft-board.settings.after_bulk_apply_aborted` | action | — | `src/Services/BoardService.php:1566` |
| `sirsoft-board.settings.before_bulk_apply` | action | — | `src/Services/BoardService.php:1468` |
| `sirsoft-board.user_post.store_validation_rules` | filter | — | `src/Http/Requests/User/StorePostRequest.php:168` |
| `sirsoft-board.user_post.update_validation_rules` | filter | — | `src/Http/Requests/User/UpdatePostRequest.php:118` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
`{도메인}.{동사}` 이름 규칙 안에서 4개 도메인(board/post/comment/report)이 전부 같은 3단
패턴(`before_*` → `filter_*_data` → `after_*`)을 반복합니다. 새 검증 규칙을 게시판별로 다르게
걸고 싶으면 `*.store_validation_rules`/`update_validation_rules` filter 를, 저장 직전 데이터를
가공하고 싶으면 `filter_*_data` 를, 저장 완료 후 부가 작업(알림·외부 연동)을 붙이고 싶으면
`after_*` action 을 잡습니다 — `before_*` 에서 예외를 던지면 저장 자체를 막을 수 있습니다.

`sirsoft-board.post.filter_content_thumbnail`/`sirsoft-board.search.post.index_should_update`
두 필터는 Model(`Post.php`) 안에서 직접 발행되는 예외적인 자리입니다 — Service 를 거치지 않는
지연 평가(썸네일 추출, 검색 색인 갱신 여부 판단)라 Model 이 직접 훅을 겁니다.

개인정보 보유기간 핸들러 두 개(`src/Privacy/`, 이슈 #636)는 코어 prune 훅 규약(7.0.7)을 따라
처리 전후로 `sirsoft-board.author_ip.before_prune`(보존일) / `.after_prune`(보존일, 건수),
`sirsoft-board.guest_author.before_prune` / `.after_prune` 을 발행합니다. **`--dry-run` 에서는
발행하지 않습니다** — 리허설에서 리스너가 돌았다고 착각하지 않도록 실제 처리 때만 발행합니다.
비회원 삭제 모드는 게시판 삭제 경로를 지나므로 `sirsoft-board.post.after_delete`·
`sirsoft-board.comment.after_delete` 가 `trigger_type=system` 행에 대해 발화하지만
**`before_delete` 는 발화하지 않습니다**(무인 배치에 본인인증 정책이 걸리면 법정 파기가 멈춤).

`sirsoft-board.board.posts.before_force_delete` 는 게시판 영구 삭제 직전 1,000건 묶음마다
`($board, array $postIds, string $postType)` 로 발화합니다. 셋째 인자 `$postType` 은 글 모델 타입
(다형 참조 type)입니다 — 글을 다형 참조로 가리키는 확장(이커머스 문의 등)은 게시판 클래스 이름을 스스로
적지 말고 이 값으로 자기 참조를 가려 지웁니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.activity_log.filter_description_params` | filter | `ActivityLogDescriptionResolver` | `resolveDescriptionParams` | 10 |
| `core.module_settings.after_save` | action (미선언) | `SeoBoardSettingsCacheListener` | `onModuleSettingsSave` | 20 |
| `core.notification.filter_default_definitions` | filter | `BoardNotificationDataListener` | `contributeDefaultDefinitions` | 20 |
| `core.privacy.retention_handlers` | filter | `PrivacyRetentionHandlerListener` | `addBoardHandlers` | 20 |
| `core.search.build_response` | filter | `SearchPostsListener` | `buildPostsResponse` | 10 |
| `core.search.index_validation_rules` | filter | `SearchPostsListener` | `addValidationRules` | 10 |
| `core.search.results` | filter | `SearchPostsListener` | `searchPosts` | 10 |
| `core.user.after_create` | action (미선언) | `UserNotificationSettingsListener` | `afterCreate` | 10 |
| `core.user.create_validation_rules` | filter | `UserNotificationSettingsListener` | `addValidationRules` | 10 |
| `core.user.filter_create_data` | filter | `UserNotificationSettingsListener` | `filterCreateData` | 10 |
| `core.user.filter_resource_data` | filter | `UserNotificationSettingsListener` | `filterResourceData` | 10 |
| `core.user.filter_resource_data` | filter | `UserReactionInfoListener` | `injectReactionSummary` | 31 |
| `core.user.filter_resource_data` | filter | `UserScrapInfoListener` | `injectScrapSummary` | 30 |
| `core.user.filter_update_data` | filter | `UserNotificationSettingsListener` | `filterUpdateData` | 10 |
| `core.user.update_profile_validation_rules` | filter | `UserNotificationSettingsListener` | `addValidationRules` | 10 |
| `core.user.update_validation_rules` | filter | `UserNotificationSettingsListener` | `addValidationRules` | 10 |
| `sirsoft-board.attachment.after_delete` | action (미선언) | `BoardActivityLogListener` | `handleAttachmentAfterDelete` | 20 |
| `sirsoft-board.attachment.after_delete` | action (미선언) | `PostAttachmentCountSyncListener` | `syncAttachmentsCount` | 10 |
| `sirsoft-board.attachment.after_download` | action (미선언) | `BoardActivityLogListener` | `handleAttachmentAfterDownload` | 20 |
| `sirsoft-board.attachment.after_link` | action (미선언) | `PostAttachmentCountSyncListener` | `syncAttachmentsCount` | 10 |
| `sirsoft-board.attachment.after_upload` | action (미선언) | `BoardActivityLogListener` | `handleAttachmentAfterUpload` | 20 |
| `sirsoft-board.attachment.after_upload` | action (미선언) | `PostAttachmentCountSyncListener` | `syncAttachmentsCount` | 10 |
| `sirsoft-board.board.after_add_to_menu` | action (미선언) | `BoardActivityLogListener` | `handleBoardAfterAddToMenu` | 20 |
| `sirsoft-board.board.after_create` | action (미선언) | `BoardActivityLogListener` | `handleBoardAfterCreate` | 20 |
| `sirsoft-board.board.after_delete` | action (미선언) | `BoardActivityLogListener` | `handleBoardAfterDelete` | 20 |
| `sirsoft-board.board.after_delete` | action (미선언) | `SeoBoardCacheListener` | `onBoardDelete` | 20 |
| `sirsoft-board.board.after_remove_from_menu` | action (미선언) | `BoardActivityLogListener` | `handleBoardAfterRemoveFromMenu` | 20 |
| `sirsoft-board.board.after_update` | action (미선언) | `BoardActivityLogListener` | `handleBoardAfterUpdate` | 20 |
| `sirsoft-board.board.after_update` | action (미선언) | `SeoBoardCacheListener` | `onBoardUpdate` | 20 |
| `sirsoft-board.board.posts.before_force_delete` | action (미선언) | `SeoBoardCacheListener` | `onBoardPostsForceDelete` | 20 |
| `sirsoft-board.board_type.after_create` | action (미선언) | `BoardActivityLogListener` | `handleBoardTypeAfterCreate` | 20 |
| `sirsoft-board.board_type.after_delete` | action (미선언) | `BoardActivityLogListener` | `handleBoardTypeAfterDelete` | 20 |
| `sirsoft-board.board_type.after_update` | action (미선언) | `BoardActivityLogListener` | `handleBoardTypeAfterUpdate` | 20 |
| `sirsoft-board.comment.after_blind` | action (미선언) | `BoardActivityLogListener` | `handleCommentAfterBlind` | 20 |
| `sirsoft-board.comment.after_blind` | action (미선언) | `SeoBoardCacheListener` | `onCommentChange` | 20 |
| `sirsoft-board.comment.after_create` | action (미선언) | `BoardActivityLogListener` | `handleCommentAfterCreate` | 20 |
| `sirsoft-board.comment.after_create` | action (미선언) | `BoardCommentsCountSyncListener` | `syncCommentsCount` | 10 |
| `sirsoft-board.comment.after_create` | action (미선언) | `CommentReplySyncListener` | `syncRepliesCount` | 10 |
| `sirsoft-board.comment.after_create` | action (미선언) | `PostCountSyncListener` | `syncCommentsCount` | 10 |
| `sirsoft-board.comment.after_create` | action (미선언) | `SeoBoardCacheListener` | `onCommentChange` | 20 |
| `sirsoft-board.comment.after_delete` | action (미선언) | `BoardActivityLogListener` | `handleCommentAfterDelete` | 20 |
| `sirsoft-board.comment.after_delete` | action (미선언) | `BoardCommentsCountSyncListener` | `syncCommentsCount` | 10 |
| `sirsoft-board.comment.after_delete` | action (미선언) | `CommentReplySyncListener` | `syncRepliesCount` | 10 |
| `sirsoft-board.comment.after_delete` | action (미선언) | `PostCountSyncListener` | `syncCommentsCount` | 10 |
| `sirsoft-board.comment.after_delete` | action (미선언) | `SeoBoardCacheListener` | `onCommentChange` | 20 |
| `sirsoft-board.comment.after_restore` | action (미선언) | `BoardActivityLogListener` | `handleCommentAfterRestore` | 20 |
| `sirsoft-board.comment.after_restore` | action (미선언) | `BoardCommentsCountSyncListener` | `syncCommentsCount` | 10 |
| `sirsoft-board.comment.after_restore` | action (미선언) | `CommentReplySyncListener` | `syncRepliesCount` | 10 |
| `sirsoft-board.comment.after_restore` | action (미선언) | `PostCountSyncListener` | `syncCommentsCount` | 10 |
| `sirsoft-board.comment.after_restore` | action (미선언) | `SeoBoardCacheListener` | `onCommentChange` | 20 |
| `sirsoft-board.comment.after_update` | action (미선언) | `BoardActivityLogListener` | `handleCommentAfterUpdate` | 20 |
| `sirsoft-board.comment.after_update` | action (미선언) | `SeoBoardCacheListener` | `onCommentChange` | 20 |
| `sirsoft-board.notification.channels` | filter | `BoardNotificationChannelListener` | `filterChannels` | 10 |
| `sirsoft-board.notification.extract_data` | filter | `BoardNotificationDataListener` | `extractData` | 20 |
| `sirsoft-board.notification.personalize_data` | filter | `BoardNotificationDataListener` | `personalizeBoardData` | 20 |
| `sirsoft-board.post.after_blind` | action (미선언) | `BoardActivityLogListener` | `handlePostAfterBlind` | 20 |
| `sirsoft-board.post.after_blind` | action (미선언) | `SeoBoardCacheListener` | `onPostUpdate` | 20 |
| `sirsoft-board.post.after_create` | action (미선언) | `BoardActivityLogListener` | `handlePostAfterCreate` | 20 |
| `sirsoft-board.post.after_create` | action (미선언) | `BoardPostsCountSyncListener` | `syncPostsCount` | 10 |
| `sirsoft-board.post.after_create` | action (미선언) | `PostReplySyncListener` | `syncRepliesCount` | 10 |
| `sirsoft-board.post.after_create` | action (미선언) | `SeoBoardCacheListener` | `onPostCreate` | 20 |
| `sirsoft-board.post.after_delete` | action (미선언) | `BoardActivityLogListener` | `handlePostAfterDelete` | 20 |
| `sirsoft-board.post.after_delete` | action (미선언) | `BoardPostsCountSyncListener` | `syncPostsCount` | 10 |
| `sirsoft-board.post.after_delete` | action (미선언) | `PostReplySyncListener` | `syncRepliesCount` | 10 |
| `sirsoft-board.post.after_delete` | action (미선언) | `SeoBoardCacheListener` | `onPostDelete` | 20 |
| `sirsoft-board.post.after_restore` | action (미선언) | `BoardActivityLogListener` | `handlePostAfterRestore` | 20 |
| `sirsoft-board.post.after_restore` | action (미선언) | `BoardPostsCountSyncListener` | `syncPostsCount` | 10 |
| `sirsoft-board.post.after_restore` | action (미선언) | `PostReplySyncListener` | `syncRepliesCount` | 10 |
| `sirsoft-board.post.after_restore` | action (미선언) | `SeoBoardCacheListener` | `onPostUpdate` | 20 |
| `sirsoft-board.post.after_update` | action (미선언) | `BoardActivityLogListener` | `handlePostAfterUpdate` | 20 |
| `sirsoft-board.post.after_update` | action (미선언) | `SeoBoardCacheListener` | `onPostUpdate` | 20 |
| `sirsoft-board.reaction.after_react` | action (미선언) | `BoardActivityLogListener` | `handleReactionAfterReact` | 20 |
| `sirsoft-board.report.after_blind_content` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterBlindContent` | 20 |
| `sirsoft-board.report.after_bulk_update_status` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterBulkUpdateStatus` | 20 |
| `sirsoft-board.report.after_cancel` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterCancel` | 20 |
| `sirsoft-board.report.after_create` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterCreate` | 20 |
| `sirsoft-board.report.after_delete` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterDelete` | 20 |
| `sirsoft-board.report.after_delete_content` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterDeleteContent` | 20 |
| `sirsoft-board.report.after_restore_content` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterRestoreContent` | 20 |
| `sirsoft-board.report.after_update_status` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterUpdateStatus` | 20 |
| `sirsoft-board.scrap.after_toggle` | action (미선언) | `BoardActivityLogListener` | `handleScrapAfterToggle` | 20 |
| `sirsoft-board.settings.after_bulk_apply` | action (미선언) | `BoardActivityLogListener` | `handleSettingsAfterBulkApply` | 20 |
| `sirsoft-board.settings.after_bulk_apply` | action (미선언) | `SeoBoardSettingsCacheListener` | `onBulkApply` | 20 |
| `sirsoft-board.settings.after_bulk_apply_aborted` | action (미선언) | `BoardActivityLogListener` | `handleSettingsAfterBulkApplyAborted` | 20 |
| `sirsoft-ckeditor5.image.filter_reference_sources` | filter | `Ckeditor5ReferenceSourcesListener` | `addBoardSources` | 10 |
| `sirsoft-ecommerce.inquiry.count_replies` | filter | `EcommerceInquiryHookListener` | `countReplies` | 10 |
| `sirsoft-ecommerce.inquiry.create` | filter | `EcommerceInquiryHookListener` | `createAndReturn` | 10 |
| `sirsoft-ecommerce.inquiry.delete` | filter | `EcommerceInquiryHookListener` | `deletePost` | 10 |
| `sirsoft-ecommerce.inquiry.delete_reply` | filter | `EcommerceInquiryHookListener` | `deleteReplyPost` | 10 |
| `sirsoft-ecommerce.inquiry.get_by_ids` | filter | `EcommerceInquiryHookListener` | `getByIds` | 10 |
| `sirsoft-ecommerce.inquiry.get_settings` | filter | `EcommerceInquiryHookListener` | `getBoardSettings` | 10 |
| `sirsoft-ecommerce.inquiry.purge_sample_posts` | filter | `EcommerceInquiryHookListener` | `purgeSamplePosts` | 10 |
| `sirsoft-ecommerce.inquiry.seed_sample_post` | filter | `EcommerceInquiryHookListener` | `seedSamplePost` | 10 |
| `sirsoft-ecommerce.inquiry.update` | filter | `EcommerceInquiryHookListener` | `updatePost` | 10 |
| `sirsoft-ecommerce.inquiry.update_reply` | filter | `EcommerceInquiryHookListener` | `updateReplyPost` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`core.user.*` 4종을 구독하는 이유는 회원가입/수정 화면에 "댓글 알림 수신 여부" 필드를 끼워
넣기 위해서입니다 — 이 필드는 `UserNotificationSetting` 모델(board 소유)에 저장되지만, 입력
자체는 코어 회원 폼에서 받습니다. `sirsoft-ckeditor5.image.filter_reference_sources` 구독은
board 글 본문(HTML 에디터)에 삽입된 이미지가 삭제 시 함께 정리되도록 참조 소스 목록에 게시글을
등록하는 자리입니다. `sirsoft-ecommerce.inquiry.*` 는 이커머스 "상품 문의"가 board 의
Post CRUD 를 재사용하는 위임 지점입니다 — 이커머스가 훅을 정의하고 board 가 답합니다. 운영 경로 8개
(`create`·`update`·`delete`·`update_reply`·`delete_reply`·`get_by_ids`·`get_settings`·`count_replies`)와
이커머스 샘플 시더 전용 2개(`seed_sample_post`·`purge_sample_posts`)입니다.

두 모듈은 서로를 의존성으로 선언하지 않으므로 **각자 자기 것만 씁니다.** board 가 훅에 답하다 실패하면
자기 문구(`sirsoft-board::messages.inquiry.*`)로 예외를 던지고, 이커머스는 그것을 자기 실패 응답으로
바꿉니다. 이커머스가 알아야 할 board 쪽 값(글 ID·글 모델 타입·게시판 설정·비밀글 열람 판정)은 전부 훅
반환값이나 인자로 건넵니다.

| 샘플 시더 훅 | 인자 | 반환 |
|---|---|---|
| `sirsoft-ecommerce.inquiry.seed_sample_post` | `(null, string $slug, array $question, ?array $reply)` — question: title·content·user_id·author_name·password(평문, board 가 해시)·is_secret·created_at / reply: content·user_id·created_at | `['post_id' => int, 'inquirable_type' => string]` · 게시판 없으면 이전 값 · 생성 뒤 게시판 글 수와 질문의 답변 수를 다시 센다 |
| `sirsoft-ecommerce.inquiry.purge_sample_posts` | `(0, string $slug, array<string, int[]> $inquirables)` — 타입별 글 ID 묶음 | 영구 삭제한 글 수 — 넘겨받은 자기 글 타입(Post) 글과 그 글의 답변만(같은 게시판 안). 그 글들의 댓글·첨부(저장소 파일 포함)·반응·스크랩도 지우고 게시판 글·댓글 수를 다시 센다. 운영자가 실제 문의에 단 답변은 남는다 |

`SeoBoardCacheListener` 가 `board.after_update`·`board.after_delete`·`board.posts.before_force_delete`
를 구독하는 것은 사이트맵 색인이 게시판(부모)과 게시글(자식)의 생명주기를 함께 따르게 하기
위해서입니다 — 게시판을 비활성화·삭제하면 게시글 URL 도 빠지고, 다시 활성화하면 공개 글만
1,000건 묶음(`SitemapIndexer::indexResources`)으로 재색인합니다. 글마다 색인하면 공개 글이 수만 건인
게시판에서 큐 작업 시간 제한을 넘겨 앞쪽 글만 색인된 채 실패합니다. 삭제 훅 둘은 삭제 트랜잭션과
원자적이도록 동기(sync) 실행입니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogDescriptionResolver` | 1개 | 명시 등록 | ✅ | `src/Listeners/ActivityLogDescriptionResolver.php` |
| `BoardActivityLogListener` | 33개 | 명시 등록 | ✅ | `src/Listeners/BoardActivityLogListener.php` |
| `BoardCommentsCountSyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/BoardCommentsCountSyncListener.php` |
| `BoardNotificationChannelListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/BoardNotificationChannelListener.php` |
| `BoardNotificationDataListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/BoardNotificationDataListener.php` |
| `BoardPostsCountSyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/BoardPostsCountSyncListener.php` |
| `Ckeditor5ReferenceSourcesListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Ckeditor5ReferenceSourcesListener.php` |
| `CommentReplySyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/CommentReplySyncListener.php` |
| `EcommerceInquiryHookListener` | 10개 | 명시 등록 | ✅ | `src/Listeners/EcommerceInquiryHookListener.php` |
| `PostAttachmentCountSyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/PostAttachmentCountSyncListener.php` |
| `PostCountSyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/PostCountSyncListener.php` |
| `PostReplySyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/PostReplySyncListener.php` |
| `PrivacyRetentionHandlerListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/PrivacyRetentionHandlerListener.php` |
| `SearchPostsListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/SearchPostsListener.php` |
| `SeoBoardCacheListener` | 13개 | 명시 등록 | ✅ | `src/Listeners/SeoBoardCacheListener.php` |
| `SeoBoardSettingsCacheListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/SeoBoardSettingsCacheListener.php` |
| `UserNotificationSettingsListener` | 7개 | 명시 등록 | ✅ | `src/Listeners/UserNotificationSettingsListener.php` |
| `UserReactionInfoListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/UserReactionInfoListener.php` |
| `UserScrapInfoListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/UserScrapInfoListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
`BoardActivityLogListener` 하나가 30개 훅을 구독하는 것이 의도된 형태입니다 — 활동 로그는
"무엇이 언제 왜 바뀌었는가"를 도메인 전체에서 일관된 형식으로 남겨야 하므로, 도메인별로
리스너를 쪼개면 로그 스키마가 갈라질 위험이 커집니다. 반대로 카운트 동기화(`*CountSyncListener`)
는 목적이 하나씩이라 도메인별로 쪼개져 있습니다 — 첨부 개수와 댓글 개수는 서로 독립적으로
실패해도 되므로, 한쪽이 예외를 던져도 다른 쪽 동기화는 영향받지 않습니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/admin-ecommerce-inquiry-settings.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `inquiry_board_setting` |
| `resources/extensions/admin-user-reactions-tab.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_detail` |
| `resources/extensions/admin-user-scraps-tab.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_detail` |
| `resources/extensions/admin_dashboard_community.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `admin_dashboard_community` |
| `resources/extensions/admin_dashboard_quick_menu.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `admin_dashboard_quick_menu` |
| `resources/extensions/user-notification-detail.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_detail` |
| `resources/extensions/user-notification-settings.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_form` |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
5개 조각 중 `admin-ecommerce-inquiry-settings.g7.html`·`admin_dashboard_community.g7.html`·
`admin_dashboard_quick_menu.g7.html` 은 board 자신의 화면이 아니라 **다른 확장(이커머스 문의
설정 화면, 관리자 대시보드)에** 게시판 관련 UI 를 끼워 넣는 조각입니다. 이 모듈이 다른 확장의
레이아웃을 코드로 알지 못한 채(레이아웃 확장 시스템을 통해서만) UI 를 주입한다는 뜻입니다.
나머지 2개(`user-notification-*`)는 코어 회원 알림 설정 화면에 board 알림 수신 옵션을
끼워 넣는 자리입니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
공개 API 라우트(`optional.sanctum`)와 관리자 API 라우트(코어 `auth`+권한 미들웨어)는 전부
코어가 이미 등록한 미들웨어로 충분합니다. board 만의 요청 전처리(예: 게시판별 rate limit)가
필요해지면 이 자리에 선언형으로 추가하되, 대상(targets)을 명시해 자기 라우트에만 부착합니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
실시간 갱신(새 댓글이 열려 있는 화면에 즉시 반영되는 등)은 이 모듈의 범위 밖입니다(§1 참고).
필요해지면 `sirsoft-board.{slug}.*` 채널을 신설하되, 게시판별로 채널을 분리해야 방문자가
관심 없는 다른 게시판의 이벤트까지 구독하지 않습니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 스케줄 | 주기 | 설명 |
|---|---|---|
| `sirsoft-board:aggregate-stats` | `hourly` | 대시보드 게시물 현황 집계 |
| `sirsoft-board:prune-attachments --scheduled` | `daily` | 방치된 임시 첨부 정리 + 보존기간 경과 삭제 첨부 영구 정리 |
<!-- @generated:schedules END -->

<!-- @intent START -->
`prune-attachments` 는 두 가지 서로 다른 작업을 한 스케줄에 묶습니다 — "방치된 임시 첨부
정리"(업로드했지만 게시글 저장까지 이어지지 않은 파일)는 사용자 파일을 지우지 않으므로 항상
실행되고, "보존기간 경과 삭제 첨부 영구 정리"(이미 삭제 처리된 첨부의 실제 파일 파기)는
`attachment_settings.purge_enabled` 로 게이트됩니다 — module.php 의 `enabled_config: null` 은
스케줄 자체는 끌 수 없다는 뜻이고, 실제 파기 여부만 설정으로 조정됩니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 알림 키 | 채널 |
|---|---|
| `new_comment` | `mail`, `database` |
| `reply_comment` | `mail`, `database` |
| `post_reply` | `mail`, `database` |
| `post_action` | `mail`, `database` |
| `new_post_admin` | `mail`, `database` |
| `report_received_admin` | `mail`, `database` |
| `report_action` | `mail`, `database` |
<!-- @generated:notifications END -->

<!-- @intent START -->
7종 중 `new_comment`/`reply_comment`/`post_reply` 는 회원이 끌 수 있습니다
(`UserNotificationSetting`, `core.user.*` 훅으로 회원 폼에 노출) — 반면 관리자 대상 알림
(`new_post_admin`/`report_received_admin`)과 신고 처리 결과 알림(`post_action`/`report_action`)
은 끌 수 없습니다. `post_action`과 `report_action`이 정확히 같은 훅 6개를 구독하는 것은
중복이 아니라 **관점의 차이**입니다 — 관리자가 직접 블라인드했는지, 신고 처리 결과로
블라인드됐는지에 따라 원 작성자에게 보이는 문구(원인 설명)가 갈라져야 하기 때문입니다.

관리자 대상 알림의 수신자(`related_users.board_managers`)는 그 게시판의 manager 역할
(`sirsoft-board.{slug}.manager`) 사용자이고, 아무도 없으면 최고관리자 1명으로 폴백합니다.
알림 데이터 리스너(`BoardNotificationDataListener`)는 이 조회를 모델 관계로 하지 않고
Repository 로만 합니다 — 역할 사용자는 `RoleRepositoryInterface::getUsersByIdentifier()`,
폴백은 `UserRepositoryInterface::findSuperAdmin()`, 신고 접수 알림의 "케이스당 1회" 판정과
최근 신고 로그는 `ReportRepositoryInterface::countLogsByReportSince()`·`findLatestLogByReport()`
입니다.
<!-- @intent END -->

## 활동 로그 훅

> 이 확장이 코어 활동 로그(`activity_logs`)에 기록을 남기기 위해 구독하는 훅 33개입니다.
> 코어 `docs/backend/activity-log-hooks.md` 에 있던 목록을 이 확장 소유로 옮긴 것입니다(#601) —
> 확장이 훅을 더할 때 코어 문서를 고쳐야 하던 역방향 의존을 없애기 위해서입니다. 코어 문서에는
> 총계와 이 문서로의 링크만 남습니다.

> 새 항목을 추가하면 코어 `lang/{ko,en}/activity_log.php` 의 action 라벨과 description 본문,
> 그리고 번들 일본어 팩까지 함께 정의해야 합니다 — **모듈 lang 파일에 넣으면 해석되지
> 않습니다.**

### 게시판 모듈 훅 (BoardActivityLogListener)

**파일**: `modules/_bundled/sirsoft-board/src/Listeners/BoardActivityLogListener.php`
**총 30훅**

> 이 표에 `before_*` 훅이 없는 것은 누락이 아닙니다. 수정 전 스냅샷은 이 리스너가
> `before_*` 훅으로 직접 잡지 않고 **Service 가 잡아 `after_*` 훅의 인자로 넘깁니다**
> (`ChangeDetector::detect($model, $snapshot)`). `before_*` 훅 자체는 발행되며 그 목록은
> 위 「발행 훅」 절에 있습니다.

#### Board (7훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.board.after_create` | `handleBoardAfterCreate` | `board.create` | Admin | Board |
| `sirsoft-board.board.after_update` | `handleBoardAfterUpdate` | `board.update` | Admin | Board |
| `sirsoft-board.board.after_delete` | `handleBoardAfterDelete` | `board.delete` | Admin | Board |
| `sirsoft-board.board.after_add_to_menu` | `handleBoardAfterAddToMenu` | `board.add_to_menu` | Admin | Board |
| `sirsoft-board.board.after_remove_from_menu` | `handleBoardAfterRemoveFromMenu` | `board.remove_from_menu` | Admin | Board |
| `sirsoft-board.settings.after_bulk_apply` | `handleSettingsAfterBulkApply` | `board_settings.bulk_apply` | Admin | - |
| `sirsoft-board.settings.after_bulk_apply_aborted` | `handleSettingsAfterBulkApplyAborted` | `board_settings.bulk_apply_aborted` | Admin | - |

#### BoardType (3훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.board_type.after_create` | `handleBoardTypeAfterCreate` | `board_type.create` | Admin | BoardType |
| `sirsoft-board.board_type.after_update` | `handleBoardTypeAfterUpdate` | `board_type.update` | Admin | BoardType |
| `sirsoft-board.board_type.after_delete` | `handleBoardTypeAfterDelete` | `board_type.delete` | Admin | BoardType |

#### Post (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.post.after_create` | `handlePostAfterCreate` | `post.create` | Admin | Post |
| `sirsoft-board.post.after_update` | `handlePostAfterUpdate` | `post.update` | Admin | Post |
| `sirsoft-board.post.after_delete` | `handlePostAfterDelete` | `post.delete` | Admin | Post |
| `sirsoft-board.post.after_blind` | `handlePostAfterBlind` | `post.blind` | Admin | Post |
| `sirsoft-board.post.after_restore` | `handlePostAfterRestore` | `post.restore` | Admin | Post |

#### Comment (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.comment.after_create` | `handleCommentAfterCreate` | `comment.create` | Admin | Comment |
| `sirsoft-board.comment.after_update` | `handleCommentAfterUpdate` | `comment.update` | Admin | Comment |
| `sirsoft-board.comment.after_delete` | `handleCommentAfterDelete` | `comment.delete` | Admin | Comment |
| `sirsoft-board.comment.after_blind` | `handleCommentAfterBlind` | `comment.blind` | Admin | Comment |
| `sirsoft-board.comment.after_restore` | `handleCommentAfterRestore` | `comment.restore` | Admin | Comment |

#### Attachment (3훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.attachment.after_upload` | `handleAttachmentAfterUpload` | `attachment.upload` | Admin | Attachment |
| `sirsoft-board.attachment.after_delete` | `handleAttachmentAfterDelete` | `attachment.delete` | Admin | Attachment |
| `sirsoft-board.attachment.after_download` | `handleAttachmentAfterDownload` | `attachment.download` | Admin / User | Attachment |

#### Report (8훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.report.after_create` | `handleReportAfterCreate` | `report.create` | Admin | Report |
| `sirsoft-board.report.after_update_status` | `handleReportAfterUpdateStatus` | `report.update_status` | Admin | Report |
| `sirsoft-board.report.after_bulk_update_status` | `handleReportAfterBulkUpdateStatus` | `report.bulk_update_status` | Admin | - |
| `sirsoft-board.report.after_delete` | `handleReportAfterDelete` | `report.delete` | Admin | Report |
| `sirsoft-board.report.after_restore_content` | `handleReportAfterRestoreContent` | `report.restore_content` | Admin | Report |
| `sirsoft-board.report.after_blind_content` | `handleReportAfterBlindContent` | `report.blind_content` | Admin | Report |
| `sirsoft-board.report.after_delete_content` | `handleReportAfterDeleteContent` | `report.delete_content` | Admin | Report |
| `sirsoft-board.report.after_cancel` | `handleReportAfterCancel` | `report.cancel` | User | Report |

#### Scrap (1훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.scrap.after_toggle` | `handleScrapAfterToggle` | `scrap.scrap` / `scrap.unscrap` | User | Post |

#### Reaction (1훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.reaction.after_react` | `handleReactionAfterReact` | `reaction.add` / `reaction.change` / `reaction.remove` (댓글이면 `comment_reaction.*`) | User | Post / Comment |
