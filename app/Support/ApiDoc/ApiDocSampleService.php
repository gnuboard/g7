<?php

namespace App\Support\ApiDoc;

use App\Contracts\ApiDoc\ApiDocSampleSeeder;
use App\Models\ActivityLog;
use App\Models\LanguagePack;
use App\Models\Menu;
use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;

/**
 * API 문서 실측용 완전 샘플 데이터 서비스
 *
 * 각 API 도메인의 대표 엔티티에 모든 필드가 채워진 완전한 샘플 레코드를
 * 멱등하게 생성하고, docgen 이 상세 GET 실측에 사용할 도메인별 대표 route key
 * 맵을 제공합니다. 개발 환경 전용 — 생성된 샘플은 개발 DB 에 남습니다.
 */
class ApiDocSampleService implements ApiDocSampleSeeder
{
    /**
     * @var string 샘플 레코드 식별용 마커 (이메일/식별자 prefix)
     */
    private const MARKER = 'apidoc-sample';

    /**
     * 도메인별 완전 샘플을 멱등 생성하고 대표 route key 맵을 반환합니다.
     *
     * @return array<string, array{model: class-string, key: string, value: string}> 도메인 => 대표 레코드 정보
     */
    public function seed(): array
    {
        $map = [];

        // permissions → roles → users 순: user 가 role 을, role 이 permission 을 참조
        $map['permissions'] = $this->seedPermission();
        $map['roles'] = $this->seedRole();
        $map['users'] = $this->seedUser();
        $map['menus'] = $this->seedMenu();
        $map['notification-definitions'] = $this->seedNotificationDefinition();
        $map['schedules'] = $this->seedSchedule();
        $map['activity-logs'] = $this->seedActivityLog();
        $map['language-packs'] = $this->seedLanguagePack();
        $map['notification-logs'] = $this->seedNotificationLog();
        $map['notification-templates'] = $this->seedNotificationTemplate();

        return array_filter($map);
    }

    /**
     * 완전한 사용자 샘플을 생성하고 roles 관계를 연결합니다.
     *
     * @return array{model: class-string, key: string, value: string} 대표 레코드 정보
     */
    private function seedUser(): array
    {
        $user = User::query()->where('email', self::MARKER.'-user@example.com')->first();

        if (! $user) {
            $user = User::factory()->complete()->create([
                'name' => 'API 문서 샘플 사용자',
                'email' => self::MARKER.'-user@example.com',
            ]);

            $role = Role::query()->where('identifier', self::MARKER.'-role')->first()
                ?? Role::query()->where('identifier', 'admin')->first()
                ?? Role::query()->first()
                ?? Role::factory()->create(['identifier' => self::MARKER.'-role-fallback']);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return ['model' => User::class, 'key' => $user->getRouteKeyName(), 'value' => (string) $user->getRouteKey()];
    }

    /**
     * 완전한 역할 샘플을 생성하고 permissions 관계를 연결합니다.
     *
     * @return array{model: class-string, key: string, value: string} 대표 레코드 정보
     */
    private function seedRole(): array
    {
        $role = Role::query()->where('identifier', self::MARKER.'-role')->first();

        if (! $role) {
            $role = Role::factory()->create([
                'identifier' => self::MARKER.'-role',
                'name' => ['ko' => 'API 문서 샘플 역할', 'en' => 'API Doc Sample Role'],
                'description' => ['ko' => '문서 실측용 역할', 'en' => 'Sample role for API docs'],
                'is_active' => true,
            ]);

            $permissionIds = Permission::query()->limit(3)->pluck('id')->all();
            if ($permissionIds !== []) {
                $role->permissions()->syncWithoutDetaching($permissionIds);
            }
        }

        return ['model' => Role::class, 'key' => $role->getRouteKeyName(), 'value' => (string) $role->getRouteKey()];
    }

    /**
     * 완전한 권한 샘플(부모-자식 계층)을 생성합니다.
     *
     * @return array{model: class-string, key: string, value: string} 대표 레코드 정보
     */
    private function seedPermission(): array
    {
        $permission = Permission::query()->where('identifier', self::MARKER.'.parent')->first();

        if (! $permission) {
            $permission = Permission::factory()->create([
                'identifier' => self::MARKER.'.parent',
                'name' => ['ko' => 'API 문서 샘플 권한', 'en' => 'API Doc Sample Permission'],
                'description' => ['ko' => '문서 실측용 권한', 'en' => 'Sample permission'],
                'type' => 'admin',
            ]);

            Permission::factory()->create([
                'parent_id' => $permission->id,
                'identifier' => self::MARKER.'.child',
                'name' => ['ko' => '하위 권한', 'en' => 'Child Permission'],
                'type' => 'admin',
            ]);
        }

        return ['model' => Permission::class, 'key' => $permission->getRouteKeyName(), 'value' => (string) $permission->getRouteKey()];
    }

    /**
     * 완전한 메뉴 샘플(부모-자식)을 생성합니다.
     *
     * @return array{model: class-string, key: string, value: string} 대표 레코드 정보
     */
    private function seedMenu(): array
    {
        $menu = Menu::query()->where('slug', self::MARKER.'-menu')->first();

        if (! $menu) {
            $creator = User::query()->where('email', self::MARKER.'-user@example.com')->first();

            $menu = Menu::factory()->create([
                'name' => ['ko' => 'API 문서 샘플 메뉴', 'en' => 'API Doc Sample Menu'],
                'slug' => self::MARKER.'-menu',
                'url' => '/admin/apidoc-sample',
                'icon' => 'fas fa-book',
                'is_active' => true,
                'created_by' => $creator?->id,
            ]);

            Menu::factory()->create([
                'name' => ['ko' => '하위 메뉴', 'en' => 'Child Menu'],
                'slug' => self::MARKER.'-menu-child',
                'parent_id' => $menu->id,
                'created_by' => $creator?->id,
            ]);
        }

        return ['model' => Menu::class, 'key' => $menu->getRouteKeyName(), 'value' => (string) $menu->getRouteKey()];
    }

    /**
     * 완전한 알림 정의 샘플(템플릿 포함)을 생성합니다.
     *
     * @return array{model: class-string, key: string, value: string} 대표 레코드 정보
     */
    private function seedNotificationDefinition(): array
    {
        $definition = NotificationDefinition::query()->where('type', self::MARKER.'.event')->first();

        if (! $definition) {
            $definition = NotificationDefinition::factory()->create([
                'type' => self::MARKER.'.event',
                'name' => ['ko' => 'API 문서 샘플 알림', 'en' => 'API Doc Sample Notification'],
                'description' => ['ko' => '문서 실측용 알림 정의', 'en' => 'Sample notification'],
                'channels' => ['database', 'mail'],
                'is_active' => true,
            ]);
        }

        return ['model' => NotificationDefinition::class, 'key' => $definition->getRouteKeyName(), 'value' => (string) $definition->getRouteKey()];
    }

    /**
     * 완전한 스케줄 샘플(실행 이력 포함)을 생성합니다.
     *
     * @return array{model: class-string, key: string, value: string} 대표 레코드 정보
     */
    private function seedSchedule(): array
    {
        $schedule = Schedule::query()->where('name', 'API 문서 샘플 스케줄')->first();

        if (! $schedule) {
            $creator = $this->sampleUser();

            $schedule = Schedule::create([
                'name' => 'API 문서 샘플 스케줄',
                'description' => '문서 실측용 스케줄',
                'type' => 'artisan',
                'command' => 'cache:clear',
                'expression' => '0 3 * * *',
                'frequency' => 'daily',
                'without_overlapping' => true,
                'run_in_maintenance' => false,
                'timeout' => 300,
                'is_active' => true,
                'last_result' => 'success',
                'last_run_at' => now()->subDay(),
                'next_run_at' => now()->addDay(),
                'created_by' => $creator?->id,
            ]);
        }

        return ['model' => Schedule::class, 'key' => $schedule->getRouteKeyName(), 'value' => (string) $schedule->getRouteKey()];
    }

    /**
     * 완전한 활동 로그 샘플(actor + changes 포함)을 생성합니다.
     *
     * @return array{model: class-string, key: string, value: string} 대표 레코드 정보
     */
    private function seedActivityLog(): array
    {
        $log = ActivityLog::query()->where('description_key', 'apidoc.sample.action')->first();

        if (! $log) {
            $user = $this->sampleUser();

            $log = ActivityLog::create([
                'log_type' => 'admin',
                'loggable_type' => User::class,
                'loggable_id' => $user?->id,
                'user_id' => $user?->id,
                'action' => 'user.update',
                'description_key' => 'apidoc.sample.action',
                'description_params' => ['name' => 'API 문서 샘플'],
                'properties' => ['source' => 'apidoc'],
                'changes' => ['status' => ['old' => 'inactive', 'new' => 'active']],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'ApiDocgen/1.0',
                'created_at' => now(),
            ]);
        }

        return ['model' => ActivityLog::class, 'key' => $log->getRouteKeyName(), 'value' => (string) $log->getRouteKey()];
    }

    /**
     * 완전한 언어팩 샘플(manifest 채움)을 생성합니다.
     *
     * `scope=core` + `status=active` 조합은 그 locale 을 시스템 전역 지원 로케일로 승격시킨다
     * (LanguagePackRepository::getActiveCoreLocales → refreshSupportedLocales →
     * config('app.supported_locales'/'app.translatable_locales')).
     *
     * 문서용 샘플은 조회 예시를 만들기 위한 것이지 시스템 동작을 바꿀 자격이 없다. 샘플이 그
     * 경로를 타면 이후 시드/저장되는 모든 다국어 데이터에 실재하지 않는 로케일 키가 박히고,
     * 샘플을 비활성화한 뒤에는 그 키가 검증(TranslatableField)을 통과하지 못해 저장이 막힌다.
     *
     * 따라서 승격 경로를 벗어난 조합(module 스코프 + installed 상태)으로 생성하며, locale 도
     * 실재하는 번들 로케일만 사용한다.
     *
     * @return array{model: class-string, key: string, value: string} 대표 레코드 정보
     */
    private function seedLanguagePack(): array
    {
        $pack = LanguagePack::query()->where('identifier', 'apidoc-sample-lang')->first();

        if (! $pack) {
            $installer = $this->sampleUser();

            $pack = LanguagePack::create([
                'identifier' => 'apidoc-sample-lang',
                'vendor' => 'apidoc',
                'scope' => 'module',
                'target_identifier' => 'apidoc-sample-module',
                'locale' => 'ja',
                'locale_name' => 'Japanese',
                'locale_native_name' => '日本語',
                'text_direction' => 'ltr',
                'version' => '1.0.0',
                'latest_version' => '1.0.0',
                'license' => 'MIT',
                'description' => ['ko' => '문서 실측용 언어팩', 'en' => 'Sample language pack'],
                'status' => 'installed',
                'is_protected' => false,
                'manifest' => [
                    'name' => ['ko' => 'API 문서 샘플 언어팩', 'en' => 'API Doc Sample Pack'],
                    'version' => '1.0.0',
                    'locale' => 'ja',
                ],
                'source_type' => 'bundled',
                'installed_by' => $installer?->id,
                'installed_at' => now()->subDays(3),
                'activated_at' => null,
            ]);
        }

        return ['model' => LanguagePack::class, 'key' => $pack->getRouteKeyName(), 'value' => (string) $pack->getRouteKey()];
    }

    /**
     * 완전한 알림 로그 샘플(수신자/발신자 포함)을 생성합니다.
     *
     * @return array{model: class-string, key: string, value: string} 대표 레코드 정보
     */
    private function seedNotificationLog(): array
    {
        $log = NotificationLog::query()->where('notification_type', 'apidoc.sample.event')->first();

        if (! $log) {
            $user = $this->sampleUser();

            $log = NotificationLog::create([
                'channel' => 'mail',
                'notification_type' => 'apidoc.sample.event',
                'extension_type' => 'core',
                'extension_identifier' => '',
                'recipient_user_id' => $user?->id,
                'recipient_identifier' => $user?->email,
                'recipient_name' => $user?->name,
                'sender_user_id' => $user?->id,
                'subject' => 'API 문서 샘플 알림',
                'body' => '문서 실측용 알림 본문입니다.',
                'status' => 'sent',
                'error_message' => null,
                'source' => 'apidoc',
                'sent_at' => now()->subHour(),
            ]);
        }

        return ['model' => NotificationLog::class, 'key' => $log->getRouteKeyName(), 'value' => (string) $log->getRouteKey()];
    }

    /**
     * 완전한 알림 템플릿 샘플(정의 연결)을 생성합니다.
     *
     * @return array{model: class-string, key: string, value: string} 대표 레코드 정보
     */
    private function seedNotificationTemplate(): array
    {
        $this->seedNotificationDefinition();
        $definitionId = NotificationDefinition::query()->where('type', self::MARKER.'.event')->value('id');

        $template = NotificationTemplate::query()
            ->where('definition_id', $definitionId)
            ->where('channel', 'mail')
            ->first();

        if (! $template && $definitionId) {
            $updater = $this->sampleUser();

            $template = NotificationTemplate::create([
                'definition_id' => $definitionId,
                'channel' => 'mail',
                'subject' => 'API 문서 샘플 템플릿 제목',
                'body' => '안녕하세요 {{name}} 님, 문서 실측용 본문입니다.',
                'click_url' => '/admin/apidoc-sample',
                'recipients' => [['type' => 'role', 'value' => 'admin']],
                'is_active' => true,
                'is_default' => false,
                'updated_by' => $updater?->id,
            ]);
        }

        if (! $template) {
            return [];
        }

        return ['model' => NotificationTemplate::class, 'key' => $template->getRouteKeyName(), 'value' => (string) $template->getRouteKey()];
    }

    /**
     * 시드된 완전 샘플 사용자를 반환합니다 (연관 엔티티의 소유자/actor 용).
     *
     * @return User|null 샘플 사용자 (없으면 null)
     */
    private function sampleUser(): ?User
    {
        return User::query()->where('email', self::MARKER.'-user@example.com')->first();
    }
}
