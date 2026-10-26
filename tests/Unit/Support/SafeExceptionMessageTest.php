<?php

namespace Tests\Unit\Support;

use App\Extension\Exceptions\ExtensionSeederFailedException;
use App\Support\SafeExceptionMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 화면에 실을 예외 사유 판정 (7.1.0 전수 실측).
 */
class SafeExceptionMessageTest extends TestCase
{
    /**
     * 내부 예외 종류마다 운영 모드에서 일반 문구로 바뀌고, 사용자용 사유는 그대로 남는다.
     *
     * @scenario case=production_masks_db_exception
     *
     * @effects extension_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_internal_exceptions_are_masked_in_production(): void
    {
        config(['app.debug' => false]);
        Log::spy();

        $generic = __('extensions.errors.internal_error');
        $query = new QueryException('mysql', 'select 1', [], new \PDOException('SQLSTATE[HY000] [2002] Connection refused'));

        $this->assertSame($generic, SafeExceptionMessage::for($query));
        $this->assertSame($generic, SafeExceptionMessage::for(new \PDOException('SQLSTATE[HY000]: boom')));
        $this->assertSame($generic, SafeExceptionMessage::for(new \ErrorException('file_put_contents(C:\\srv\\app\\x): Failed to open stream')));
        $this->assertSame($generic, SafeExceptionMessage::for(new \TypeError('Argument #1 must be of type string, called in /srv/app/x.php on line 3')));
        // DB 예외 원문을 문구에 이어 붙여 다시 던진 경우
        $this->assertSame($generic, SafeExceptionMessage::for(new \RuntimeException('설치 실패: SQLSTATE[42S01]: Base table or view already exists')));

        $reason = __('modules.not_found', ['module' => 'acme-core']);
        $this->assertSame($reason, SafeExceptionMessage::for(new \RuntimeException($reason)));

        Log::shouldHaveReceived('error')->times(5);
    }

    /**
     * 디버그 모드에서는 원문을 그대로 돌려준다.
     *
     * @scenario case=debug_keeps_raw_detail
     *
     * @effects extension_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_debug_mode_returns_raw_message(): void
    {
        config(['app.debug' => true]);

        $this->assertSame('SQLSTATE[HY000]: boom', SafeExceptionMessage::for(new \PDOException('SQLSTATE[HY000]: boom')));
    }

    /**
     * 시더 실패는 운영 모드에서도 실패한 시더 이름을 남기고 원인 상세만 일반 문구로 바꾼다(계획 D2).
     *
     * 원인이 DB 예외(SQLSTATE)이든 서버 경로가 든 PHP 오류이든 시더 이름은 화면에 남고 내부 상세는 빠진다.
     *
     * @scenario case=seeder_failure_keeps_seeder_name
     *
     * @effects seeder_failure_shows_seeder_name_without_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_seeder_failure_keeps_seeder_name_and_masks_detail(): void
    {
        config(['app.debug' => false]);
        Log::spy();

        $seeder = 'Modules\\Acme\\Shop\\Database\\Seeders\\ShippingTypeSeeder';
        $expected = __('extensions.errors.seeder_failed', ['seeder' => $seeder, 'message' => __('extensions.errors.internal_error')]);

        $db = new \PDOException('SQLSTATE[42S22]: Column not found: 1054 Unknown column \'x\' (Host: db.internal)');
        $this->assertSame($expected, SafeExceptionMessage::for(new ExtensionSeederFailedException($seeder, $db->getMessage(), $db)));

        $php = new \TypeError('Argument #1 must be of type string, called in /srv/app/modules/acme-shop/x.php on line 3');
        $this->assertSame($expected, SafeExceptionMessage::for(new ExtensionSeederFailedException($seeder, $php->getMessage(), $php)));

        // 시더가 일부러 던진 사용자용 사유는 그대로
        $domain = new ExtensionSeederFailedException($seeder, '기본 배송 유형 코드가 비어 있습니다');
        $this->assertSame($domain->getMessage(), SafeExceptionMessage::for($domain));
    }
}
