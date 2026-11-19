<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit;

use Modules\Sirsoft\Ecommerce\Listeners\AssignDefaultCurrencyOnRegisterListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserCurrencyInfoListener;
use Modules\Sirsoft\Ecommerce\Module;
use Tests\PureTestCase;

/**
 * 유저별 통화 설치 게이트 검증 (A5)
 *
 * "유저별 통화 = 커머스 책임"이 구조적으로 보장되는지 확인한다:
 * - 통화 관련 리스너는 모듈 자산(미설치 시 미등록 → 가입 통화 부여/응답 주입 안 일어남).
 * - 관리자 통화 필드는 모듈의 회원 항목 선언(미설치 시 코어 회원폼에 부재).
 * - 마이페이지/관리자 UI 는 ecommerce_preferred_currency 키 게이트(미설치 시 키 부재).
 */
class InstallGateCurrencyTest extends PureTestCase
{
    /**
     * 가입 시 기본 통화 부여·회원 응답 통화 주입 리스너가 모듈 훅 리스너 목록에 들어 있어, 모듈이 꺼지면 함께 빠지는지 확인한다.
     *
     * @scenario asset=hook_listeners
     *
     * @effects currency_listeners_registered_by_module
     *
     * @return void 반환값 없음
     */
    public function test_currency_listeners_are_module_owned(): void
    {
        $module = new Module;
        $listeners = $module->getHookListeners();

        // 모듈 리스너 목록에 등록 → 미설치(모듈 비활성) 시 자동 미등록
        $this->assertContains(UserCurrencyInfoListener::class, $listeners);
        $this->assertContains(AssignDefaultCurrencyOnRegisterListener::class, $listeners);
    }

    /**
     * 관리자 회원 폼의 통화 필드가 코어 폼 하드코딩이나 주입 조각이 아니라 모듈의 회원 항목 선언인지 확인한다.
     *
     * 선언의 출처가 모듈이므로 모듈이 꺼지면 항목 등록소가 걸러 내 회원 폼에서 칸이 사라진다.
     *
     * @scenario asset=admin_form_extension
     *
     * @effects admin_currency_field_declared_as_module_member_field
     *
     * @return void 반환값 없음
     */
    public function test_admin_currency_field_is_module_member_field(): void
    {
        $fields = array_column((new Module)->getFields(), null, 'key');

        $this->assertArrayHasKey('ecommerce_preferred_currency', $fields);
        $this->assertSame('core.user', $fields['ecommerce_preferred_currency']['field_set']);
        $this->assertSame('handler', $fields['ecommerce_preferred_currency']['storage']);
        $this->assertContains('admin_update', $fields['ecommerce_preferred_currency']['ui']['surfaces']);

        // 종전의 주입 조각은 없다 — 남아 있으면 같은 칸이 회원 폼에 두 번 선다
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/resources/extensions/admin-user-currency-field.json');
    }

    /**
     * 회원별 통화를 담는 ecommerce_user_profiles 테이블 생성 마이그레이션이 모듈 안에 있어 미설치 시 테이블이 없는지 확인한다.
     *
     * @scenario asset=user_profile_migration
     *
     * @effects user_profile_table_created_by_module_migration
     *
     * @return void 반환값 없음
     */
    public function test_user_profile_table_is_module_migration(): void
    {
        // user-profile 테이블은 모듈 마이그레이션 → 미설치 시 테이블 부재
        $migrationPath = dirname(__DIR__, 2).'/database/migrations';
        $files = glob($migrationPath.'/*_create_ecommerce_user_profiles_table.php');

        $this->assertNotEmpty($files, 'ecommerce_user_profiles 마이그레이션이 모듈에 존재해야 합니다.');
    }

    /**
     * 회원 통화 관리 권한(user-currency) 카테고리가 코어가 아니라 모듈 권한 정의에 들어 있는지 확인한다.
     *
     * @scenario asset=permission
     *
     * @effects user_currency_permission_defined_by_module
     *
     * @return void 반환값 없음
     */
    public function test_user_currency_permission_is_module_owned(): void
    {
        $module = new Module;
        $permissions = $module->getPermissions();

        $identifiers = collect($permissions['categories'] ?? [])->pluck('identifier')->all();
        $this->assertContains('user-currency', $identifiers, '회원 통화 관리 권한이 모듈에 정의되어야 합니다.');
    }
}
