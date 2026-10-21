# 게시판 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 167종 / 호출 지점 176곳. 이 중 167종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `core.module_settings.after_save` | action | — | `src/Http/Controllers/Admin/BoardSettingsController.php:181` |
| `sirsoft-board.attachment.after_delete` | action | — | `src/Services/AttachmentService.php:613` |
| `sirsoft-board.attachment.after_download` | action | — | `src/Services/AttachmentService.php:982` |
| `sirsoft-board.attachment.after_link` | action | — | `src/Services/AttachmentService.php:230` 외 1곳 |
| `sirsoft-board.attachment.after_reorder` | action | — | `src/Services/AttachmentService.php:801` |
| `sirsoft-board.attachment.after_upload` | action | — | `src/Services/AttachmentService.php:205` |
| `sirsoft-board.attachment.before_delete` | action | — | `src/Services/AttachmentService.php:592` |
| `sirsoft-board.attachment.before_reorder` | action | — | `src/Services/AttachmentService.php:796` |
| `sirsoft-board.attachment.before_upload` | action | — | `src/Services/AttachmentService.php:130` |
| `sirsoft-board.attachment.filter_upload_file` | filter | — | `src/Services/AttachmentService.php:133` |
| `sirsoft-board.attachment.reorder_validation_rules` | filter | — | `src/Http/Requests/ReorderAttachmentsRequest.php:40` |
| `sirsoft-board.attachment.upload_validation_rules` | filter | — | `src/Http/Requests/UploadAttachmentRequest.php:80` |
| `sirsoft-board.author_ip.after_prune` | action | — | `src/Privacy/BoardIpRetentionHandler.php:135` |
| `sirsoft-board.author_ip.before_prune` | action | — | `src/Privacy/BoardIpRetentionHandler.php:130` |
| `sirsoft-board.board.after_add_to_menu` | action | — | `src/Services/BoardService.php:1499` |
| `sirsoft-board.board.after_bulk_members` | action | — | `src/Services/BoardService.php:1952` |
| `sirsoft-board.board.after_create` | action | — | `src/Services/BoardService.php:642` |
| `sirsoft-board.board.after_delete` | action | — | `src/Services/BoardService.php:889` |
| `sirsoft-board.board.after_menu_list` | action | — | `src/Services/BoardService.php:283` |
| `sirsoft-board.board.after_popular_list` | action | — | `src/Services/BoardService.php:545` |
| `sirsoft-board.board.after_public_list` | action | — | `src/Services/BoardService.php:307` |
| `sirsoft-board.board.after_public_show` | action | — | `src/Services/BoardService.php:335` |
| `sirsoft-board.board.after_remove_from_menu` | action | — | `src/Services/BoardService.php:1544` |
| `sirsoft-board.board.after_reorder` | action | — | `src/Services/BoardService.php:1333` |
| `sirsoft-board.board.after_stats` | action | — | `src/Services/BoardService.php:428` |
| `sirsoft-board.board.after_update` | action | — | `src/Services/BoardService.php:806` |
| `sirsoft-board.board.before_add_to_menu` | action | — | `src/Services/BoardService.php:1463` |
| `sirsoft-board.board.before_bulk_members` | action | — | `src/Services/BoardService.php:1863` |
| `sirsoft-board.board.before_copy` | action | — | `src/Services/BoardService.php:912` |
| `sirsoft-board.board.before_create` | action | — | `src/Services/BoardService.php:608` |
| `sirsoft-board.board.before_delete` | action | — | `src/Services/BoardService.php:838` |
| `sirsoft-board.board.before_menu_list` | action | — | `src/Services/BoardService.php:273` |
| `sirsoft-board.board.before_popular_list` | action | — | `src/Services/BoardService.php:532` |
| `sirsoft-board.board.before_public_list` | action | — | `src/Services/BoardService.php:301` |
| `sirsoft-board.board.before_public_show` | action | — | `src/Services/BoardService.php:325` |
| `sirsoft-board.board.before_remove_from_menu` | action | — | `src/Services/BoardService.php:1530` |
| `sirsoft-board.board.before_reorder` | action | — | `src/Services/BoardService.php:1315` |
| `sirsoft-board.board.before_stats` | action | — | `src/Services/BoardService.php:410` |
| `sirsoft-board.board.before_update` | action | — | `src/Services/BoardService.php:748` |
| `sirsoft-board.board.bulk_members_validation_rules` | filter | — | `src/Http/Requests/Admin/BulkBoardMembersRequest.php:53` |
| `sirsoft-board.board.filter_copy_data` | filter | — | `src/Services/BoardService.php:969` |
| `sirsoft-board.board.filter_create_data` | filter | — | `src/Services/BoardService.php:611` |
| `sirsoft-board.board.filter_menu_data` | filter | — | `src/Services/BoardService.php:1493` |
| `sirsoft-board.board.filter_menu_list_result` | filter | — | `src/Services/BoardService.php:281` |
| `sirsoft-board.board.filter_popular_list_result` | filter | — | `src/Services/BoardService.php:543` |
| `sirsoft-board.board.filter_public_list_result` | filter | — | `src/Services/BoardService.php:305` |
| `sirsoft-board.board.filter_public_show_result` | filter | — | `src/Services/BoardService.php:333` |
| `sirsoft-board.board.filter_stats_result` | filter | — | `src/Services/BoardService.php:426` |
| `sirsoft-board.board.filter_update_data` | filter | — | `src/Services/BoardService.php:753` |
| `sirsoft-board.board.posts.before_force_delete` | action | — | `src/Services/BoardService.php:859` |
| `sirsoft-board.board.reorder_validation_rules` | filter | — | `src/Http/Requests/Admin/ReorderBoardsRequest.php:49` |
| `sirsoft-board.board.store_validation_rules` | filter | — | `src/Http/Requests/StoreBoardRequest.php:254` |
| `sirsoft-board.board.update_validation_rules` | filter | — | `src/Http/Requests/UpdateBoardRequest.php:253` |
| `sirsoft-board.board_type.after_create` | action | — | `src/Services/BoardTypeService.php:42` |
| `sirsoft-board.board_type.after_delete` | action | — | `src/Services/BoardTypeService.php:107` |
| `sirsoft-board.board_type.after_update` | action | — | `src/Services/BoardTypeService.php:70` |
| `sirsoft-board.board_type.before_create` | action | — | `src/Services/BoardTypeService.php:36` |
| `sirsoft-board.board_type.before_delete` | action | — | `src/Services/BoardTypeService.php:103` |
| `sirsoft-board.board_type.before_update` | action | — | `src/Services/BoardTypeService.php:62` |
| `sirsoft-board.board_type.filter_create_data` | filter | — | `src/Services/BoardTypeService.php:38` |
| `sirsoft-board.board_type.filter_update_data` | filter | — | `src/Services/BoardTypeService.php:66` |
| `sirsoft-board.comment.after_blind` | action | — | `src/Services/CommentService.php:579` |
| `sirsoft-board.comment.after_create` | action | — | `src/Services/CommentService.php:432` |
| `sirsoft-board.comment.after_delete` | action | — | `src/Services/CommentService.php:530` |
| `sirsoft-board.comment.after_public_list` | action | — | `src/Services/CommentService.php:109` 외 1곳 |
| `sirsoft-board.comment.after_restore` | action | — | `src/Services/CommentService.php:627` |
| `sirsoft-board.comment.after_update` | action | — | `src/Services/CommentService.php:467` |
| `sirsoft-board.comment.after_user_list` | action | — | `src/Services/CommentService.php:670` |
| `sirsoft-board.comment.before_blind` | action | — | `src/Services/CommentService.php:570` |
| `sirsoft-board.comment.before_create` | action | — | `src/Services/CommentService.php:398` |
| `sirsoft-board.comment.before_delete` | action | — | `src/Services/CommentService.php:488` |
| `sirsoft-board.comment.before_public_list` | action | — | `src/Services/CommentService.php:74` 외 1곳 |
| `sirsoft-board.comment.before_restore` | action | — | `src/Services/CommentService.php:618` |
| `sirsoft-board.comment.before_update` | action | — | `src/Services/CommentService.php:456` |
| `sirsoft-board.comment.before_user_list` | action | — | `src/Services/CommentService.php:644` |
| `sirsoft-board.comment.filter_create_data` | filter | — | `src/Services/CommentService.php:401` |
| `sirsoft-board.comment.filter_public_list_result` | filter | — | `src/Services/CommentService.php:107` 외 1곳 |
| `sirsoft-board.comment.filter_update_data` | filter | — | `src/Services/CommentService.php:461` |
| `sirsoft-board.comment.filter_user_list_result` | filter | — | `src/Services/CommentService.php:668` |
| `sirsoft-board.comment.store_validation_rules` | filter | — | `src/Http/Requests/StoreCommentRequest.php:97` |
| `sirsoft-board.comment.update_validation_rules` | filter | — | `src/Http/Requests/UpdateCommentRequest.php:71` |
| `sirsoft-board.guest_author.after_prune` | action | — | `src/Privacy/BoardGuestRetentionHandler.php:192` |
| `sirsoft-board.guest_author.before_prune` | action | — | `src/Privacy/BoardGuestRetentionHandler.php:186` |
| `sirsoft-board.permissions.after_create` | action | — | `src/Services/BoardService.php:702` |
| `sirsoft-board.permissions.after_delete` | action | — | `src/Services/BoardService.php:866` |
| `sirsoft-board.permissions.after_update` | action | — | `src/Services/BoardService.php:779` |
| `sirsoft-board.post.after_adjacent` | action | — | `src/Services/PostService.php:1095` |
| `sirsoft-board.post.after_blind` | action | — | `src/Services/PostService.php:632` |
| `sirsoft-board.post.after_bulk_action` | action | — | `src/Services/PostService.php:802` |
| `sirsoft-board.post.after_create` | action | — | `src/Services/PostService.php:332` |
| `sirsoft-board.post.after_delete` | action | — | `src/Services/PostService.php:562` |
| `sirsoft-board.post.after_popular_list` | action | — | `src/Services/BoardService.php:511` |
| `sirsoft-board.post.after_public_list` | action | — | `src/Services/PostService.php:145` |
| `sirsoft-board.post.after_public_show` | action | — | `src/Services/PostService.php:986` |
| `sirsoft-board.post.after_recent_list` | action | — | `src/Services/BoardService.php:470` |
| `sirsoft-board.post.after_restore` | action | — | `src/Services/PostService.php:721` |
| `sirsoft-board.post.after_update` | action | — | `src/Services/PostService.php:408` |
| `sirsoft-board.post.after_user_activity_list` | action | — | `src/Services/PostService.php:1588` |
| `sirsoft-board.post.after_user_activity_stats` | action | — | `src/Services/PostService.php:1628` |
| `sirsoft-board.post.after_user_public_list` | action | — | `src/Services/PostService.php:1670` |
| `sirsoft-board.post.after_user_public_stats` | action | — | `src/Services/PostService.php:1712` |
| `sirsoft-board.post.before_adjacent` | action | — | `src/Services/PostService.php:1043` |
| `sirsoft-board.post.before_blind` | action | — | `src/Services/PostService.php:607` |
| `sirsoft-board.post.before_bulk_action` | action | — | `src/Services/PostService.php:762` |
| `sirsoft-board.post.before_create` | action | — | `src/Services/PostService.php:307` |
| `sirsoft-board.post.before_delete` | action | — | `src/Services/PostService.php:483` |
| `sirsoft-board.post.before_popular_list` | action | — | `src/Services/BoardService.php:496` |
| `sirsoft-board.post.before_public_list` | action | — | `src/Services/PostService.php:119` |
| `sirsoft-board.post.before_public_show` | action | — | `src/Services/PostService.php:951` |
| `sirsoft-board.post.before_recent_list` | action | — | `src/Services/BoardService.php:452` |
| `sirsoft-board.post.before_restore` | action | — | `src/Services/PostService.php:674` |
| `sirsoft-board.post.before_update` | action | — | `src/Services/PostService.php:381` |
| `sirsoft-board.post.before_user_activity_list` | action | — | `src/Services/PostService.php:1543` |
| `sirsoft-board.post.before_user_activity_stats` | action | — | `src/Services/PostService.php:1606` |
| `sirsoft-board.post.before_user_public_list` | action | — | `src/Services/PostService.php:1656` |
| `sirsoft-board.post.before_user_public_stats` | action | — | `src/Services/PostService.php:1687` |
| `sirsoft-board.post.filter_adjacent_result` | filter | — | `src/Services/PostService.php:1093` |
| `sirsoft-board.post.filter_content_thumbnail` | filter | — | `src/Models/Post.php:148` |
| `sirsoft-board.post.filter_create_data` | filter | — | `src/Services/PostService.php:310` |
| `sirsoft-board.post.filter_list_cache_variant` | filter | — | `src/Support/PostQueryExtensions.php:143` |
| `sirsoft-board.post.filter_popular_list_result` | filter | — | `src/Services/BoardService.php:509` |
| `sirsoft-board.post.filter_public_list_result` | filter | — | `src/Services/PostService.php:143` |
| `sirsoft-board.post.filter_public_show_result` | filter | — | `src/Services/PostService.php:984` |
| `sirsoft-board.post.filter_recent_list_result` | filter | — | `src/Services/BoardService.php:468` |
| `sirsoft-board.post.filter_update_data` | filter | — | `src/Services/PostService.php:386` |
| `sirsoft-board.post.filter_user_activity_list_result` | filter | — | `src/Services/PostService.php:1586` |
| `sirsoft-board.post.filter_user_activity_stats_result` | filter | — | `src/Services/PostService.php:1626` |
| `sirsoft-board.post.filter_user_public_list_result` | filter | — | `src/Services/PostService.php:1668` |
| `sirsoft-board.post.filter_user_public_stats_result` | filter | — | `src/Services/PostService.php:1710` |
| `sirsoft-board.post.list_query_extensions` | filter | — | `src/Support/PostQueryExtensions.php:73` |
| `sirsoft-board.post.store_validation_rules` | filter | — | `src/Http/Requests/StorePostRequest.php:123` |
| `sirsoft-board.post.update_validation_rules` | filter | — | `src/Http/Requests/UpdatePostRequest.php:81` |
| `sirsoft-board.reaction.after_react` | action | — | `src/Services/ReactionService.php:432` 외 1곳 |
| `sirsoft-board.reaction.after_user_list` | action | — | `src/Services/ReactionService.php:201` |
| `sirsoft-board.reaction.before_react` | action | — | `src/Services/ReactionService.php:385` |
| `sirsoft-board.reaction.before_user_list` | action | — | `src/Services/ReactionService.php:195` |
| `sirsoft-board.reaction.filter_user_list_result` | filter | — | `src/Services/ReactionService.php:199` |
| `sirsoft-board.report.after_blind_content` | action | — | `src/Services/ReportService.php:730` |
| `sirsoft-board.report.after_bulk_update_status` | action | — | `src/Services/ReportService.php:493` |
| `sirsoft-board.report.after_cancel` | action | — | `src/Services/ReportService.php:1648` |
| `sirsoft-board.report.after_create` | action | — | `src/Services/ReportService.php:297` |
| `sirsoft-board.report.after_delete` | action | — | `src/Services/ReportService.php:556` |
| `sirsoft-board.report.after_delete_content` | action | — | `src/Services/ReportService.php:903` |
| `sirsoft-board.report.after_restore_content` | action | — | `src/Services/ReportService.php:693` |
| `sirsoft-board.report.after_update_status` | action | — | `src/Services/ReportService.php:370` |
| `sirsoft-board.report.after_user_list` | action | — | `src/Services/ReportService.php:1584` |
| `sirsoft-board.report.before_bulk_update_status` | action | — | `src/Services/ReportService.php:386` |
| `sirsoft-board.report.before_cancel` | action | — | `src/Services/ReportService.php:1622` |
| `sirsoft-board.report.before_create` | action | — | `src/Services/ReportService.php:201` |
| `sirsoft-board.report.before_delete` | action | — | `src/Services/ReportService.php:551` |
| `sirsoft-board.report.before_update_status` | action | — | `src/Services/ReportService.php:325` |
| `sirsoft-board.report.before_user_list` | action | — | `src/Services/ReportService.php:1578` |
| `sirsoft-board.report.filter_create_data` | filter | — | `src/Services/ReportService.php:225` |
| `sirsoft-board.report.filter_user_list_result` | filter | — | `src/Services/ReportService.php:1582` |
| `sirsoft-board.roles.after_create` | action | — | `src/Services/BoardService.php:682` |
| `sirsoft-board.roles.after_delete` | action | — | `src/Services/BoardService.php:870` |
| `sirsoft-board.scrap.after_toggle` | action | — | `src/Services/PostScrapService.php:77` 외 4곳 |
| `sirsoft-board.scrap.after_user_list` | action | — | `src/Services/PostScrapService.php:167` |
| `sirsoft-board.scrap.before_toggle` | action | — | `src/Services/PostScrapService.php:65` |
| `sirsoft-board.scrap.before_user_list` | action | — | `src/Services/PostScrapService.php:161` |
| `sirsoft-board.scrap.filter_user_list_result` | filter | — | `src/Services/PostScrapService.php:165` |
| `sirsoft-board.search.post.index_should_update` | filter | — | `src/Models/Post.php:298` |
| `sirsoft-board.settings.after_bulk_apply` | action | — | `src/Services/BoardService.php:1831` |
| `sirsoft-board.settings.after_bulk_apply_aborted` | action | — | `src/Services/BoardService.php:1822` |
| `sirsoft-board.settings.before_bulk_apply` | action | — | `src/Services/BoardService.php:1724` |
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

**조회 결과 훅 (1.2.1)** — 사용자 조회 19곳이 `before_{op}` → `filter_{op}_result` → `after_{op}` 세 훅을 발행합니다.
인자는 `(값, 요청 정보)` 이고 요청 정보에는 `user`(비회원은 `null`)·`locale` 과 아래 경로별 값이 들어 있습니다.
`after_*` 는 결과 훅이 돌려준 값을 받습니다. 결과 훅은 Service 에서, 캐시를 읽은 뒤·열람 권한 거르기 뒤에 발행하므로
리스너가 결과를 바꿔도 공용 캐시는 그대로이고, 리스너는 이미 걸러진 목록을 받습니다.

| 조회(API) | 훅 이름 (`sirsoft-board.` 생략) | 값 타입 | 경로별 값 |
|---|---|---|---|
| 게시판 메뉴 `boards/board-menu` | `board.*_menu_list` | 게시판 컬렉션(id·name·slug·display_order·created_at, 복제본) | — |
| 게시판 목록 `boards` | `board.*_public_list` | 게시판 컬렉션 | `recent_posts_limit` |
| 게시판 정보 `boards/{slug}` | `board.*_public_show` | 게시판 모델 | `slug` |
| 게시판 통계 `boards/stats` | `board.*_stats` | 배열 | — |
| 최근 게시글 `boards/posts/recent` | `post.*_recent_list` | 배열 | `limit` |
| 인기 게시판 `boards/popular-boards` | `board.*_popular_list` | 배열 | `limit` |
| 인기 게시글 `boards/popular` | `post.*_popular_list` | 배열 | `period`, `limit` |
| 게시글 목록 `boards/{slug}/posts` | `post.*_public_list` | 페이지네이터 | `board`, `filters`, `with_trashed` |
| 게시글 상세 `boards/{slug}/posts/{id}` (비밀번호 확인 응답 2곳 포함) | `post.*_public_show` | 게시글 모델 | `board`, `post_id` |
| 이전/다음 글 `…/posts/{id}/navigation` | `post.*_adjacent` | 배열 `{prev, next}` | `board`, `post_id`, `filters` |
| 댓글 목록 `…/posts/{id}/comments`·상세 화면 댓글 | `comment.*_public_list` | 댓글 컬렉션 | `board`, `post_id`, `paginated` |
| 내 활동 `me/board-activities` | `post.*_user_activity_list` | 페이지네이터 | `subject_user_id`, `filters` |
| 내 활동 통계 `me/activity-stats` | `post.*_user_activity_stats` | 배열 | `subject_user_id` |
| 내 댓글 `me/my-comments` | `comment.*_user_list` | 페이지네이터 | `subject_user_id`, `filters` |
| 회원 작성글 `users/{user}/posts` | `post.*_user_public_list` | 페이지네이터 | `subject_user_id`, `filters` |
| 회원 통계 `users/{user}/posts/stats` | `post.*_user_public_stats` | 배열 | `subject_user_id` |
| 내 스크랩 `me/scraps` | `scrap.*_user_list` | 페이지네이터 | `subject_user_id`, `search`, `search_field` |
| 내 반응 `me/reactions` | `reaction.*_user_list` | 페이지네이터 | `subject_user_id`, `filters` |
| 내 신고 `me/reports` | `report.*_user_list` | 페이지네이터 | `subject_user_id`, `filters` |

- 리스너는 **같은 타입의 새 값**을 돌려줍니다. 컬렉션은 `map()`·`reverse()` 로, 페이지네이터는 `through()`·`setCollection()` 으로 고칩니다. 모델을 고칠 때는 복제해서 고칩니다 — 캐시가 같은 인스턴스를 돌려주는 저장소에서는 제자리 수정이 같은 요청의 다음 호출에 남습니다(메뉴는 Service 가 복제본을 넘깁니다).
- 페이지네이터에서 항목을 빼도 `total`·`last_page` 는 그대로입니다. 결과 훅은 변환·재정렬·주석 용도이고 목록을 좁히는 용도가 아닙니다.
- 게시글 상세 결과 훅은 상세 화면과 비밀번호 확인 응답(글·원글 비밀번호) 두 곳에서도 발행됩니다. 이전/다음 글은 컨트롤러가 미리 빈 값으로 답하는 경우(공지·답글·없는 글)에는 발행되지 않습니다. 단건 조회에서 대상이 없으면 `before_*` 만 발행합니다.
- 관리자 화면 조회(관리자 게시글 목록·상세·댓글)는 이 훅을 발행하지 않습니다. 첨부 내려받기·미리보기는 파일 응답이라 결과 훅이 없고, 내려받은 뒤 `attachment.after_download` 를 씁니다.
- `board.filter_menu_data` 는 **관리자 메뉴**를 만들 때의 데이터 필터이고, 사용자 메뉴 결과는 `board.filter_menu_list_result` 입니다.
- 서버 훅은 어느 화면이 불렀는지 모릅니다. 리스너를 걸면 같은 API 를 쓰는 모든 화면(PC 상단 메뉴·모바일 메뉴·마이페이지 게시판 선택칸·검색엔진용 페이지)이 함께 바뀝니다. 한 템플릿의 한 화면에서만 순서를 바꾸려면 레이아웃의 데이터 바인딩에서 정렬하세요 — 예: 목록 반복의 원본을 `{{[...(boards.data ?? [])].reverse()}}` 로 지정하면 그 화면만 뒤집히고 다른 화면·캐시에는 영향이 없습니다.

**게시판 표시 순서 (1.2.1)** — `sirsoft-board.board.before_reorder(array $boardIds)` / `after_reorder(array $boardIds, array $before)` 를 발행합니다.
`$boardIds` 는 새 순서의 전체 게시판 id, `$before` 는 바꾸기 전 `id => 표시 순서` 입니다. `after_reorder` 는 저장이 커밋된 뒤에 발행됩니다.
순서 저장 검증 규칙은 `sirsoft-board.board.reorder_validation_rules` 필터로 늘릴 수 있습니다.

### 목록 쿼리 확장 훅 (1.2.1)

**`sirsoft-board.post.list_query_extensions`** (filter) — 확장이 게시글 목록 성격의 쿼리에 조건을 더하는 자리입니다.
리스너는 받은 배열에 **조건 함수** `fn (Builder $query, array $context): void` 를 더해 돌려줍니다. 게시판은 자기 조건
(게시판·공지·원글·권한 범위·삭제·필터·검색)을 다 건 **뒤에** 조건 함수를 붙이므로 확장은 결과를 **좁히기만** 합니다.
코어 회원 목록의 `core.user.list_query_extensions` 와 같은 방식입니다. 호출할 수 없는 원소는 버리고 경고 로그를 남깁니다.
리스너가 하나도 없으면 훅을 부르지 않으며 쿼리도 늘지 않습니다.

`$context` 배열:

| 키 | 값 |
|---|---|
| `context` | 목록 구분값 (아래 표) |
| `audience` | `user` \| `admin` — 관리자 글 관리 목록·관리자 대시보드는 `admin` |
| `board` | `?Board` — 여러 게시판을 다루는 맥락에서는 `null` |
| `board_ids` | `?list<int>` — 통합검색의 대상 게시판 id (그 외 `null`) |
| `user` | `?User` — 현재 인증 사용자 (비회원·사이트맵은 `null`) |
| `locale` | 현재 화면 언어 (사이트맵은 기본 언어) |
| `subject_user_id` | `?int` — 마이페이지·회원 프로필의 「그 회원」 |

| 구분값 | 조회 | 비고 |
|---|---|---|
| `list` | 게시글 목록의 원글 | 사용자·관리자 목록 공통(`audience` 로 구분) |
| `notice` | 게시글 목록 첫 페이지의 공지 | |
| `reply` | 목록에서 원글 아래 이어 그리는 답글 줄, 관리자 목록의 답글 검색 | |
| `thread` | 게시글 상세의 답글 스레드(원글 아래 모든 깊이) | 사용자·관리자 상세 공통(`audience` 로 구분). 숨긴 답글 아래 답글은 이어 읽지 않습니다 |
| `count` | 게시글 목록의 전체 건수(페이지 수) | **`list` 와 같은 조건을 걸어야** 건수와 페이지 수가 맞습니다 |
| `adjacent` | 상세의 이전/다음 글 | 이전/다음을 찾는 모든 쿼리(정렬 동률 비교·작성일 최댓값/최솟값 서브쿼리)에 걸립니다 |
| `search` | 게시판 하나의 공개 검색 목록·건수 | |
| `search_across` | 통합검색의 게시글 목록·커서 목록·탭 배지 건수 | 셋이 같은 집합입니다 |
| `recent` | 홈 최근 게시글 | 게시판별 최신 글을 합치기 **전** 게시판마다 걸립니다 |
| `popular` | 인기 게시글 | 작성자·첨부 조인이 있어 **컬럼을 한정**해야 합니다 |
| `board_recent` | 게시판 목록 API 의 게시판별 최근 글 | |
| `user_activity` | 마이페이지 「내 글」·「댓글 단 글」, 회원 프로필 작성글 | 「댓글 단 글」은 조인이 있어 컬럼을 한정해야 합니다 |
| `user_stats` | 마이페이지 활동 통계·회원 프로필 통계의 게시글 수 | 댓글 수에는 걸리지 않습니다 |
| `admin_dashboard` | 관리자 대시보드 최근 게시글 | `audience=admin` |
| `sitemap` | 사이트맵의 게시글 URL | 요청이 없는 생성이라 `user` 가 없고 언어는 기본 언어입니다 |

**`sirsoft-board.post.filter_list_cache_variant`** (filter) — 위 훅으로 결과를 사람·언어마다 다르게 좁히는 확장이 **같은 축**을
목록 캐시 키에 넣는 자리입니다. 리스너는 받은 배열에 축 값(문자열·정수)을 더해 돌려줍니다. 값이 있으면 키 끝에 접미사가 붙고,
비어 있으면 키는 그대로입니다. 캐시 태그는 그대로라 글 작성·삭제·블라인드 때 비우는 방식도 같습니다.
`$context` 는 `cache`(아래 7개 중 하나)·`board_id`·`audience`·`user`·`locale` 입니다.

| `cache` | 캐시 |
|---|---|
| `normal_count` | 게시글 목록 전체 건수 |
| `adjacent` | 이전/다음 글 |
| `user_activities_total` | 마이페이지 활동 총 건수 |
| `user_activity_stats` | 마이페이지 활동 통계 |
| `user_public_stats` | 회원 프로필 통계 |
| `recent` | 홈 최근 게시글 |
| `popular` | 인기 게시글 |

> `list_query_extensions` 로 결과를 사람·언어마다 다르게 좁히면 **같은 축을 `filter_list_cache_variant` 로 반드시 돌려줍니다.**
> 돌려주지 않으면 먼저 연 사람의 건수·이전/다음·최근/인기 글이 캐시에 남아 다른 사람에게 보입니다.

리스너 규칙:

- 컬럼은 `$query->qualifyColumn('category')` 처럼 **테이블을 붙여** 씁니다 — `popular`·`user_activity` 는 다른 테이블과 조인합니다.
- 다른 테이블을 볼 때는 `whereIn(컬럼, 서브쿼리)` 를 씁니다. 글마다 판정하는 `whereHas` 는 목록 성능을 리스너에 묶습니다.
- 운영자가 확장 조건 없이 전부 봐야 하는 화면이면 `audience === 'admin'` 일 때 조건을 걸지 않습니다.
- 여러 게시판을 다루는 맥락(`recent`·`popular`·`search_across`·`user_activity`·`user_stats`·`admin_dashboard`)에서 특정 게시판만
  좁히려면 조건 안에서 게시판을 가립니다 — 예: `where(board_id 가 대상이 아님 OR 분류 조건)`.

확장 조건이 **반영되지 않는** 값:

- 저장된 총계 — 게시판 카드의 「글 N개」(게시판 글 수), 홈 통계, 인기 게시판, 일별 게시판 통계. 쿼리가 아니라 저장된 숫자입니다.
- 스크랩·반응 목록 — 회원이 직접 담은 항목이라 좁히면 담은 글이 사라진 것처럼 보입니다.
- 사이트맵 캐시와 검색엔진용 화면 캐시 — 사이트맵은 요청 없이 한 번 만들어 모든 언어에 같은 파일을 냅니다. 검색엔진용 화면 캐시는
  주소·언어로 이미 나뉩니다.
- 댓글 목록 — 게시글이 아니라 댓글을 다루는 목록입니다.

예제 — 분류가 `English` 인 글은 영어 화면에서만, 그 밖의 글은 영어가 아닌 화면에서만 보입니다(게시판 `lang-board` 한정):

```php
class LanguageBoardListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.post.list_query_extensions' => ['method' => 'extensions', 'type' => 'filter'],
            'sirsoft-board.post.filter_list_cache_variant' => ['method' => 'variant', 'type' => 'filter'],
        ];
    }

    public function handle(...$args): void {}

    public function extensions(array $extensions, array $context): array
    {
        if ($context['audience'] === 'admin' || $context['context'] === 'sitemap') {
            return $extensions; // 관리자·사이트맵은 전부 보인다
        }

        $boardId = $this->boardRepository->findBySlug('lang-board')?->id;
        $isEnglish = $context['locale'] === 'en';

        $extensions[] = function (Builder $query) use ($boardId, $isEnglish) {
            $board = $query->qualifyColumn('board_id');
            $category = $query->qualifyColumn('category');

            $query->where(fn ($w) => $w->where($board, '<>', $boardId)->orWhere(
                fn ($l) => $isEnglish
                    ? $l->where($category, 'English')
                    : $l->where(fn ($n) => $n->where($category, '<>', 'English')->orWhereNull($category))
            ));
        };

        return $extensions;
    }

    public function variant(array $parts, array $context): array
    {
        $parts[] = $context['locale']; // 위 조건이 언어마다 다르므로 캐시도 언어로 나눈다

        return $parts;
    }
}
```
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.activity_log.filter_description_params` | filter | `ActivityLogDescriptionResolver` | `resolveDescriptionParams` | 10 |
| `core.module_settings.after_save` | action (미선언) | `SeoBoardSettingsCacheListener` | `onModuleSettingsSave` | 20 |
| `core.notification.filter_default_definitions` | filter | `BoardNotificationDataListener` | `contributeDefaultDefinitions` | 20 |
| `core.permission.scope_enforcement_ledger` | filter | `BoardScopeLedgerListener` | `addLedgerEntries` | 10 |
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
| `sirsoft-board.board.after_reorder` | action (미선언) | `BoardActivityLogListener` | `handleBoardAfterReorder` | 20 |
| `sirsoft-board.board.after_reorder` | action (미선언) | `SeoBoardCacheListener` | `onBoardReorder` | 20 |
| `sirsoft-board.board.after_update` | action (미선언) | `BoardActivityLogListener` | `handleBoardAfterUpdate` | 20 |
| `sirsoft-board.board.after_update` | action (미선언) | `SeoBoardCacheListener` | `onBoardUpdate` | 20 |
| `sirsoft-board.board.after_update` | action (미선언) | `SeoBoardRegenerateListener` | `onBoardUpdate` | 20 |
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
| `sirsoft-board.post.after_blind` | action (미선언) | `SeoBoardRegenerateListener` | `onPostChange` | 20 |
| `sirsoft-board.post.after_create` | action (미선언) | `BoardActivityLogListener` | `handlePostAfterCreate` | 20 |
| `sirsoft-board.post.after_create` | action (미선언) | `BoardPostsCountSyncListener` | `syncPostsCount` | 10 |
| `sirsoft-board.post.after_create` | action (미선언) | `PostReplySyncListener` | `syncRepliesCount` | 10 |
| `sirsoft-board.post.after_create` | action (미선언) | `SeoBoardCacheListener` | `onPostCreate` | 20 |
| `sirsoft-board.post.after_create` | action (미선언) | `SeoBoardRegenerateListener` | `onPostChange` | 20 |
| `sirsoft-board.post.after_delete` | action (미선언) | `BoardActivityLogListener` | `handlePostAfterDelete` | 20 |
| `sirsoft-board.post.after_delete` | action (미선언) | `BoardPostsCountSyncListener` | `syncPostsCount` | 10 |
| `sirsoft-board.post.after_delete` | action (미선언) | `PostReplySyncListener` | `syncRepliesCount` | 10 |
| `sirsoft-board.post.after_delete` | action (미선언) | `SeoBoardCacheListener` | `onPostDelete` | 20 |
| `sirsoft-board.post.after_restore` | action (미선언) | `BoardActivityLogListener` | `handlePostAfterRestore` | 20 |
| `sirsoft-board.post.after_restore` | action (미선언) | `BoardPostsCountSyncListener` | `syncPostsCount` | 10 |
| `sirsoft-board.post.after_restore` | action (미선언) | `PostReplySyncListener` | `syncRepliesCount` | 10 |
| `sirsoft-board.post.after_restore` | action (미선언) | `SeoBoardCacheListener` | `onPostUpdate` | 20 |
| `sirsoft-board.post.after_restore` | action (미선언) | `SeoBoardRegenerateListener` | `onPostChange` | 20 |
| `sirsoft-board.post.after_update` | action (미선언) | `BoardActivityLogListener` | `handlePostAfterUpdate` | 20 |
| `sirsoft-board.post.after_update` | action (미선언) | `SeoBoardCacheListener` | `onPostUpdate` | 20 |
| `sirsoft-board.post.after_update` | action (미선언) | `SeoBoardRegenerateListener` | `onPostChange` | 20 |
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
| `sirsoft-gdpr.necessary_storage.filter_provided_keys` | filter | `ProvideNecessaryStorageKeysListener` | `provide` | 10 |
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

`SeoBoardCacheListener` 의 나머지 구독(글·댓글·게시판 수정)도 sync 입니다 — 캐시 무효화와 행 한 건의
색인은 저장한 요청 안에서 하고, 본문은 `DB::afterCommit` 으로 커밋 뒤에 비웁니다. 글 상세를 미리 다시
그리는 일과 게시판 활성 상태가 바뀔 때의 게시글 대량 재색인은 `SeoBoardRegenerateListener`(큐)가 맡습니다
— 무거운 일이 저장 응답을 늦추지 않게 하면서, 비우기가 큐로 밀려 워커가 돌 때까지 옛 화면이 보이는 일을 막습니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogDescriptionResolver` | 1개 | 명시 등록 | ✅ | `src/Listeners/ActivityLogDescriptionResolver.php` |
| `BoardActivityLogListener` | 34개 | 명시 등록 | ✅ | `src/Listeners/BoardActivityLogListener.php` |
| `BoardCommentsCountSyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/BoardCommentsCountSyncListener.php` |
| `BoardNotificationChannelListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/BoardNotificationChannelListener.php` |
| `BoardNotificationDataListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/BoardNotificationDataListener.php` |
| `BoardPostsCountSyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/BoardPostsCountSyncListener.php` |
| `BoardScopeLedgerListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/BoardScopeLedgerListener.php` |
| `Ckeditor5ReferenceSourcesListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Ckeditor5ReferenceSourcesListener.php` |
| `CommentReplySyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/CommentReplySyncListener.php` |
| `EcommerceInquiryHookListener` | 10개 | 명시 등록 | ✅ | `src/Listeners/EcommerceInquiryHookListener.php` |
| `PostAttachmentCountSyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/PostAttachmentCountSyncListener.php` |
| `PostCountSyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/PostCountSyncListener.php` |
| `PostReplySyncListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/PostReplySyncListener.php` |
| `PrivacyRetentionHandlerListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/PrivacyRetentionHandlerListener.php` |
| `ProvideNecessaryStorageKeysListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ProvideNecessaryStorageKeysListener.php` |
| `SearchPostsListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/SearchPostsListener.php` |
| `SeoBoardCacheListener` | 14개 | 명시 등록 | ✅ | `src/Listeners/SeoBoardCacheListener.php` |
| `SeoBoardRegenerateListener` | 5개 | 명시 등록 | ✅ | `src/Listeners/SeoBoardRegenerateListener.php` |
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

### 사용자 화면 자리 (sirsoft-basic)

<!-- @intent START -->
이 모듈은 사용자 화면을 갖지 않습니다 — 방문자가 보는 게시판 화면은 사용자 템플릿(기준 구현 `sirsoft-basic`)이 그립니다.
그래서 확장이 게시판 사용자 화면에 표시나 입력칸을 붙이는 자리는 템플릿이 확장점으로 엽니다(7.1.1+). 같은 이름의 확장점은
모든 화면·모든 게시판 유형(basic·gallery·card)에 함께 있으므로, 확장은 이름으로 **한 번만** 등록하면 됩니다.

| 확장점 | 자리 | `context` 값 |
| --- | --- | --- |
| `board_post_row_badges` | 글이 보이는 모든 목록의 제목 바로 뒤 | `board_list` · `popular` · `home_recent` · `home_board_summary` · `user_posts` · `user_profile_recent` · `mypage_posts` · `mypage_scrap` · `search_all` · `search_posts` |
| `board_post_header_badges` | 글 상세 제목 바로 뒤 | `board_show` |
| `board_comment_row_badges` | 글 상세 댓글 머리 줄, 마이페이지 내 댓글 | `board_show_comment` · `mypage_comments` |
| `board_post_form_top` | 글쓰기 폼 맨 위(검증 오류 상자 아래) | — (`mode` 로 구분) |
| `board_post_form_bottom` | 글쓰기 폼 맨 아래(취소·저장 단추 위) | — (`mode` 로 구분) |

props 계약:

- 글 자리: `post`(행 원본) · `postId` · `boardSlug` · `context`. 상세 머리의 `post` 는 상세 응답의 `data` 다.
- 댓글 자리: `commentData`(행 원본) · `commentId` · `postId` · `boardSlug` · `context`.
- 글쓰기 자리: `mode`(`create`·`reply`·`edit`) · `boardSlug` · `postId`(새 글은 `null`) · `board`(글쓰기 틀 응답의 게시판).

글쓰기 자리에 끼운 입력칸이 이름(`name`)으로 값을 쓰면 글쓰기 폼 상태에 들어가 저장 요청(`POST`·`PUT …/posts`) 본문에 함께
실립니다. 이 모듈은 모르는 키를 저장하지 않고 무시하므로, 그 값을 저장하는 것은 확장이 게시글 저장 훅
(`sirsoft-board.post.after_create`·`after_update` 등, 위 「발행 훅」 표)으로 처리합니다. 목록·상세에 그 값을 실어 보내는 것도
조회 훅의 몫입니다. 자리마다의 놓는 곳·호스트 규칙은 코어 레이아웃 확장 문서의 「템플릿이 열어 두어야 하는 확장점」을 봅니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 미들웨어 | 부착 대상(targets) | 우선순위 |
|---|---|---|
| `EnsureBoardExists` | `api.modules.sirsoft-board.boards.posts.*`, `api.modules.sirsoft-board.boards.comments.*`, `api.modules.sirsoft-board.boards.attachments.*`, `api.modules.sirsoft-board.boards.attachment.*`, `api.modules.sirsoft-board.admin.board.*` | - |
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
