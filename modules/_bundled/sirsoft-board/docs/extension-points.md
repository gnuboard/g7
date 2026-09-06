# 게시판 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 90종 / 호출 지점 91곳. 이 중 90종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `core.module_settings.after_save` | action | — | `src/Http/Controllers/Admin/BoardSettingsController.php:123` |
| `sirsoft-board.attachment.after_delete` | action | — | `src/Services/AttachmentService.php:463` |
| `sirsoft-board.attachment.after_download` | action | — | `src/Services/AttachmentService.php:776` |
| `sirsoft-board.attachment.after_link` | action | — | `src/Services/AttachmentService.php:217` 외 1곳 |
| `sirsoft-board.attachment.after_reorder` | action | — | `src/Services/AttachmentService.php:631` |
| `sirsoft-board.attachment.after_upload` | action | — | `src/Services/AttachmentService.php:192` |
| `sirsoft-board.attachment.before_delete` | action | — | `src/Services/AttachmentService.php:442` |
| `sirsoft-board.attachment.before_reorder` | action | — | `src/Services/AttachmentService.php:626` |
| `sirsoft-board.attachment.before_upload` | action | — | `src/Services/AttachmentService.php:117` |
| `sirsoft-board.attachment.filter_upload_file` | filter | — | `src/Services/AttachmentService.php:120` |
| `sirsoft-board.attachment.reorder_validation_rules` | filter | — | `src/Http/Requests/ReorderAttachmentsRequest.php:35` |
| `sirsoft-board.attachment.upload_validation_rules` | filter | — | `src/Http/Requests/UploadAttachmentRequest.php:77` |
| `sirsoft-board.board.after_add_to_menu` | action | — | `src/Services/BoardService.php:1059` |
| `sirsoft-board.board.after_create` | action | — | `src/Services/BoardService.php:457` |
| `sirsoft-board.board.after_delete` | action | — | `src/Services/BoardService.php:623` |
| `sirsoft-board.board.after_remove_from_menu` | action | — | `src/Services/BoardService.php:1104` |
| `sirsoft-board.board.after_update` | action | — | `src/Services/BoardService.php:543` |
| `sirsoft-board.board.before_add_to_menu` | action | — | `src/Services/BoardService.php:1023` |
| `sirsoft-board.board.before_copy` | action | — | `src/Services/BoardService.php:646` |
| `sirsoft-board.board.before_create` | action | — | `src/Services/BoardService.php:382` |
| `sirsoft-board.board.before_delete` | action | — | `src/Services/BoardService.php:574` |
| `sirsoft-board.board.before_remove_from_menu` | action | — | `src/Services/BoardService.php:1090` |
| `sirsoft-board.board.before_update` | action | — | `src/Services/BoardService.php:495` |
| `sirsoft-board.board.filter_copy_data` | filter | — | `src/Services/BoardService.php:703` |
| `sirsoft-board.board.filter_create_data` | filter | — | `src/Services/BoardService.php:385` |
| `sirsoft-board.board.filter_menu_data` | filter | — | `src/Services/BoardService.php:1053` |
| `sirsoft-board.board.filter_update_data` | filter | — | `src/Services/BoardService.php:500` |
| `sirsoft-board.board.posts.before_force_delete` | action | — | `src/Services/BoardService.php:593` |
| `sirsoft-board.board.store_validation_rules` | filter | — | `src/Http/Requests/StoreBoardRequest.php:225` |
| `sirsoft-board.board.update_validation_rules` | filter | — | `src/Http/Requests/UpdateBoardRequest.php:228` |
| `sirsoft-board.board_type.after_create` | action | — | `src/Services/BoardTypeService.php:42` |
| `sirsoft-board.board_type.after_delete` | action | — | `src/Services/BoardTypeService.php:107` |
| `sirsoft-board.board_type.after_update` | action | — | `src/Services/BoardTypeService.php:70` |
| `sirsoft-board.board_type.before_create` | action | — | `src/Services/BoardTypeService.php:36` |
| `sirsoft-board.board_type.before_delete` | action | — | `src/Services/BoardTypeService.php:103` |
| `sirsoft-board.board_type.before_update` | action | — | `src/Services/BoardTypeService.php:62` |
| `sirsoft-board.board_type.filter_create_data` | filter | — | `src/Services/BoardTypeService.php:38` |
| `sirsoft-board.board_type.filter_update_data` | filter | — | `src/Services/BoardTypeService.php:66` |
| `sirsoft-board.comment.after_blind` | action | — | `src/Services/CommentService.php:496` |
| `sirsoft-board.comment.after_create` | action | — | `src/Services/CommentService.php:391` |
| `sirsoft-board.comment.after_delete` | action | — | `src/Services/CommentService.php:457` |
| `sirsoft-board.comment.after_restore` | action | — | `src/Services/CommentService.php:532` |
| `sirsoft-board.comment.after_update` | action | — | `src/Services/CommentService.php:426` |
| `sirsoft-board.comment.before_blind` | action | — | `src/Services/CommentService.php:487` |
| `sirsoft-board.comment.before_create` | action | — | `src/Services/CommentService.php:357` |
| `sirsoft-board.comment.before_delete` | action | — | `src/Services/CommentService.php:447` |
| `sirsoft-board.comment.before_restore` | action | — | `src/Services/CommentService.php:523` |
| `sirsoft-board.comment.before_update` | action | — | `src/Services/CommentService.php:415` |
| `sirsoft-board.comment.filter_create_data` | filter | — | `src/Services/CommentService.php:360` |
| `sirsoft-board.comment.filter_update_data` | filter | — | `src/Services/CommentService.php:420` |
| `sirsoft-board.comment.store_validation_rules` | filter | — | `src/Http/Requests/StoreCommentRequest.php:91` |
| `sirsoft-board.comment.update_validation_rules` | filter | — | `src/Http/Requests/UpdateCommentRequest.php:67` |
| `sirsoft-board.permissions.after_create` | action | — | `src/Services/BoardService.php:431` |
| `sirsoft-board.permissions.after_delete` | action | — | `src/Services/BoardService.php:600` |
| `sirsoft-board.permissions.after_update` | action | — | `src/Services/BoardService.php:523` |
| `sirsoft-board.post.after_blind` | action | — | `src/Services/PostService.php:510` |
| `sirsoft-board.post.after_create` | action | — | `src/Services/PostService.php:285` |
| `sirsoft-board.post.after_delete` | action | — | `src/Services/PostService.php:469` |
| `sirsoft-board.post.after_restore` | action | — | `src/Services/PostService.php:574` |
| `sirsoft-board.post.after_update` | action | — | `src/Services/PostService.php:361` |
| `sirsoft-board.post.before_blind` | action | — | `src/Services/PostService.php:501` |
| `sirsoft-board.post.before_create` | action | — | `src/Services/PostService.php:260` |
| `sirsoft-board.post.before_delete` | action | — | `src/Services/PostService.php:436` |
| `sirsoft-board.post.before_restore` | action | — | `src/Services/PostService.php:543` |
| `sirsoft-board.post.before_update` | action | — | `src/Services/PostService.php:334` |
| `sirsoft-board.post.filter_content_thumbnail` | filter | — | `src/Models/Post.php:138` |
| `sirsoft-board.post.filter_create_data` | filter | — | `src/Services/PostService.php:263` |
| `sirsoft-board.post.filter_update_data` | filter | — | `src/Services/PostService.php:339` |
| `sirsoft-board.post.store_validation_rules` | filter | — | `src/Http/Requests/StorePostRequest.php:120` |
| `sirsoft-board.post.update_validation_rules` | filter | — | `src/Http/Requests/UpdatePostRequest.php:77` |
| `sirsoft-board.report.after_blind_content` | action | — | `src/Services/ReportService.php:717` |
| `sirsoft-board.report.after_bulk_update_status` | action | — | `src/Services/ReportService.php:497` |
| `sirsoft-board.report.after_create` | action | — | `src/Services/ReportService.php:297` |
| `sirsoft-board.report.after_delete` | action | — | `src/Services/ReportService.php:564` |
| `sirsoft-board.report.after_delete_content` | action | — | `src/Services/ReportService.php:885` |
| `sirsoft-board.report.after_restore_content` | action | — | `src/Services/ReportService.php:683` |
| `sirsoft-board.report.after_update_status` | action | — | `src/Services/ReportService.php:374` |
| `sirsoft-board.report.before_bulk_update_status` | action | — | `src/Services/ReportService.php:390` |
| `sirsoft-board.report.before_create` | action | — | `src/Services/ReportService.php:208` |
| `sirsoft-board.report.before_delete` | action | — | `src/Services/ReportService.php:559` |
| `sirsoft-board.report.before_update_status` | action | — | `src/Services/ReportService.php:329` |
| `sirsoft-board.report.filter_create_data` | filter | — | `src/Services/ReportService.php:232` |
| `sirsoft-board.roles.after_create` | action | — | `src/Services/BoardService.php:411` |
| `sirsoft-board.roles.after_delete` | action | — | `src/Services/BoardService.php:604` |
| `sirsoft-board.search.post.index_should_update` | filter | — | `src/Models/Post.php:280` |
| `sirsoft-board.settings.after_bulk_apply` | action | — | `src/Services/BoardService.php:1368` |
| `sirsoft-board.settings.after_bulk_apply_aborted` | action | — | `src/Services/BoardService.php:1359` |
| `sirsoft-board.settings.before_bulk_apply` | action | — | `src/Services/BoardService.php:1263` |
| `sirsoft-board.user_post.store_validation_rules` | filter | — | `src/Http/Requests/User/StorePostRequest.php:162` |
| `sirsoft-board.user_post.update_validation_rules` | filter | — | `src/Http/Requests/User/UpdatePostRequest.php:112` |
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
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.activity_log.filter_description_params` | filter | `ActivityLogDescriptionResolver` | `resolveDescriptionParams` | 10 |
| `core.module_settings.after_save` | action (미선언) | `SeoBoardSettingsCacheListener` | `onModuleSettingsSave` | 20 |
| `core.notification.filter_default_definitions` | filter | `BoardNotificationDataListener` | `contributeDefaultDefinitions` | 20 |
| `core.search.build_response` | filter | `SearchPostsListener` | `buildPostsResponse` | 10 |
| `core.search.index_validation_rules` | filter | `SearchPostsListener` | `addValidationRules` | 10 |
| `core.search.results` | filter | `SearchPostsListener` | `searchPosts` | 10 |
| `core.user.after_create` | action (미선언) | `UserNotificationSettingsListener` | `afterCreate` | 10 |
| `core.user.create_validation_rules` | filter | `UserNotificationSettingsListener` | `addValidationRules` | 10 |
| `core.user.filter_create_data` | filter | `UserNotificationSettingsListener` | `filterCreateData` | 10 |
| `core.user.filter_resource_data` | filter | `UserNotificationSettingsListener` | `filterResourceData` | 10 |
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
| `sirsoft-board.board.after_remove_from_menu` | action (미선언) | `BoardActivityLogListener` | `handleBoardAfterRemoveFromMenu` | 20 |
| `sirsoft-board.board.after_update` | action (미선언) | `BoardActivityLogListener` | `handleBoardAfterUpdate` | 20 |
| `sirsoft-board.board.after_update` | action (미선언) | `SeoBoardCacheListener` | `onBoardUpdate` | 20 |
| `sirsoft-board.board_type.after_create` | action (미선언) | `BoardActivityLogListener` | `handleBoardTypeAfterCreate` | 20 |
| `sirsoft-board.board_type.after_delete` | action (미선언) | `BoardActivityLogListener` | `handleBoardTypeAfterDelete` | 20 |
| `sirsoft-board.board_type.after_update` | action (미선언) | `BoardActivityLogListener` | `handleBoardTypeAfterUpdate` | 20 |
| `sirsoft-board.comment.after_blind` | action (미선언) | `BoardActivityLogListener` | `handleCommentAfterBlind` | 20 |
| `sirsoft-board.comment.after_create` | action (미선언) | `BoardActivityLogListener` | `handleCommentAfterCreate` | 20 |
| `sirsoft-board.comment.after_create` | action (미선언) | `BoardCommentsCountSyncListener` | `syncCommentsCount` | 10 |
| `sirsoft-board.comment.after_create` | action (미선언) | `CommentReplySyncListener` | `syncRepliesCount` | 10 |
| `sirsoft-board.comment.after_create` | action (미선언) | `PostCountSyncListener` | `syncCommentsCount` | 10 |
| `sirsoft-board.comment.after_delete` | action (미선언) | `BoardActivityLogListener` | `handleCommentAfterDelete` | 20 |
| `sirsoft-board.comment.after_delete` | action (미선언) | `BoardCommentsCountSyncListener` | `syncCommentsCount` | 10 |
| `sirsoft-board.comment.after_delete` | action (미선언) | `CommentReplySyncListener` | `syncRepliesCount` | 10 |
| `sirsoft-board.comment.after_delete` | action (미선언) | `PostCountSyncListener` | `syncCommentsCount` | 10 |
| `sirsoft-board.comment.after_restore` | action (미선언) | `BoardActivityLogListener` | `handleCommentAfterRestore` | 20 |
| `sirsoft-board.comment.after_restore` | action (미선언) | `BoardCommentsCountSyncListener` | `syncCommentsCount` | 10 |
| `sirsoft-board.comment.after_restore` | action (미선언) | `CommentReplySyncListener` | `syncRepliesCount` | 10 |
| `sirsoft-board.comment.after_restore` | action (미선언) | `PostCountSyncListener` | `syncCommentsCount` | 10 |
| `sirsoft-board.comment.after_update` | action (미선언) | `BoardActivityLogListener` | `handleCommentAfterUpdate` | 20 |
| `sirsoft-board.notification.channels` | filter | `BoardNotificationChannelListener` | `filterChannels` | 10 |
| `sirsoft-board.notification.extract_data` | filter | `BoardNotificationDataListener` | `extractData` | 20 |
| `sirsoft-board.post.after_blind` | action (미선언) | `BoardActivityLogListener` | `handlePostAfterBlind` | 20 |
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
| `sirsoft-board.post.after_update` | action (미선언) | `BoardActivityLogListener` | `handlePostAfterUpdate` | 20 |
| `sirsoft-board.post.after_update` | action (미선언) | `SeoBoardCacheListener` | `onPostUpdate` | 20 |
| `sirsoft-board.report.after_blind_content` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterBlindContent` | 20 |
| `sirsoft-board.report.after_bulk_update_status` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterBulkUpdateStatus` | 20 |
| `sirsoft-board.report.after_create` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterCreate` | 20 |
| `sirsoft-board.report.after_delete` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterDelete` | 20 |
| `sirsoft-board.report.after_delete_content` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterDeleteContent` | 20 |
| `sirsoft-board.report.after_restore_content` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterRestoreContent` | 20 |
| `sirsoft-board.report.after_update_status` | action (미선언) | `BoardActivityLogListener` | `handleReportAfterUpdateStatus` | 20 |
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
| `sirsoft-ecommerce.inquiry.update` | filter | `EcommerceInquiryHookListener` | `updatePost` | 10 |
| `sirsoft-ecommerce.inquiry.update_reply` | filter | `EcommerceInquiryHookListener` | `updateReplyPost` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`core.user.*` 4종을 구독하는 이유는 회원가입/수정 화면에 "댓글 알림 수신 여부" 필드를 끼워
넣기 위해서입니다 — 이 필드는 `UserNotificationSetting` 모델(board 소유)에 저장되지만, 입력
자체는 코어 회원 폼에서 받습니다. `sirsoft-ckeditor5.image.filter_reference_sources` 구독은
board 글 본문(HTML 에디터)에 삽입된 이미지가 삭제 시 함께 정리되도록 참조 소스 목록에 게시글을
등록하는 자리입니다. `sirsoft-ecommerce.inquiry.*` 8개는 이커머스 "상품 문의"가 board 의
Post/Comment CRUD 를 그대로 재사용하되 저장 로직만 이커머스가 대신 처리하는 위임 지점입니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogDescriptionResolver` | 1개 | 명시 등록 | ✅ | `src/Listeners/ActivityLogDescriptionResolver.php` |
| `BoardActivityLogListener` | 30개 | 명시 등록 | ✅ | `src/Listeners/BoardActivityLogListener.php` |
| `BoardCommentsCountSyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/BoardCommentsCountSyncListener.php` |
| `BoardNotificationChannelListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/BoardNotificationChannelListener.php` |
| `BoardNotificationDataListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/BoardNotificationDataListener.php` |
| `BoardPostsCountSyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/BoardPostsCountSyncListener.php` |
| `Ckeditor5ReferenceSourcesListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Ckeditor5ReferenceSourcesListener.php` |
| `CommentReplySyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/CommentReplySyncListener.php` |
| `EcommerceInquiryHookListener` | 8개 | 명시 등록 | ✅ | `src/Listeners/EcommerceInquiryHookListener.php` |
| `PostAttachmentCountSyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/PostAttachmentCountSyncListener.php` |
| `PostCountSyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/PostCountSyncListener.php` |
| `PostReplySyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/PostReplySyncListener.php` |
| `SearchPostsListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/SearchPostsListener.php` |
| `SeoBoardCacheListener` | 4개 | 명시 등록 | ✅ | `src/Listeners/SeoBoardCacheListener.php` |
| `SeoBoardSettingsCacheListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/SeoBoardSettingsCacheListener.php` |
| `UserNotificationSettingsListener` | 7개 | 명시 등록 | ✅ | `src/Listeners/UserNotificationSettingsListener.php` |
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
| `resources/extensions/admin-ecommerce-inquiry-settings.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/admin_dashboard_community.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/admin_dashboard_quick_menu.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/user-notification-detail.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
| `resources/extensions/user-notification-settings.json` | 다른 확장/템플릿 레이아웃에 주입되는 조각 |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
5개 조각 중 `admin-ecommerce-inquiry-settings.json`·`admin_dashboard_community.json`·
`admin_dashboard_quick_menu.json` 은 board 자신의 화면이 아니라 **다른 확장(이커머스 문의
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
<!-- @intent END -->

## 활동 로그 훅

> 이 확장이 코어 활동 로그(`activity_logs`)에 기록을 남기기 위해 구독하는 훅 30개입니다.
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

#### Report (7훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-board.report.after_create` | `handleReportAfterCreate` | `report.create` | Admin | Report |
| `sirsoft-board.report.after_update_status` | `handleReportAfterUpdateStatus` | `report.update_status` | Admin | Report |
| `sirsoft-board.report.after_bulk_update_status` | `handleReportAfterBulkUpdateStatus` | `report.bulk_update_status` | Admin | - |
| `sirsoft-board.report.after_delete` | `handleReportAfterDelete` | `report.delete` | Admin | Report |
| `sirsoft-board.report.after_restore_content` | `handleReportAfterRestoreContent` | `report.restore_content` | Admin | Report |
| `sirsoft-board.report.after_blind_content` | `handleReportAfterBlindContent` | `report.blind_content` | Admin | Report |
| `sirsoft-board.report.after_delete_content` | `handleReportAfterDeleteContent` | `report.delete_content` | Admin | Report |
