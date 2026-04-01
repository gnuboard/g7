<?php

namespace Modules\Sirsoft\Board\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Sirsoft\Board\Models\BoardMailTemplate;

/**
 * 게시판 메일 템플릿을 시딩합니다 (5종).
 */
class BoardMailTemplateSeeder extends Seeder
{
    /**
     * 게시판 메일 템플릿을 시딩합니다.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command->info('게시판 메일 템플릿 시딩 시작...');

        $templates = $this->getDefaultTemplates();

        foreach ($templates as $data) {
            BoardMailTemplate::updateOrCreate(
                ['type' => $data['type']],
                [
                    'subject' => $data['subject'],
                    'body' => $data['body'],
                    'variables' => $data['variables'],
                    'is_active' => true,
                    'is_default' => true,
                ]
            );

            $this->command->info("  - {$data['type']} 템플릿 등록 완료");
        }

        $this->command->info('게시판 메일 템플릿 시딩 완료 (' . count($templates) . '종)');
    }

    /**
     * 기본 템플릿 데이터를 반환합니다.
     *
     * @return array<int, array{type: string, subject: array, body: array, variables: array}>
     */
    public function getDefaultTemplates(): array
    {
        return [
            $this->newCommentTemplate(),
            $this->replyCommentTemplate(),
            $this->postReplyTemplate(),
            $this->postActionTemplate(),
            $this->newPostAdminTemplate(),
            $this->reportReceivedAdminTemplate(),
            $this->reportActionTemplate(),
        ];
    }

    /**
     * 새 댓글 알림 템플릿을 반환합니다.
     *
     * @return array
     */
    private function newCommentTemplate(): array
    {
        return [
            'type' => 'new_comment',
            'subject' => [
                'ko' => '[{board_name}] 게시글에 새 댓글이 등록되었습니다',
                'en' => '[{board_name}] New comment on your post',
            ],
            'body' => [
                'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                    . '<p><strong>{board_name}</strong> 게시판의 게시글에 <strong>{comment_author}</strong>님이 댓글을 남겼습니다.</p>'
                    . '<blockquote style="border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;">{comment_content}</blockquote>'
                    . $this->button('게시글 보기', '{post_url}')
                    . '<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                'en' => '<h1>Hello, {name}.</h1>'
                    . '<p><strong>{comment_author}</strong> commented on your post in <strong>{board_name}</strong>.</p>'
                    . '<blockquote style="border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;">{comment_content}</blockquote>'
                    . $this->button('View Post', '{post_url}')
                    . '<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
            ],
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
        ];
    }

    /**
     * 대댓글 알림 템플릿을 반환합니다.
     *
     * @return array
     */
    private function replyCommentTemplate(): array
    {
        return [
            'type' => 'reply_comment',
            'subject' => [
                'ko' => '[{board_name}] 댓글에 답글이 등록되었습니다',
                'en' => '[{board_name}] Reply to your comment',
            ],
            'body' => [
                'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                    . '<p><strong>{board_name}</strong> 게시판에서 <strong>{comment_author}</strong>님이 댓글에 답글을 남겼습니다.</p>'
                    . '<blockquote style="border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;">{comment_content}</blockquote>'
                    . $this->button('게시글 보기', '{post_url}')
                    . '<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                'en' => '<h1>Hello, {name}.</h1>'
                    . '<p><strong>{comment_author}</strong> replied to your comment in <strong>{board_name}</strong>.</p>'
                    . '<blockquote style="border-left: 3px solid #cbd5e0; padding-left: 12px; color: #718096;">{comment_content}</blockquote>'
                    . $this->button('View Post', '{post_url}')
                    . '<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
            ],
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
        ];
    }

    /**
     * 답변글 알림 템플릿을 반환합니다.
     *
     * @return array
     */
    private function postReplyTemplate(): array
    {
        return [
            'type' => 'post_reply',
            'subject' => [
                'ko' => '[{board_name}] 게시글에 답변글이 등록되었습니다',
                'en' => '[{board_name}] Reply to your post',
            ],
            'body' => [
                'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                    . '<p><strong>{board_name}</strong> 게시판의 게시글 "<strong>{post_title}</strong>"에 답변글이 등록되었습니다.</p>'
                    . $this->button('게시글 보기', '{post_url}')
                    . '<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                'en' => '<h1>Hello, {name}.</h1>'
                    . '<p>A reply has been posted to your post "<strong>{post_title}</strong>" in <strong>{board_name}</strong>.</p>'
                    . $this->button('View Post', '{post_url}')
                    . '<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
            ],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
        ];
    }

    /**
     * 관리자 처리 알림 템플릿을 반환합니다.
     *
     * @return array
     */
    private function postActionTemplate(): array
    {
        return [
            'type' => 'post_action',
            'subject' => [
                'ko' => '[{board_name}] 게시글이 {action_type} 처리되었습니다',
                'en' => '[{board_name}] Your post has been {action_type}',
            ],
            'body' => [
                'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                    . '<p><strong>{board_name}</strong> 게시판의 게시글 "<strong>{post_title}</strong>"이(가) 관리자에 의해 <strong>{action_type}</strong> 처리되었습니다.</p>'
                    . '<p>문의사항이 있으시면 관리자에게 연락해 주세요.</p>'
                    . $this->button('게시글 보기', '{post_url}')
                    . '<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                'en' => '<h1>Hello, {name}.</h1>'
                    . '<p>Your post "<strong>{post_title}</strong>" in <strong>{board_name}</strong> has been <strong>{action_type}</strong> by an administrator.</p>'
                    . '<p>If you have any questions, please contact the administrator.</p>'
                    . $this->button('View Post', '{post_url}')
                    . '<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
            ],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'action_type', 'description' => '처리 유형 (블라인드/삭제/복원)'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
        ];
    }

    /**
     * 관리자 새 게시글 알림 템플릿을 반환합니다.
     *
     * @return array
     */
    private function newPostAdminTemplate(): array
    {
        return [
            'type' => 'new_post_admin',
            'subject' => [
                'ko' => '[{board_name}] 새 게시글이 등록되었습니다',
                'en' => '[{board_name}] New post has been created',
            ],
            'body' => [
                'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                    . '<p><strong>{board_name}</strong> 게시판에 <strong>{post_author}</strong>님이 새 게시글을 등록했습니다.</p>'
                    . '<p>게시글 제목: <strong>{post_title}</strong></p>'
                    . $this->button('게시글 보기', '{post_url}')
                    . '<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                'en' => '<h1>Hello, {name}.</h1>'
                    . '<p><strong>{post_author}</strong> created a new post in <strong>{board_name}</strong>.</p>'
                    . '<p>Title: <strong>{post_title}</strong></p>'
                    . $this->button('View Post', '{post_url}')
                    . '<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
            ],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름 (관리자)'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'board_name', 'description' => '게시판 이름'],
                ['key' => 'post_title', 'description' => '게시글 제목'],
                ['key' => 'post_author', 'description' => '게시글 작성자'],
                ['key' => 'post_url', 'description' => '게시글 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
        ];
    }

    /**
     * 신고 처리 알림 템플릿을 반환합니다.
     *
     * @return array
     */
    private function reportActionTemplate(): array
    {
        return [
            'type' => 'report_action',
            'subject' => [
                'ko' => '[{board_name}] 회원님의 {target_type} "{post_title}"이 {action_type} 처리되었습니다',
                'en' => '[{board_name}] Your {target_type} "{post_title}" has been {action_type}',
            ],
            'body' => [
                'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                    . '<p><strong>{board_name}</strong> 게시판에서 회원님의 <strong>{target_type}</strong> "<strong>{post_title}</strong>"이(가) 신고로 인해 <strong>{action_type}</strong> 처리되었습니다.</p>'
                    . '<p>문의사항이 있으시면 관리자에게 연락해 주세요.</p>'
                    . $this->button('게시글 보기', '{post_url}')
                    . '<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                'en' => '<h1>Hello, {name}.</h1>'
                    . '<p>Your <strong>{target_type}</strong> "<strong>{post_title}</strong>" in <strong>{board_name}</strong> has been <strong>{action_type}</strong> due to reports.</p>'
                    . '<p>If you have any questions, please contact the administrator.</p>'
                    . $this->button('View Post', '{post_url}')
                    . '<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
            ],
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
        ];
    }

    /**
     * 신고 접수 관리자 알림 템플릿을 반환합니다.
     *
     * @return array
     */
    private function reportReceivedAdminTemplate(): array
    {
        return [
            'type' => 'report_received_admin',
            'subject' => [
                'ko' => '[{board_name}] "{post_title}"에 대한 신고가 접수되었습니다',
                'en' => '[{board_name}] A new report has been received for "{post_title}"',
            ],
            'body' => [
                'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                    . '<p><strong>{board_name}</strong> 게시판에서 <strong>{target_type}</strong> "<strong>{post_title}</strong>"에 대한 새 신고가 접수되었습니다.</p>'
                    . '<p>신고 사유: <strong>{reason_type}</strong></p>'
                    . $this->button('신고 관리 페이지로 이동', '{report_url}')
                    . '<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                'en' => '<h1>Hello, {name}.</h1>'
                    . '<p>A new report has been received for the <strong>{target_type}</strong> "<strong>{post_title}</strong>" in <strong>{board_name}</strong>.</p>'
                    . '<p>Reason: <strong>{reason_type}</strong></p>'
                    . $this->button('Go to Report Management', '{report_url}')
                    . '<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
            ],
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
            . '<tr><td align="center">'
            . '<a href="' . $url . '" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">'
            . $text
            . '</a>'
            . '</td></tr></table>';
    }
}
