<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Extension\HookContextCapture;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Support\SystemActor;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 큐로 간 자동 전이 훅도 작업자가 시스템이다 (S1-D16 후속)
 *
 * 자동 전이 안에서 발화한 훅이 큐로 가면 `HookContextCapture::capture()` 가 `user_id` null 을 싣는다.
 * 그런데 워커는 오래 사는 프로세스이고 코어 `restore()` 는 `user_id` 가 비어 있으면 인증 사용자를
 * **건드리지 않는다** — 같은 워커가 앞서 처리한 작업의 사용자가 그대로 남아 그 사람이 작업자로
 * 기록됐다(2026-10-07 재실측: 자동 진행 취소의 「취소신청서 상태 변경」 줄만 고객).
 *
 * 모듈은 시스템 범위에서 캡처한 컨텍스트에 표지를 싣고, 복원 때 그 표지를 보면 작업자를 비운다.
 * 코어의 잔존 자체는 코어 결함으로 따로 보고한다.
 */
class SystemActorContextListenerTest extends ModuleTestCase
{
    /**
     * 시스템 범위에서 캡처한 컨텍스트는 표지를 싣고 범위 밖은 싣지 않는다.
     *
     * @scenario actor=auto_approve
     *
     * @effects queued_auto_transition_hooks_restore_as_system
     *
     * @return void 반환값 없음
     */
    public function test_capture_marks_only_the_system_scope(): void
    {
        $this->actingAs($this->createUser());

        $outside = HookContextCapture::capture();
        $inside = SystemActor::run(fn () => HookContextCapture::capture());

        $this->assertEmpty($outside['sirsoft_ecommerce_system_actor'] ?? null, '범위 밖에 표지가 실렸다.');
        $this->assertTrue($inside['sirsoft_ecommerce_system_actor'] ?? false, '시스템 범위 표지가 실리지 않았다.');
        $this->assertNull($inside['user_id']);
    }

    /**
     * 표지가 실린 작업을 복원하면 워커에 남아 있던 사용자가 작업자가 되지 않는다.
     *
     * @scenario actor=auto_approve
     *
     * @effects queued_auto_transition_hooks_restore_as_system
     *
     * @return void 반환값 없음
     */
    public function test_restore_clears_the_leftover_worker_user(): void
    {
        $context = SystemActor::run(fn () => HookContextCapture::capture());

        // 같은 워커가 앞서 처리한 작업의 사용자가 남아 있는 상태.
        $this->actingAs($this->createUser());

        HookContextCapture::restore($context);

        $this->assertNull(Auth::id(), '앞 작업의 사용자가 자동 전이의 작업자로 남았다.');
    }
}
