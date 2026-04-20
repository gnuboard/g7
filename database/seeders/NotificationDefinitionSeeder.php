<?php

namespace Database\Seeders;

use App\Extension\Helpers\NotificationSyncHelper;
use Illuminate\Database\Seeder;

class NotificationDefinitionSeeder extends Seeder
{
    /**
     * 코어 알림 정의 및 템플릿을 시딩합니다.
     *
     * 데이터 정합성 (upsert + stale cleanup) 은 NotificationSyncHelper 에 위임하며,
     * 사용자 수정(user_overrides) 은 helper 내부 HasUserOverrides trait 를 통해 보존됩니다.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command?->info('코어 알림 정의 시딩 시작...');

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

        // 완전 동기화: seeder 에서 제거된 코어 definition 삭제 (cascade 로 template 도 정리)
        $helper->cleanupStaleDefinitions('core', 'core', $definedTypes);

        $this->command?->info('코어 알림 정의 시딩 완료 ('.count($definitions).'종)');
    }

    /**
     * 코어 기본 알림 정의 데이터를 반환합니다.
     *
     * @return array
     */
    public function getDefaultDefinitions(): array
    {
        return [
            $this->welcomeDefinition(),
            $this->resetPasswordDefinition(),
            $this->passwordChangedDefinition(),
        ];
    }

    /**
     * 회원가입 환영 알림 정의.
     *
     * @return array
     */
    private function welcomeDefinition(): array
    {
        return [
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '회원가입 환영', 'en' => 'Welcome'],
            'description' => ['ko' => '회원가입 완료 시 발송되는 환영 알림', 'en' => 'Welcome notification sent upon registration'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.auth.after_register'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '로그인 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 회원가입을 환영합니다',
                        'en' => '[{app_name}] Welcome to Our Service',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 환영합니다!</h1>'
                            .'<p>{app_name}에 가입해 주셔서 감사합니다.</p>'
                            .'<p>이제 모든 서비스를 이용하실 수 있습니다. 아래 버튼을 클릭하여 로그인해 주세요.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">로그인하기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Welcome, {name}!</h1>'
                            .'<p>Thank you for joining {app_name}.</p>'
                            .'<p>You now have access to all our services. Click the button below to log in.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Log In</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '회원가입을 환영합니다', 'en' => 'Welcome to our service'],
                    'body' => ['ko' => '{name}님, {app_name}에 가입해 주셔서 감사합니다.', 'en' => 'Welcome {name}, thank you for joining {app_name}.'],
                    'click_url' => '/mypage',
                ],
            ],
        ];
    }

    /**
     * 비밀번호 재설정 알림 정의.
     *
     * @return array
     */
    private function resetPasswordDefinition(): array
    {
        return [
            'type' => 'reset_password',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '비밀번호 재설정', 'en' => 'Password Reset'],
            'description' => ['ko' => '비밀번호 재설정 요청 시 발송되는 알림', 'en' => 'Notification sent when password reset is requested'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.auth.after_reset_password_request'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '비밀번호 재설정 URL'],
                ['key' => 'expire_minutes', 'description' => '링크 만료 시간(분)'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 비밀번호 재설정 안내',
                        'en' => '[{app_name}] Password Reset Request',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p>비밀번호 재설정 요청을 받았습니다. 아래 버튼을 클릭하여 비밀번호를 재설정해 주세요.</p>'
                            .'<p>이 링크는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">비밀번호 재설정</a></td></tr></table>'
                            .'<p>비밀번호 재설정을 요청하지 않으셨다면, 이 이메일을 무시해 주세요.</p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>We received a request to reset your password. Click the button below to set a new password.</p>'
                            .'<p>This link will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Reset Password</a></td></tr></table>'
                            .'<p>If you did not request a password reset, please ignore this email.</p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '비밀번호 재설정 안내', 'en' => 'Password Reset Request'],
                    'body' => ['ko' => '{name}님, 비밀번호 재설정이 요청되었습니다. 본인이 요청하지 않았다면 이 알림을 무시해 주세요.', 'en' => '{name}, a password reset has been requested. If you did not request this, please ignore this notification.'],
                    'click_url' => '{action_url}',
                ],
            ],
        ];
    }

    /**
     * 비밀번호 변경 완료 알림 정의.
     *
     * @return array
     */
    private function passwordChangedDefinition(): array
    {
        return [
            'type' => 'password_changed',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '비밀번호 변경', 'en' => 'Password Changed'],
            'description' => ['ko' => '비밀번호 변경 완료 시 발송되는 알림', 'en' => 'Notification sent when password is changed'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.auth.after_password_changed'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '로그인 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 비밀번호가 변경되었습니다',
                        'en' => '[{app_name}] Your Password Has Been Changed',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p>계정의 비밀번호가 성공적으로 변경되었습니다.</p>'
                            .'<p><strong>본인이 변경하지 않았다면, 즉시 고객 지원팀에 문의하시기 바랍니다.</strong></p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">로그인하기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>Your account password has been successfully changed.</p>'
                            .'<p><strong>If you did not make this change, please contact our support team immediately.</strong></p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Log In</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '비밀번호가 변경되었습니다', 'en' => 'Your password has been changed'],
                    'body' => ['ko' => '{name}님, 비밀번호가 변경되었습니다. 본인이 변경하지 않았다면 즉시 고객 지원팀에 문의하시기 바랍니다.', 'en' => '{name}, your password has been changed. If you did not make this change, please contact support immediately.'],
                    'click_url' => '/mypage/change-password',
                ],
            ],
        ];
    }
}
