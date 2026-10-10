<?php

namespace Tests\Unit\Analytics;

use App\Enums\Analytics\CollectFailureReason;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 적재 실패 사유 — 사유마다 기록하는 자리와 표시 문구가 있어야 한다.
 *
 * 사유를 더했는데 기록하는 코드가 없으면 진단 화면의 필터 칸이 늘기만 하고 영원히 비고,
 * 반대로 실제 원인이 다른 사유(예: 데이터베이스 오류)로 뭉쳐 운영자가 엉뚱한 곳을 찾는다.
 */
class CollectFailureReasonTest extends TestCase
{
    /**
     * 모든 사유는 수집 코드 어딘가에서 실제로 기록된다.
     *
     * 모집단은 열거형에서, 기록 자리는 `app/` 소스 전체에서 파생한다 — 열거형 정의 파일 자신은 뺀다.
     *
     * @scenario source=beacon
     *
     * @effects every_failure_reason_has_a_producer_and_a_label
     *
     * @return void 없음
     */
    public function test_every_reason_has_a_producer(): void
    {
        $enumFile = realpath(app_path('Enums/Analytics/CollectFailureReason.php'));
        $source = '';

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() === 'php' && $file->getRealPath() !== $enumFile) {
                $source .= $file->getContents();
            }
        }

        $this->assertNotEmpty(CollectFailureReason::cases());

        foreach (CollectFailureReason::cases() as $reason) {
            $this->assertStringContainsString(
                'CollectFailureReason::'.$reason->name,
                $source,
                $reason->value.' 사유를 기록하는 자리가 없다 — 쓰지 않을 사유면 열거형에서 거둔다',
            );
        }
    }

    /**
     * 사유마다 ko·en 표시 문구가 있고, 열거형에 없는 사유의 문구는 남아 있지 않다.
     *
     * @scenario source=beacon
     *
     * @effects every_failure_reason_has_a_producer_and_a_label
     *
     * @return void 없음
     */
    public function test_every_reason_has_labels_and_no_orphans(): void
    {
        $values = array_map(fn (CollectFailureReason $reason): string => $reason->value, CollectFailureReason::cases());
        sort($values);

        foreach (['ko', 'en'] as $locale) {
            $labels = require lang_path("{$locale}/analytics.php");
            $keys = array_keys($labels['collect_failure_reason'] ?? []);
            sort($keys);

            $this->assertNotEmpty($keys, $locale);
            $this->assertSame($values, $keys, "{$locale}: 사유 목록과 문구 목록이 다르다");
        }
    }
}
