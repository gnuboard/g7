<?php

namespace Tests\Unit\DisplayName;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\DisplayName\DisplayNameArea;
use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameKindRegistry;
use App\DisplayName\DisplayNameResolver;
use App\DisplayName\DisplayNameSettings;
use App\DisplayName\Kinds\AbstractDisplayNameKind;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Support\NameMasker;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 표시 이름 계산기 — 종류 6개, 대체 순서, 영역 상속 사슬, 회원이 고른 값, 탈퇴 회원과 비회원, 설정에 없는 깊은 키, 확장 등록.
 */
class DisplayNameResolverTest extends TestCase
{
    use RefreshDatabase;

    private DisplayNameResolver $resolver;

    /**
     * 코어 회원 항목을 시딩하고 계산기를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreFieldDefinitionSeeder::class);
        $this->resolver = app(DisplayNameResolver::class);
    }

    /**
     * 테스트가 건 필터를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.display_name.filter_resolved');
        HookManager::clearFilter('core.display_name.filter_kinds');
        HookManager::clearFilter('core.display_name.filter_areas');
        parent::tearDown();
    }

    /**
     * 표시 이름 설정을 바꿉니다.
     *
     * @param  array<string, mixed>  $member  회원 범주 설정 (display_name_* 키)
     * @return void 반환값 없음
     */
    private function settings(array $member): void
    {
        foreach ($member as $key => $value) {
            config(["g7_settings.core.member.{$key}" => $value]);
        }
    }

    /**
     * 사이트 기본 영역을 종류 하나로 고정합니다.
     *
     * @param  string  $kind  종류 키
     * @return void 반환값 없음
     */
    private function fixSite(string $kind): void
    {
        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => $kind]]]);
    }

    /**
     * 저장하지 않은 회원을 만듭니다 (컬럼 값만으로 계산되는 종류용).
     *
     * @param  string|null  $name  이름
     * @param  string|null  $nickname  닉네임
     * @param  string  $email  이메일
     * @return User 회원
     */
    private function person(?string $name, ?string $nickname, string $email = 'gildong@example.com'): User
    {
        return (new User)->forceFill(['name' => $name, 'nickname' => $nickname, 'email' => $email, 'status' => UserStatus::Active->value]);
    }

    /**
     * 표시 이름으로 쓸 수 있는 추가 항목을 만듭니다.
     *
     * @param  string  $key  항목 키
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return FieldDefinition 만든 항목
     */
    private function displayField(string $key, array $attributes = []): FieldDefinition
    {
        $definition = FieldDefinition::create(array_merge([
            'field_set' => FieldSet::CORE_USER, 'key' => $key, 'type' => 'text', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => '활동명', 'en' => 'Alias'],
            'enabled' => true, 'display_name_usable' => true, 'sort_order' => 900,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition;
    }

    /**
     * 회원의 추가 항목 값을 저장합니다.
     *
     * @param  User  $user  회원
     * @param  string  $key  항목 키
     * @param  string  $value  값
     * @return void 반환값 없음
     */
    private function setFieldValue(User $user, string $key, string $value): void
    {
        app(FieldValueService::class)->write($user, FieldSet::CORE_USER, [$key => $value], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user));
    }

    /**
     * 가림 규칙: 이름은 첫 글자와 끝 글자만, 아이디는 앞 3자만 남긴다. 미리보기와 계산기가 같은 결과를 쓴다.
     *
     * @scenario case=masker
     *
     * @effects name_masked_keeps_first_and_last, id_masked_keeps_first_three
     *
     * @return void 반환값 없음
     */
    public function test_masker_rules(): void
    {
        $this->assertSame(['홍*동', '홍*', '홍', '남****수', null, null], [
            NameMasker::maskName('홍길동'), NameMasker::maskName('홍길'), NameMasker::maskName('홍'),
            NameMasker::maskName('남궁민수영수'), NameMasker::maskName(''), NameMasker::maskName(null),
        ]);
        $this->assertSame(['gil***', 'gil***', 'a***', 'a***', null], [
            NameMasker::maskId('gildong@example.com'), NameMasker::maskId('gildong'), NameMasker::maskId('abc@example.com'),
            NameMasker::maskId('a@example.com'), NameMasker::maskId(null),
        ]);
    }

    /**
     * 코어 종류 다섯 개는 이름, 닉네임, 이메일만으로 계산된다.
     *
     * @scenario case=core_kinds
     *
     * @effects kind_name_shows_name, kind_nickname_shows_nickname, kind_masked_name_masks_name, kind_masked_id_masks_email_local_part, kind_name_nickname_combines_both
     *
     * @return void 반환값 없음
     */
    public function test_core_kinds_compute_from_columns(): void
    {
        $user = $this->person('홍길동', '길동이');

        $expected = [
            'name' => '홍길동', 'nickname' => '길동이', 'masked_name' => '홍*동',
            'masked_id' => 'gil***', 'name_nickname' => '홍길동(길동이)',
        ];
        foreach ($expected as $kind => $shown) {
            $this->fixSite($kind);
            $this->assertSame($shown, $this->resolver->resolve($user), "종류 {$kind}");
            $this->assertSame($kind, $this->resolver->effectiveKind($user));
        }

        $kinds = app(DisplayNameKindRegistry::class);
        $this->assertSame(['name', 'nickname', 'masked_name', 'masked_id', 'name_nickname'], array_keys($kinds->staticKinds()));
        $this->assertSame([true, true, false, false, false], [
            $kinds->get('nickname')->requiresNickname(), $kinds->get('name_nickname')->requiresNickname(),
            $kinds->get('name')->requiresNickname(), $kinds->get('masked_name')->requiresNickname(), $kinds->get('masked_id')->requiresNickname(),
        ]);
        $this->assertSame([[], []], [$kinds->get('masked_name')->searchColumns(), $kinds->get('masked_id')->searchColumns()], '가린 종류가 검색 컬럼을 내줬다');
    }

    /**
     * 추가 항목 종류(`field:{키}`)는 값 테이블의 값을 보인다. 「표시 이름으로 사용 가능」을 끄거나 관리자만 열람으로 바꾸면 종류에서 빠진다.
     *
     * @scenario case=field_kind
     *
     * @effects kind_field_shows_custom_field_value, field_kind_requires_display_name_usable_flag, admin_only_field_is_not_a_kind
     *
     * @return void 반환값 없음
     */
    public function test_field_kind_shows_custom_field_value(): void
    {
        $field = $this->displayField('alias');
        $user = User::factory()->create(['name' => '홍길동', 'nickname' => '길동이', 'email' => 'gildong@example.com']);
        $this->setFieldValue($user, 'alias', '바람의 검객');

        $this->fixSite('field:alias');
        $kinds = app(DisplayNameKindRegistry::class);
        $this->assertTrue($kinds->has('field:alias'));
        $this->assertSame('활동명', $kinds->get('field:alias')->label('ko'));
        $this->assertSame('바람의 검객', $this->resolver->resolve($user));

        // 표시 이름으로 쓰지 않게 하면 종류가 사라지고, 그 종류를 가리키던 설정은 「이름」으로 계산된다
        $field->update(['display_name_usable' => false]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
        $this->assertFalse($kinds->has('field:alias'));
        $this->assertSame('홍길동', $this->resolver->resolve($user));

        $field->update(['display_name_usable' => true, 'edit_scope' => FieldDefinition::SCOPE_ADMIN_ONLY]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
        $this->assertFalse($kinds->has('field:alias'), '관리자만 열람 항목이 표시 이름 종류가 됐다');
    }

    /**
     * 값이 없으면 대체 순서를 따른다. 닉네임이 비었을 때 이름으로 대신하지 않는다(실명이 새지 않게).
     *
     * @scenario case=fallback_order
     *
     * @effects name_falls_back_to_nickname_then_masked_id, nickname_never_falls_back_to_name, masked_name_falls_back_to_masked_id, name_nickname_falls_back_to_name_chain, field_falls_back_to_nickname_then_masked_id
     *
     * @return void 반환값 없음
     */
    public function test_fallback_order(): void
    {
        $this->displayField('alias');
        $noName = $this->person(null, '길동이');
        $noNickname = $this->person('홍길동', null);
        $neither = $this->person(null, '');

        $cases = [
            // 종류 => [이름 없음, 닉네임 없음, 둘 다 없음]
            'name' => ['길동이', '홍길동', 'gil***'],
            'nickname' => ['길동이', 'gil***', 'gil***'],
            'masked_name' => ['gil***', '홍*동', 'gil***'],
            'name_nickname' => ['길동이', '홍길동', 'gil***'],
            'field:alias' => ['길동이', 'gil***', 'gil***'],
            'masked_id' => ['gil***', 'gil***', 'gil***'],
        ];
        foreach ($cases as $kind => [$withoutName, $withoutNickname, $withoutBoth]) {
            $this->assertSame(
                [$withoutName, $withoutNickname, $withoutBoth],
                [$this->resolver->computeAs($noName, $kind), $this->resolver->computeAs($noNickname, $kind), $this->resolver->computeAs($neither, $kind)],
                "종류 {$kind} 의 대체 순서",
            );
        }

        $chain = array_map(fn ($kind) => $kind->key(), app(DisplayNameKindRegistry::class)->chain('name_nickname'));
        $this->assertSame(['name_nickname', 'name', 'nickname', 'masked_id'], $chain);
    }

    /**
     * 영역은 자기 설정 → 부모 → … → 사이트 기본 순서로 따라간다. 인스턴스 설정(게시판마다 등)이 영역 설정보다 먼저다.
     *
     * @scenario case=area_inheritance
     *
     * @effects area_inherits_parent_setting, area_own_setting_wins_over_parent, instance_setting_wins_over_area, notification_channel_inherits_notification, unknown_area_uses_site_default
     *
     * @return void 반환값 없음
     */
    public function test_area_inheritance_chain(): void
    {
        $areas = app(DisplayNameAreaRegistry::class);
        $areas->register(new DisplayNameArea(
            key: 'demo-board:posts',
            label: ['ko' => '게시판 글'],
            ownerType: 'module',
            ownerIdentifier: 'demo-board',
            parent: 'core:site',
            instanceResolver: fn (array $context) => ($context['board'] ?? null) === 'anonymous' ? ['mode' => 'fixed', 'kind' => 'masked_id'] : null,
        ));
        $areas->register(new DisplayNameArea(
            key: 'demo-board:admin',
            label: ['ko' => '게시판 관리'],
            ownerType: 'module',
            ownerIdentifier: 'demo-board',
            parent: 'core:admin',
            audience: DisplayNameArea::AUDIENCE_ADMIN,
        ));
        $user = $this->person('홍길동', '길동이');

        $this->settings(['display_name_areas' => [
            'core:site' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'name_nickname'],
        ]]);

        // 따름: 부모의 값
        $this->assertSame('길동이', $this->resolver->resolve($user, 'demo-board:posts'));
        $this->assertSame('홍길동(길동이)', $this->resolver->resolve($user, 'demo-board:admin'));
        $this->assertSame('core:admin', $this->resolver->areaRule('demo-board:admin')['source']);
        // 알림 채널 → 알림 → 사이트 기본
        $this->assertSame(
            ['core:notification:mail', 'core:notification', 'core:site'],
            array_map(fn (DisplayNameArea $area) => $area->key, $areas->lineage('core:notification:mail')),
        );
        $this->assertSame('길동이', $this->resolver->resolve($user, 'core:notification:mail'));

        // 자기 설정이 부모보다 먼저
        $this->settings(['display_name_areas' => [
            'core:site' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'core:notification' => ['mode' => 'fixed', 'kind' => 'name'],
            'demo-board:posts' => ['mode' => 'fixed', 'kind' => 'masked_name'],
        ]]);
        $this->assertSame('홍*동', $this->resolver->resolve($user, 'demo-board:posts'));
        $this->assertSame('홍길동', $this->resolver->resolve($user, 'core:notification:mail'));

        // 인스턴스 설정이 영역 설정보다 먼저
        $this->assertSame('gil***', $this->resolver->resolve($user, 'demo-board:posts', ['board' => 'anonymous']));
        $this->assertSame('홍*동', $this->resolver->resolve($user, 'demo-board:posts', ['board' => 'free']));

        // 등록되지 않은 영역 키는 사이트 기본으로 계산한다
        $this->assertSame('길동이', $this->resolver->resolve($user, 'gone-extension:reviews'));
    }

    /**
     * 「회원이 고른 값」 방식은 회원이 고른 종류로 보인다. 고르지 않았거나 고른 종류가 허용에서 빠졌으면 회원 기본 종류다.
     *
     * @scenario case=member_mode
     *
     * @effects member_mode_uses_chosen_kind, member_mode_uses_default_when_not_chosen, member_mode_uses_default_when_choice_no_longer_selectable
     *
     * @return void 반환값 없음
     */
    public function test_member_mode_uses_the_members_choice(): void
    {
        $this->settings([
            'display_name_selectable_kinds' => ['name', 'nickname', 'masked_name'],
            'display_name_member_default_kind' => 'masked_name',
            'display_name_areas' => ['core:site' => ['mode' => 'member']],
        ]);

        $chose = $this->person('홍길동', '길동이')->forceFill(['display_name_kind' => 'nickname']);
        $notChosen = $this->person('홍길동', '길동이');
        $removed = $this->person('홍길동', '길동이')->forceFill(['display_name_kind' => 'name_nickname']);

        $this->assertSame(['길동이', '홍*동', '홍*동'], [
            $this->resolver->resolve($chose), $this->resolver->resolve($notChosen), $this->resolver->resolve($removed),
        ]);
        $this->assertSame(['nickname', 'masked_name', 'masked_name'], [
            $this->resolver->effectiveKind($chose), $this->resolver->effectiveKind($notChosen), $this->resolver->effectiveKind($removed),
        ]);
        $this->assertSame(['name', 'nickname', 'masked_name'], $this->resolver->candidateKinds());

        // 고정 영역에서는 회원이 고른 값이 쓰이지 않는다
        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'member'], 'core:admin' => ['mode' => 'fixed', 'kind' => 'name']]]);
        $this->assertSame('홍길동', $this->resolver->resolve($chose, DisplayNameAreaRegistry::AREA_ADMIN));
    }

    /**
     * 탈퇴한 회원은 종류와 무관하게 「탈퇴한 사용자」 문구로, 비회원은 넘겨받은 이름 그대로 보인다.
     *
     * @scenario case=withdrawn_and_guest
     *
     * @effects withdrawn_member_shows_fixed_label, guest_shows_given_name, guest_without_name_is_null
     *
     * @return void 반환값 없음
     */
    public function test_withdrawn_member_and_guest(): void
    {
        $withdrawn = $this->person('홍길동_탈퇴_20261112', '길동이_탈퇴_7')->forceFill(['status' => UserStatus::Withdrawn->value]);

        foreach (['name', 'nickname', 'masked_id'] as $kind) {
            $this->fixSite($kind);
            $this->assertSame(__('user.withdrawn_user'), $this->resolver->resolve($withdrawn), "종류 {$kind}");
        }

        $this->fixSite('masked_name');
        $this->assertSame('지나가던 손님', $this->resolver->resolve(null, 'core:site', ['guest_name' => '지나가던 손님']));
        $this->assertNull($this->resolver->resolve(null));
        $this->assertNull($this->resolver->resolve(null, 'core:site', ['guest_name' => '  ']));
    }

    /**
     * 저장된 영역 묶음에 없는 영역은 선언 기본값으로 읽고, 지금 없는 종류를 가리키는 값은 따름으로 읽는다.
     *
     * @scenario case=deep_key_defaults
     *
     * @effects missing_area_setting_uses_declared_default, unknown_kind_setting_falls_back_to_parent, site_area_never_inherits, empty_selectable_kinds_fall_back_to_default_kind
     *
     * @return void 반환값 없음
     */
    public function test_missing_deep_keys_use_declared_defaults(): void
    {
        $areas = app(DisplayNameAreaRegistry::class);
        $areas->register(new DisplayNameArea(
            key: 'demo-shop:inquiry',
            label: ['ko' => '상품 문의'],
            ownerType: 'module',
            ownerIdentifier: 'demo-shop',
            defaultMode: DisplayNameArea::MODE_FIXED,
            defaultKind: 'masked_name',
        ));
        $settings = app(DisplayNameSettings::class);
        $user = $this->person('홍길동', '길동이');

        // 영역 묶음을 저장한 적이 있지만 그 뒤 생긴 영역은 묶음에 없다
        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'nickname']]]);
        $this->assertSame(['mode' => 'fixed', 'kind' => 'masked_name'], $settings->areaSetting($areas->get('demo-shop:inquiry')));
        $this->assertSame('홍*동', $this->resolver->resolve($user, 'demo-shop:inquiry'));
        $this->assertSame(['mode' => 'fixed', 'kind' => 'name'], $settings->areaSetting($areas->get('core:admin')), '관리자 영역의 선언 기본값이 쓰이지 않았다');

        // 지금 없는 종류(꺼진 확장, 지워진 항목)를 가리키는 값은 부모를 따른다
        $this->settings(['display_name_areas' => [
            'core:site' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'demo-shop:inquiry' => ['mode' => 'fixed', 'kind' => 'gone-extension:grade'],
        ]]);
        $this->assertSame('길동이', $this->resolver->resolve($user, 'demo-shop:inquiry'));

        // 사이트 기본 영역이 쓸 수 없는 값이면 「이름」
        $this->settings(['display_name_areas' => ['core:site' => ['mode' => 'inherit']]]);
        $this->assertSame('홍길동', $this->resolver->resolve($user));
        $this->settings(['display_name_areas' => null]);
        $this->assertSame('홍길동', $this->resolver->resolve($user));

        // 회원이 고를 수 있는 종류가 전부 사라졌으면 회원 기본 종류 하나
        $this->settings(['display_name_selectable_kinds' => ['field:deleted'], 'display_name_member_default_kind' => 'field:deleted']);
        $this->assertSame(['name'], $settings->selectableKinds());
        $this->assertSame('name', $settings->memberDefaultKind());
    }

    /**
     * 확장은 종류와 영역을 필터로 더할 수 있고, 계산 결과를 필터로 바꿀 수 있다. 형식이 맞지 않는 키는 받지 않는다.
     *
     * @scenario case=extension_registration
     *
     * @effects extension_kind_registered_through_filter, extension_area_registered_through_filter, resolved_name_passes_through_filter, invalid_kind_key_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_extensions_add_kinds_and_areas(): void
    {
        $grade = new class extends AbstractDisplayNameKind
        {
            /**
             * 종류 키를 반환합니다.
             *
             * @return string 종류 키
             */
            public function key(): string
            {
                return 'demo-rank:title';
            }

            /**
             * 종류 이름을 반환합니다.
             *
             * @param  string|null  $locale  로케일
             * @return string 종류 이름
             */
            public function label(?string $locale = null): string
            {
                return '칭호';
            }

            /**
             * 닉네임 앞에 칭호를 붙여 반환합니다.
             *
             * @param  User  $user  회원
             * @param  array<string, mixed>  $context  호출 문맥
             * @return string|null 표시 이름 (닉네임이 없으면 null)
             */
            public function compute(User $user, array $context = []): ?string
            {
                return $user->nickname ? '용사 '.$user->nickname : null;
            }
        };
        $reserved = new class extends AbstractDisplayNameKind
        {
            /**
             * 코어가 쓰는 접두의 종류 키를 반환합니다 (등록이 거절돼야 한다).
             *
             * @return string 종류 키
             */
            public function key(): string
            {
                return 'field:hijack';
            }

            /**
             * 고정 글자를 반환합니다.
             *
             * @param  User  $user  회원
             * @param  array<string, mixed>  $context  호출 문맥
             * @return string|null 표시 이름
             */
            public function compute(User $user, array $context = []): ?string
            {
                return 'x';
            }
        };

        HookManager::addFilter('core.display_name.filter_kinds', fn (array $kinds) => [...$kinds, $grade, $reserved]);
        HookManager::addFilter('core.display_name.filter_areas', fn (array $areas) => [
            ...$areas,
            'demo-rank:board' => DisplayNameArea::fromArray(['key' => 'demo-rank:board', 'label' => ['ko' => '순위표'], 'default' => ['mode' => 'fixed', 'kind' => 'demo-rank:title']], 'plugin', 'demo-rank'),
        ]);
        app(DisplayNameKindRegistry::class)->flush();
        app(DisplayNameAreaRegistry::class)->flush();

        $kinds = app(DisplayNameKindRegistry::class);
        $this->assertTrue($kinds->has('demo-rank:title'));
        $this->assertFalse($kinds->has('field:hijack'), '코어가 쓰는 접두의 종류 키를 받았다');
        $this->assertSame('용사 길동이', $this->resolver->resolve($this->person('홍길동', '길동이'), 'demo-rank:board'));
        // 값이 없으면 기본 대체 종류(아이디 가림)
        $this->assertSame('gil***', $this->resolver->resolve($this->person('홍길동', null), 'demo-rank:board'));
        $this->assertNull(DisplayNameArea::fromArray(['key' => 'has.dot:area'], 'plugin', 'demo-rank'), '점이 든 영역 키를 받았다');

        $seen = [];
        HookManager::addFilter('core.display_name.filter_resolved', function (?string $name, ?User $user, string $area, array $context) use (&$seen) {
            $seen[] = [$name, $area, $context['kind'] ?? null];

            return $name.' 님';
        });
        $this->assertSame('홍길동 님', $this->resolver->resolve($this->person('홍길동', '길동이')));
        $this->assertSame([['홍길동', 'core:site', 'name']], $seen);
    }

    /**
     * 여러 회원을 그릴 때 추가 항목 값은 쿼리 한 번으로 읽는다. 추가 항목 종류를 쓰지 않는 영역은 쿼리하지 않는다.
     *
     * @scenario case=preload
     *
     * @effects resolve_many_reads_field_values_in_one_query, column_kinds_need_no_query, display_columns_cover_core_kinds
     *
     * @return void 반환값 없음
     */
    public function test_resolve_many_preloads_field_values_once(): void
    {
        $this->displayField('alias');
        $users = User::factory()->count(5)->create();
        foreach ($users as $index => $user) {
            $this->setFieldValue($user, 'alias', '활동명'.$index);
        }
        $loaded = User::query()->whereKey($users->modelKeys())->orderBy('id')->get(User::DISPLAY_COLUMNS);

        $this->fixSite('field:alias');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $names = $this->resolver->resolveMany($loaded);
        $fieldQueries = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'field_values'));
        DB::disableQueryLog();

        $this->assertSame(['활동명0', '활동명1', '활동명2', '활동명3', '활동명4'], array_values($names));
        $this->assertCount(1, $fieldQueries, '추가 항목 값을 회원마다 읽었다');

        // 컬럼만 쓰는 종류는 표시 이름 컬럼만 읽은 모델로 쿼리 없이 계산된다
        $this->fixSite('name_nickname');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->resolver->resolveMany($loaded);
        $userQueries = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'users') || str_contains($query['query'], 'field_values'));
        DB::disableQueryLog();
        $this->assertCount(0, $userQueries);

        $this->assertSame('id,uuid,name,nickname,email,status,display_name_kind', User::displayColumns());
        $this->assertSame('id,uuid,name,nickname,email,status,display_name_kind,avatar', User::displayColumns('avatar', 'name'));
        $this->assertSame([], array_diff(['name', 'nickname', 'email'], $this->resolver->columnsFor()));
    }
}
