<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit;

use Modules\Sirsoft\Ecommerce\Listeners\AssignDefaultCurrencyOnRegisterListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserCurrencyInfoListener;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 유저별 통화 설치 게이트 검증 (A5)
 *
 * "유저별 통화 = 커머스 책임"이 구조적으로 보장되는지 확인한다:
 * - 통화 관련 리스너는 모듈 자산(미설치 시 미등록 → 가입 통화 부여/응답 주입 안 일어남).
 * - 관리자 통화 필드는 모듈 layout_extension 주입(미설치 시 코어 회원폼에 부재).
 * - 마이페이지/관리자 UI 는 ecommerce_preferred_currency 키 게이트(미설치 시 키 부재).
 */
class InstallGateCurrencyTest extends ModuleTestCase
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
     * 관리자 회원 폼의 통화 필드가 코어 폼 하드코딩이 아니라 모듈 확장 JSON 이 admin_user_form 의 extension_form_content 슬롯에 주입하는 방식인지 확인한다.
     *
     * @scenario asset=admin_form_extension
     *
     * @effects admin_currency_field_injected_via_extension_slot
     *
     * @return void 반환값 없음
     */
    public function test_admin_currency_field_is_layout_extension(): void
    {
        // 관리자 통화 필드는 코어 폼 하드코딩이 아니라 모듈 layout_extension JSON 으로 주입
        $extPath = dirname(__DIR__, 2).'/resources/extensions/admin-user-currency-field.json';
        $this->assertFileExists($extPath);

        $ext = json_decode(file_get_contents($extPath), true);
        $this->assertSame('admin_user_form', $ext['target_layout']);
        // 코어 확장 슬롯(extension_form_content)으로 주입 — 코어 폼 본체 미변경. 같은 파일이 탭 목록
        // (user_form_tabs)에도 props 를 얹으므로 순서가 아니라 슬롯 주입이 있는지로 본다.
        $this->assertContains('extension_form_content', array_column($ext['injections'], 'target_id'));
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
