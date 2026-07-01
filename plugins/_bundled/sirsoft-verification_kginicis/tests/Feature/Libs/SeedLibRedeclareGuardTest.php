<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Libs;

use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * KG이니시스 SEED 라이브러리(INILib.php / KISA_SEED_CBC.php) 재선언 가드 회귀 테스트.
 *
 * 두 파일은 composer.json 의 `autoload.files` 로 부팅 시 1회 require 되어 전역 함수
 * (String2Hex 등) 와 클래스(KISA_SEED_CBC) 를 메모리에 적재한다. 플러그인 설치/업데이트
 * 과정에서 동일 파일이 재차 require 되면 "Cannot redeclare" fatal 이 발생해 설치가
 * 롤백되었다. 가드 추가(if (!function_exists()) / if (!class_exists())) 로 재로드를
 * 무해화한 것을 회귀 차단한다.
 *
 * @since 1.0.0-beta.1
 */
class SeedLibRedeclareGuardTest extends PluginTestCase
{
    /**
     * autoload 로 이미 로드된 라이브러리를 재차 require 해도 fatal 없이 통과한다.
     *
     * @return void
     */
    public function test_seed_libs_can_be_required_again_without_redeclare_fatal(): void
    {
        // autoload.files 로 부팅 시 이미 로드된 상태여야 한다.
        $this->assertTrue(function_exists('String2Hex'), 'String2Hex 가 autoload 로 적재되어야 한다');
        $this->assertTrue(function_exists('Hex2String'));
        $this->assertTrue(function_exists('encrypt_SEED'));
        $this->assertTrue(function_exists('decrypt_SEED'));
        $this->assertTrue(class_exists('KISA_SEED_CBC'), 'KISA_SEED_CBC 클래스가 autoload 로 적재되어야 한다');

        $base = dirname(__DIR__, 3).'/src/Libs';

        // 가드가 없으면 이 재 require 에서 "Cannot redeclare" fatal 이 발생한다.
        require $base.'/INILib.php';
        require $base.'/KISA_SEED_CBC.php';

        $this->assertTrue(true, '재 require 후에도 fatal 없이 도달해야 한다');
    }

    /**
     * SEED 암복호화 라운드트립이 정상 동작한다(가드 추가가 로직을 깨지 않음).
     *
     * 이 SEED 라이브러리는 호출 측이 16바이트(블록) 배수 평문을 전달하는 것을 전제로
     * 동작한다 (실사용 decrypt_SEED 입력은 KG이니시스가 보낸 블록 정렬 암호문). 테스트도
     * 16바이트 배수 평문으로 라운드트립을 검증한다.
     *
     * @return void
     */
    public function test_seed_encrypt_decrypt_roundtrip(): void
    {
        $key = base64_encode('0123456789012345');
        $iv = '0123456789012345';

        // 16바이트 배수(2블록) 평문 — 라이브러리는 자체 패딩하지 않는다.
        $plain = 'verification1234payload012345678';
        $this->assertSame(0, strlen($plain) % 16, '평문은 SEED 블록(16바이트) 배수여야 한다');

        $encrypted = encrypt_SEED($plain, $key, $iv);

        $this->assertNotSame($plain, $encrypted, '암호문은 평문과 달라야 한다');
        $this->assertSame($plain, decrypt_SEED($encrypted, $key, $iv), '복호화 결과는 원문과 같아야 한다');
    }
}
