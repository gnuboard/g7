<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * (호환 심) 옛 IDV 메시지 정의 시더 — 7.1.0 부터 아무 일도 하지 않는다.
 *
 * 본인인증 메시지는 7.1.0 에서 알림 정의(`notification_definitions`, category=identity)로 합쳐졌고
 * `NotificationDefinitionSeeder` 가 `config('core.notification_definitions')` 의 `identity.*` 항목을
 * 함께 동기화한다. 옛 표(`identity_message_definitions/templates`)는 7.1.0 업그레이드 스텝이 이관 후 드랍한다.
 *
 * 이 클래스를 지우지 않는 이유: 7.0.x 에서 7.1.0 으로 올리는 **부모 프로세스**는 업데이트 중에도
 * 메모리에 남은 옛 `CoreUpdateService::reloadCoreConfigAndResync()` 를 실행하고, 그 코드는 이 클래스를
 * `new` 한다. 클래스가 없으면 예외는 잡혀 업데이트는 진행되지만 upgrade.log 에 「IDV 메시지 정의
 * 재시딩 실패」 경고가 남아 운영자를 오도한다. 그래서 이름만 남겨 조용히 끝낸다.
 *
 * @deprecated 7.1.0 — 8.0 에서 제거. 본인인증 메시지는 NotificationDefinitionSeeder 가 동기화한다.
 */
class IdentityMessageDefinitionSeeder extends Seeder
{
    /**
     * 시드 실행 — 호환을 위해 남긴 무동작.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command?->info('본인인증 메시지는 알림 정의로 동기화됩니다 (NotificationDefinitionSeeder) — 건너뜀');
    }
}
