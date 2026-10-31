<?php

namespace Tests\Unit\Support\LayoutMarkup;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `.g7.html` 컴파일러 단위 테스트의 공통 베이스.
 *
 * 하는 일은 하나 — **테스트마다 DB 커넥션을 반납한다.**
 *
 * 컴파일러는 DB 를 쓰지 않지만, 테스트가 부팅하는 앱은 확장 스캔(`g7_modules`)과 다국어
 * 메시지 조회 때문에 커넥션을 연다. 그리고 `RefreshDatabase` 를 쓰는 테스트와 달리 이 테스트들은
 * 트랜잭션을 열지 않으므로, 그 트레이트가 뒷정리로 해 주는 `disconnect()` 도 일어나지 않는다.
 * 실측하면 커넥션이 **테스트당 2개씩**(read/write 분리) 쌓여, 한 프로세스에서 70여 개를 넘기는
 * 순간 MySQL `max_connections`(151) 를 넘겨 `SQLSTATE[HY000] [1040]` 로 무더기 실패한다.
 * 증상이 컴파일러와 무관한 자리에서 터지므로(다국어 조회 쿼리) 원인을 오해하기 쉽다.
 *
 * `Tests\TestCase` 에 손대지 않는 이유: 그 클래스는 700 여 개의 `RefreshDatabase` 테스트가
 * 공유하고, 그쪽은 커넥션에 **열린 트랜잭션**이 걸려 있다. 뒷정리 순서를 잘못 건드리면 롤백이
 * 무력화되어 테스트 격리가 조용히 깨진다. 커넥션을 안전하게 끊을 수 있는 것은 트랜잭션을
 * 쓰지 않는 이쪽뿐이므로 정리 책임도 이쪽에 둔다.
 */
abstract class LayoutMarkupTestCase extends TestCase
{
    /**
     * 앱을 파기하기 전에 이 테스트가 연 커넥션을 반납합니다.
     */
    protected function tearDown(): void
    {
        DB::disconnect();

        parent::tearDown();
    }
}
