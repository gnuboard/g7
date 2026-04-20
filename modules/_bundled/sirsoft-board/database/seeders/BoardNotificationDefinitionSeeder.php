<?php

namespace Modules\Sirsoft\Board\Database\Seeders;

use App\Extension\Helpers\NotificationSyncHelper;
use Illuminate\Database\Seeder;

class BoardNotificationDefinitionSeeder extends Seeder
{
    /**
     * 게시판 알림 정의 및 템플릿을 시딩합니다.
     *
     * 데이터 정합성 (upsert + stale cleanup) 은 NotificationSyncHelper 에 위임하며,
     * 사용자 수정(user_overrides) 은 helper 내부 HasUserOverrides trait 를 통해 보존됩니다.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command?->info('게시판 알림 정의 시딩 시작...');

        $helper = app(NotificationSyncHelper::class);
        $definitions = $this->getDefaultDefinitions();
        $definedTypes = [];

        foreach ($definitions as $data) {
            $definition = $helper->syncDefinition($data);
            $definedTypes[] = $definition->type;

            $definedChannels = [];
            foreach ($data['templates'] as $template) {
                $helper->syncTemplate($definition->id, $template);
                $definedChannels[] = $template['channel'];
            }

            // 완전 동기화: seeder 에서 제거된 channel 의 template 삭제
            $helper->cleanupStaleTemplates($definition->id, $definedChannels);

            $this->command?->info("  - {$data['type']} 알림 정의 등록 완료");
        }

        // 완전 동기화: seeder 에서 제거된 게시판 definition 삭제 (cascade 로 template 도 정리)
        $helper->cleanupStaleDefinitions('module', 'sirsoft-board', $definedTypes);

        $this->command?->info('게시판 알림 정의 시딩 완료 ('.count($definitions).'종)');
    }

    /**
     * 게시판 기본 알림 정의 데이터를 반환합니다.
     *
     * @return array
     */
    public function getDefaultDefinitions(): array
    {
        return [
            $this->newCommentDefinition(),
            $this->replyCommentDefinition(),
            $this->postReplyDefinition(),
            $this->postActionDefinition(),
            $this->newPostAdminDefinition(),
            $this->reportReceivedAdminDefinition(),
            $this->reportActionDefinition(),
        ];
    }

    /**
     * 새 댓글 알림 정의.
     *
     * @return array
     */
    private function newCommentDefinition(): array
    {
        return [
            'type' => 'new_comment',
            'hook_prefix' => 'sirsoft-board',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-board',
            'name' => ['ko' => '새 댓글 알림', 'en' => 'New Comment Notification'],
            'description' => ['ko' => '게시글에 새 댓글이 작성되면 게시글 작성자에게 발송', 'en' => 'Sent to post author when a new comment is posted'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.comment.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'comment_author', 'description' => '댓글 작성자'],
                ['key' => 'comment_content', 'description' => '댓글 내용 (200자)'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author', 'exclude_trigger_user' => true]],
                    'subject' => [
                        'ko' => '[{board_name}] 게시글에 새 댓글이 등록되었습니다',
                        'en' => '[{board_name}] New comment on your post',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판의 게시글에 <strong>{comment_author}</strong>님이 댓글을 남겼습니다.</p>'
                            .'<blockquote style="border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;">{comment_content}</blockquote>'
                            .$this->button('게시글 보기', '{post_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p><strong>{comment_author}</strong> commented on your post in <strong>{board_name}</strong>.</p>'
                            .'<blockquote style="border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;">{comment_content}</blockquote>'
                            .$this->button('View Post', '{post_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '게시글에 새 댓글이 달렸습니다', 'en' => 'New comment on your post'],
                    'body' => ['ko' => '{comment_author}님이 \'{board_name}\' 게시글 \'{post_title}\'에 댓글을 남겼습니다.', 'en' => '{comment_author} commented on your post \'{post_title}\' in \'{board_name}\'.'],
                    'click_url' => '{post_url}',
                ],
            ],
        ];
    }

    /**
     * 대댓글 알림 정의.
     *
     * @return array
     */
    private function replyCommentDefinition(): array
    {
        return [
            'type' => 'reply_comment',
            'hook_prefix' => 'sirsoft-board',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-board',
            'name' => ['ko' => '대댓글 알림', 'en' => 'Reply Comment Notification'],
            'description' => ['ko' => '댓글에 대댓글이 작성되면 댓글 작성자에게 발송', 'en' => 'Sent to comment author when a reply is posted'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.comment.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'comment_author', 'description' => '답글 작성자'],
                ['key' => 'comment_content', 'description' => '답글 내용 (200자)'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'parent_comment_author', 'exclude_trigger_user' => true]],
                    'subject' => [
                        'ko' => '[{board_name}] 댓글에 답글이 등록되었습니다',
                        'en' => '[{board_name}] Reply to your comment',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판에서 <strong>{comment_author}</strong>님이 댓글에 답글을 남겼습니다.</p>'
                            .'<blockquote style="border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;">{comment_content}</blockquote>'
                            .$this->button('게시글 보기', '{post_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p><strong>{comment_author}</strong> replied to your comment in <strong>{board_name}</strong>.</p>'
                            .'<blockquote style="border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;">{comment_content}</blockquote>'
                            .$this->button('View Post', '{post_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'parent_comment_author', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '댓글에 답글이 달렸습니다', 'en' => 'New reply to your comment'],
                    'body' => ['ko' => '{comment_author}님이 \'{board_name}\'의 댓글에 답글을 남겼습니다.', 'en' => '{comment_author} replied to your comment in \'{board_name}\'.'],
                    'click_url' => '{post_url}',
                ],
            ],
        ];
    }

    /**
     * 답변글 알림 정의.
     *
     * @return array
     */
    private function postReplyDefinition(): array
    {
        return [
            'type' => 'post_reply',
            'hook_prefix' => 'sirsoft-board',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-board',
            'name' => ['ko' => '답변글 알림', 'en' => 'Post Reply Notification'],
            'description' => ['ko' => '게시글에 답변글이 작성되면 원글 작성자에게 발송', 'en' => 'Sent to original post author when a reply post is created'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.post.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'original_post_author', 'exclude_trigger_user' => true]],
                    'subject' => [
                        'ko' => '[{board_name}] 게시글에 답변글이 등록되었습니다',
                        'en' => '[{board_name}] Reply to your post',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판의 게시글 "<strong>{post_title}</strong>"에 답변글이 등록되었습니다.</p>'
                            .$this->button('게시글 보기', '{post_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>A reply has been posted to your post "<strong>{post_title}</strong>" in <strong>{board_name}</strong>.</p>'
                            .$this->button('View Post', '{post_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'original_post_author', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '게시글에 답변이 등록되었습니다', 'en' => 'A reply has been posted to your post'],
                    'body' => ['ko' => '\'{board_name}\'의 게시글 \'{post_title}\'에 답변이 등록되었습니다.', 'en' => 'A reply has been posted to your post \'{post_title}\' in \'{board_name}\'.'],
                    'click_url' => '{post_url}',
                ],
            ],
        ];
    }

    /**
     * 게시글 처리 알림 정의.
     *
     * @return array
     */
    private function postActionDefinition(): array
    {
        return [
            'type' => 'post_action',
            'hook_prefix' => 'sirsoft-board',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-board',
            'name' => ['ko' => '게시글/댓글 처리 알림', 'en' => 'Content Action Notification'],
            'description' => ['ko' => '게시글/댓글 블라인드/삭제/복원 시 작성자에게 발송', 'en' => 'Sent to author on blind/delete/restore actions'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.post.after_blind', 'sirsoft-board.post.after_delete', 'sirsoft-board.post.after_restore', 'sirsoft-board.comment.after_blind', 'sirsoft-board.comment.after_delete', 'sirsoft-board.comment.after_restore'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'action_type', 'description' => '처리 유형 (블라인드/삭제/복원)'],
                ['key' => 'target_type', 'description' => '처리 대상 (게시글/댓글)'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author']],
                    'subject' => [
                        'ko' => '[{board_name}] \'{post_title}\' {target_type}이(가) {action_type} 처리되었습니다',
                        'en' => '[{board_name}] Your {target_type} "{post_title}" has been {action_type}',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판의 "<strong>{post_title}</strong>" {target_type}이(가) 관리자에 의해 <strong>{action_type}</strong> 처리되었습니다.</p>'
                            .'<p>문의사항이 있으시면 관리자에게 연락해 주세요.</p>'
                            .$this->button('게시글 보기', '{post_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>Your {target_type} "<strong>{post_title}</strong>" in <strong>{board_name}</strong> has been <strong>{action_type}</strong> by an administrator.</p>'
                            .'<p>If you have any questions, please contact the administrator.</p>'
                            .$this->button('View Post', '{post_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author']],
                    'subject' => ['ko' => '{target_type}이(가) 처리되었습니다', 'en' => 'Your {target_type} has been actioned'],
                    'body' => ['ko' => '\'{board_name}\'의 \'{post_title}\' {target_type}이(가) {action_type} 처리되었습니다.', 'en' => 'Your {target_type} \'{post_title}\' in \'{board_name}\' has been {action_type}.'],
                    'click_url' => '{post_url}',
                ],
            ],
        ];
    }

    /**
     * 관리자 새 게시글 알림 정의.
     *
     * @return array
     */
    private function newPostAdminDefinition(): array
    {
        return [
            'type' => 'new_post_admin',
            'hook_prefix' => 'sirsoft-board',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-board',
            'name' => ['ko' => '새 게시글 관리자 알림', 'en' => 'New Post Admin Notification'],
            'description' => ['ko' => '새 게시글 작성 시 관리자에게 발송', 'en' => 'Sent to admin when a new post is created'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.post.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름 (관리자)'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'post_author', 'description' => '게시글 작성자'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'board_managers', 'exclude_trigger_user' => true]],
                    'subject' => [
                        'ko' => '[{board_name}] 새 게시글이 등록되었습니다',
                        'en' => '[{board_name}] New post has been created',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판에 <strong>{post_author}</strong>님이 새 게시글을 등록했습니다.</p>'
                            .'<p>게시글 제목: <strong>{post_title}</strong></p>'
                            .$this->button('게시글 보기', '{post_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p><strong>{post_author}</strong> created a new post in <strong>{board_name}</strong>.</p>'
                            .'<p>Title: <strong>{post_title}</strong></p>'
                            .$this->button('View Post', '{post_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'board_managers', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '새 게시글이 등록되었습니다', 'en' => 'New post registered'],
                    'body' => ['ko' => '{post_author}님이 \'{board_name}\'에 새 게시글 \'{post_title}\'을 등록했습니다.', 'en' => '{post_author} posted \'{post_title}\' in \'{board_name}\'.'],
                    'click_url' => '{post_url}',
                ],
            ],
        ];
    }

    /**
     * 신고 접수 관리자 알림 정의.
     *
     * @return array
     */
    private function reportReceivedAdminDefinition(): array
    {
        return [
            'type' => 'report_received_admin',
            'hook_prefix' => 'sirsoft-board',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-board',
            'name' => ['ko' => '신고 접수 관리자 알림', 'en' => 'Report Received Admin Notification'],
            'description' => ['ko' => '신고 접수 시 관리자에게 발송', 'en' => 'Sent to admin when a report is received'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.report.after_create'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '신고 대상 게시글 제목'],
                ['key' => 'target_type', 'description' => '신고 대상 유형 (게시글/댓글)'],
                ['key' => 'reason_type', 'description' => '신고 사유'],
                ['key' => 'report_url', 'description' => '신고 관리 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'role', 'value' => 'admin', 'exclude_trigger_user' => true]],
                    'subject' => [
                        'ko' => '[{board_name}] "{post_title}"에 대한 신고가 접수되었습니다',
                        'en' => '[{board_name}] A new report has been received for "{post_title}"',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판에서 <strong>{target_type}</strong> "<strong>{post_title}</strong>"에 대한 새 신고가 접수되었습니다.</p>'
                            .'<p>신고 사유: <strong>{reason_type}</strong></p>'
                            .$this->button('신고 관리 페이지로 이동', '{report_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>A new report has been received for the <strong>{target_type}</strong> "<strong>{post_title}</strong>" in <strong>{board_name}</strong>.</p>'
                            .'<p>Reason: <strong>{reason_type}</strong></p>'
                            .$this->button('Go to Report Management', '{report_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'role', 'value' => 'admin', 'exclude_trigger_user' => true]],
                    'subject' => ['ko' => '신고가 접수되었습니다', 'en' => 'New report received'],
                    'body' => ['ko' => '\'{board_name}\'의 {target_type} \'{post_title}\'에 대한 신고({reason_type})가 접수되었습니다.', 'en' => 'A report ({reason_type}) on {target_type} \'{post_title}\' in \'{board_name}\' has been received.'],
                    'click_url' => '{report_url}',
                ],
            ],
        ];
    }

    /**
     * 신고 처리 결과 알림 정의.
     *
     * @return array
     */
    private function reportActionDefinition(): array
    {
        return [
            'type' => 'report_action',
            'hook_prefix' => 'sirsoft-board',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-board',
            'name' => ['ko' => '신고 처리 결과 알림', 'en' => 'Report Action Notification'],
            'description' => ['ko' => '신고 처리 완료 시 게시글 작성자에게 발송', 'en' => 'Sent to post author when a report action is taken'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-board.post.after_blind', 'sirsoft-board.post.after_delete', 'sirsoft-board.post.after_restore', 'sirsoft-board.comment.after_blind', 'sirsoft-board.comment.after_delete', 'sirsoft-board.comment.after_restore'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'action_type', 'description' => '처리 유형 (블라인드/삭제/복원)'],
                ['key' => 'target_type', 'description' => '처리 대상 (게시글/댓글)'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author']],
                    'subject' => [
                        'ko' => '[{board_name}] \'{post_title}\' {target_type}이(가) {action_type} 처리되었습니다',
                        'en' => '[{board_name}] Your {target_type} "{post_title}" has been {action_type}',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p><strong>{board_name}</strong> 게시판의 "<strong>{post_title}</strong>" {target_type}이(가) 신고 처리에 의해 <strong>{action_type}</strong> 처리되었습니다.</p>'
                            .'<p>문의사항이 있으시면 관리자에게 연락해 주세요.</p>'
                            .$this->button('게시글 보기', '{post_url}')
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>Your {target_type} "<strong>{post_title}</strong>" in <strong>{board_name}</strong> has been <strong>{action_type}</strong> due to reports.</p>'
                            .'<p>If you have any questions, please contact the administrator.</p>'
                            .$this->button('View Post', '{post_url}')
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'related_user', 'relation' => 'post_author']],
                    'subject' => ['ko' => '신고 처리 결과 안내', 'en' => 'Report action result'],
                    'body' => ['ko' => '\'{board_name}\'의 \'{post_title}\' {target_type}이(가) {action_type} 처리되었습니다.', 'en' => 'Your {target_type} \'{post_title}\' in \'{board_name}\' has been {action_type}.'],
                    'click_url' => '{post_url}',
                ],
            ],
        ];
    }

    /**
     * 이메일 호환 CTA 버튼 HTML을 생성합니다.
     *
     * @param string $text 버튼 텍스트
     * @param string $url 버튼 링크 URL
     * @return string 인라인 스타일 버튼 HTML
     */
    private function button(string $text, string $url): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;">'
            .'<tr><td align="center">'
            .'<a href="'.$url.'" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">'
            .$text
            .'</a>'
            .'</td></tr></table>';
    }
}
