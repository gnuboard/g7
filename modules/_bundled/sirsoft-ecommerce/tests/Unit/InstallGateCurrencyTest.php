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
    public function test_currency_listeners_are_module_owned(): void
    {
        $module = new Module;
        $listeners = $module->getHookListeners();

        // 모듈 리스너 목록에 등록 → 미설치(모듈 비활성) 시 자동 미등록
        $this->assertContains(UserCurrencyInfoListener::class, $listeners);
        $this->assertContains(AssignDefaultCurrencyOnRegisterListener::class, $listeners);
    }

    public function test_admin_currency_field_is_layout_extension(): void
    {
        // 관리자 통화 필드는 코어 폼 하드코딩이 아니라 모듈 layout_extension JSON 으로 주입
        $extPath = dirname(__DIR__, 2).'/resources/extensions/admin-user-currency-field.json';
        $this->assertFileExists($extPath);

        $ext = json_decode(file_get_contents($extPath), true);
        $this->assertSame('admin_user_form', $ext['target_layout']);
        // 코어 확장 슬롯(extension_form_content)으로 주입 — 코어 폼 본체 미변경
        $this->assertSame('extension_form_content', $ext['injections'][0]['target_id']);
    }

    public function test_user_profile_table_is_module_migration(): void
    {
        // user-profile 테이블은 모듈 마이그레이션 → 미설치 시 테이블 부재
        $migrationPath = dirname(__DIR__, 2).'/database/migrations';
        $files = glob($migrationPath.'/*_create_ecommerce_user_profiles_table.php');

        $this->assertNotEmpty($files, 'ecommerce_user_profiles 마이그레이션이 모듈에 존재해야 합니다.');
    }

    public function test_user_currency_permission_is_module_owned(): void
    {
        $module = new Module;
        $permissions = $module->getPermissions();

        $identifiers = collect($permissions['categories'] ?? [])->pluck('identifier')->all();
        $this->assertContains('user-currency', $identifiers, '회원 통화 관리 권한이 모듈에 정의되어야 합니다.');
    }
}
