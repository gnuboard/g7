<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Sequence;

/**
 * 시퀀스 초기 데이터 시더.
 *
 * 설치 시 각 시퀀스 타입의 기본 설정을 1회 등록한다.
 * 재실행(install --force 등) 시 기존 레코드의 `current_value` 는 운영 중 자동 증가한
 * counter 이므로 절대 리셋되면 안 된다. `firstOrCreate` 를 사용해 기존 행을
 * 완전히 보존하고, 존재하지 않는 경우에만 기본 설정을 삽입한다.
 *
 * 시더 정의 값의 추후 변경이 필요하면 upgrade step 에서 명시적으로 처리.
 */
class SequenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('시퀀스 초기 데이터 동기화를 시작합니다.');

        $types = [
            SequenceType::PRODUCT,
            SequenceType::ORDER,
            SequenceType::CANCEL,
            SequenceType::REFUND,
        ];

        $created = 0;
        $preserved = 0;

        foreach ($types as $type) {
            $typeLabel = $this->getTypeLabel($type);
            $defaultConfig = $type->getDefaultConfig();

            [, $wasCreated] = $this->firstOrCreateSequence($type, $defaultConfig);

            if ($wasCreated) {
                $created++;
                $prefixInfo = isset($defaultConfig['prefix']) && $defaultConfig['prefix'] !== null
                    ? ", 접두사: {$defaultConfig['prefix']}"
                    : ' (채번테이블 미사용)';
                $this->command->line("  - {$typeLabel} 시퀀스 생성 완료 (알고리즘: {$defaultConfig['algorithm']->value}{$prefixInfo})");
            } else {
                $preserved++;
                $this->command->line("  - {$typeLabel} 시퀀스 보존 (기존 current_value 유지)");
            }
        }

        $this->command->info("시퀀스 동기화 완료: {$created}건 생성, {$preserved}건 보존");
    }

    /**
     * 시퀀스 레코드를 찾거나 생성합니다 (기존 레코드 보존).
     *
     * @param  SequenceType  $type  시퀀스 타입
     * @param  array<string, mixed>  $defaultConfig  기본 설정
     * @return array{0: Sequence, 1: bool}  [모델, 신규생성여부]
     */
    private function firstOrCreateSequence(SequenceType $type, array $defaultConfig): array
    {
        $existed = Sequence::where('type', $type->value)->exists();

        $sequence = Sequence::firstOrCreate(
            ['type' => $type->value],
            [
                'algorithm' => $defaultConfig['algorithm']->value,
                'prefix' => $defaultConfig['prefix'] ?? null,
                'current_value' => 0,
                'increment' => 1,
                'min_value' => 1,
                'max_value' => $defaultConfig['max_value'],
                'cycle' => false,
                'pad_length' => $defaultConfig['pad_length'],
                'max_history_count' => $defaultConfig['max_history_count'] ?? 0,
            ],
        );

        return [$sequence, ! $existed];
    }

    /**
     * 시퀀스 타입의 한국어 라벨을 반환합니다.
     */
    private function getTypeLabel(SequenceType $type): string
    {
        return match ($type) {
            SequenceType::PRODUCT => '상품',
            SequenceType::ORDER => '주문',
            SequenceType::SHIPPING => '배송',
            SequenceType::CANCEL => '취소',
            SequenceType::REFUND => '환불',
        };
    }
}
