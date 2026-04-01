<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Models\SequenceCode;

/**
 * 시퀀스 초기 데이터 시더
 */
class SequenceSeeder extends Seeder
{
    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('시퀀스 초기 데이터 생성을 시작합니다.');

        // 기존 데이터 삭제
        $this->deleteExistingSequences();

        // 상품 시퀀스 생성
        $this->createProductSequence();

        // 주문 시퀀스 생성
        $this->createOrderSequence();

        // 취소 시퀀스 생성
        $this->createCancelSequence();

        // 환불 시퀀스 생성
        $this->createRefundSequence();

        $this->command->info('시퀀스 초기 데이터가 성공적으로 생성되었습니다.');
    }

    /**
     * 기존 시퀀스 및 이력 삭제
     */
    private function deleteExistingSequences(): void
    {
        $types = [SequenceType::PRODUCT, SequenceType::ORDER, SequenceType::CANCEL, SequenceType::REFUND];

        foreach ($types as $type) {
            $typeLabel = match ($type) {
                SequenceType::PRODUCT => '상품',
                SequenceType::ORDER => '주문',
                SequenceType::SHIPPING => '배송',
                SequenceType::CANCEL => '취소',
                SequenceType::REFUND => '환불',
            };

            // 시퀀스 설정 삭제
            $deletedSequenceCount = Sequence::where('type', $type->value)->delete();

            if ($deletedSequenceCount > 0) {
                $this->command->warn("기존 {$typeLabel} 시퀀스 설정 {$deletedSequenceCount}건을 삭제했습니다.");
            }

            // 시퀀스 코드 이력 삭제
            $deletedCodeCount = SequenceCode::where('type', $type->value)->delete();

            if ($deletedCodeCount > 0) {
                $this->command->warn("기존 {$typeLabel} 시퀀스 코드 이력 {$deletedCodeCount}건을 삭제했습니다.");
            }
        }
    }

    /**
     * 상품 시퀀스 생성 (NanoID 알고리즘 — 채번테이블 미사용)
     */
    private function createProductSequence(): void
    {
        $defaultConfig = SequenceType::PRODUCT->getDefaultConfig();

        Sequence::create([
            'type' => SequenceType::PRODUCT->value,
            'algorithm' => $defaultConfig['algorithm']->value,
            'prefix' => $defaultConfig['prefix'],
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => $defaultConfig['max_value'],
            'cycle' => false,
            'pad_length' => $defaultConfig['pad_length'],
            'max_history_count' => $defaultConfig['max_history_count'],
        ]);

        $this->command->info("상품 시퀀스가 생성되었습니다. 알고리즘: {$defaultConfig['algorithm']->value} (채번테이블 미사용)");
    }

    /**
     * 주문 시퀀스 생성
     */
    private function createOrderSequence(): void
    {
        $defaultConfig = SequenceType::ORDER->getDefaultConfig();

        Sequence::create([
            'type' => SequenceType::ORDER->value,
            'algorithm' => $defaultConfig['algorithm']->value,
            'prefix' => $defaultConfig['prefix'],
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => $defaultConfig['max_value'],
            'cycle' => false,
            'pad_length' => $defaultConfig['pad_length'],
        ]);

        $this->command->info("주문 시퀀스가 생성되었습니다. 알고리즘: {$defaultConfig['algorithm']->value}, 접두사: {$defaultConfig['prefix']}");
    }

    /**
     * 취소 시퀀스 생성
     */
    private function createCancelSequence(): void
    {
        $defaultConfig = SequenceType::CANCEL->getDefaultConfig();

        Sequence::create([
            'type' => SequenceType::CANCEL->value,
            'algorithm' => $defaultConfig['algorithm']->value,
            'prefix' => $defaultConfig['prefix'],
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => $defaultConfig['max_value'],
            'cycle' => false,
            'pad_length' => $defaultConfig['pad_length'],
        ]);

        $this->command->info("취소 시퀀스가 생성되었습니다. 알고리즘: {$defaultConfig['algorithm']->value}, 접두사: {$defaultConfig['prefix']}");
    }

    /**
     * 환불 시퀀스 생성
     */
    private function createRefundSequence(): void
    {
        $defaultConfig = SequenceType::REFUND->getDefaultConfig();

        Sequence::create([
            'type' => SequenceType::REFUND->value,
            'algorithm' => $defaultConfig['algorithm']->value,
            'prefix' => $defaultConfig['prefix'],
            'current_value' => 0,
            'increment' => 1,
            'min_value' => 1,
            'max_value' => $defaultConfig['max_value'],
            'cycle' => false,
            'pad_length' => $defaultConfig['pad_length'],
        ]);

        $this->command->info("환불 시퀀스가 생성되었습니다. 알고리즘: {$defaultConfig['algorithm']->value}, 접두사: {$defaultConfig['prefix']}");
    }
}
