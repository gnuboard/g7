<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\Brand;

class BrandSeeder extends Seeder
{
    /**
     * 브랜드 시더를 실행합니다.
     */
    public function run(): void
    {
        $this->command->info('브랜드 더미 데이터 생성을 시작합니다.');

        $this->deleteExistingBrands();

        $brands = [
            // 스포츠/의류 (1-12)
            [
                'name' => ['ko' => '나이키', 'en' => 'Nike'],
                'slug' => 'nike',
                'website' => 'https://www.nike.com',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '아디다스', 'en' => 'Adidas'],
                'slug' => 'adidas',
                'website' => 'https://www.adidas.com',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '퓨마', 'en' => 'Puma'],
                'slug' => 'puma',
                'website' => 'https://www.puma.com',
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '뉴발란스', 'en' => 'New Balance'],
                'slug' => 'new-balance',
                'website' => 'https://www.newbalance.com',
                'sort_order' => 4,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '언더아머', 'en' => 'Under Armour'],
                'slug' => 'under-armour',
                'website' => 'https://www.underarmour.com',
                'sort_order' => 5,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '자라', 'en' => 'ZARA'],
                'slug' => 'zara',
                'website' => 'https://www.zara.com',
                'sort_order' => 6,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '유니클로', 'en' => 'UNIQLO'],
                'slug' => 'uniqlo',
                'website' => 'https://www.uniqlo.com',
                'sort_order' => 7,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '에잇세컨즈', 'en' => '8SECONDS'],
                'slug' => '8seconds',
                'website' => 'https://www.8seconds.co.kr',
                'sort_order' => 8,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '스파오', 'en' => 'SPAO'],
                'slug' => 'spao',
                'website' => 'https://www.spao.com',
                'sort_order' => 9,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '코오롱스포츠', 'en' => 'KOLON SPORT'],
                'slug' => 'kolon-sport',
                'website' => 'https://www.kolonsport.com',
                'sort_order' => 10,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '네파', 'en' => 'NEPA'],
                'slug' => 'nepa',
                'website' => 'https://www.nepa.co.kr',
                'sort_order' => 11,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '노스페이스', 'en' => 'The North Face'],
                'slug' => 'the-north-face',
                'website' => 'https://www.thenorthface.com',
                'sort_order' => 12,
                'is_active' => true,
            ],

            // 전자기기 (13-20)
            [
                'name' => ['ko' => '삼성전자', 'en' => 'Samsung'],
                'slug' => 'samsung',
                'website' => 'https://www.samsung.com',
                'sort_order' => 13,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '애플', 'en' => 'Apple'],
                'slug' => 'apple',
                'website' => 'https://www.apple.com',
                'sort_order' => 14,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => 'LG전자', 'en' => 'LG Electronics'],
                'slug' => 'lg-electronics',
                'website' => 'https://www.lge.co.kr',
                'sort_order' => 15,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '소니', 'en' => 'Sony'],
                'slug' => 'sony',
                'website' => 'https://www.sony.com',
                'sort_order' => 16,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '델', 'en' => 'Dell'],
                'slug' => 'dell',
                'website' => 'https://www.dell.com',
                'sort_order' => 17,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => 'HP', 'en' => 'HP'],
                'slug' => 'hp',
                'website' => 'https://www.hp.com',
                'sort_order' => 18,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '레노버', 'en' => 'Lenovo'],
                'slug' => 'lenovo',
                'website' => 'https://www.lenovo.com',
                'sort_order' => 19,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => 'ASUS', 'en' => 'ASUS'],
                'slug' => 'asus',
                'website' => 'https://www.asus.com',
                'sort_order' => 20,
                'is_active' => true,
            ],

            // 가구 (21-25)
            [
                'name' => ['ko' => '이케아', 'en' => 'IKEA'],
                'slug' => 'ikea',
                'website' => 'https://www.ikea.com',
                'sort_order' => 21,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '한샘', 'en' => 'Hanssem'],
                'slug' => 'hanssem',
                'website' => 'https://www.hanssem.com',
                'sort_order' => 22,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '에이스침대', 'en' => 'ACE BED'],
                'slug' => 'ace-bed',
                'website' => 'https://www.acebed.co.kr',
                'sort_order' => 23,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '시몬스', 'en' => 'Simmons'],
                'slug' => 'simmons',
                'website' => 'https://www.simmons.co.kr',
                'sort_order' => 24,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '퍼시스', 'en' => 'FURSYS'],
                'slug' => 'fursys',
                'website' => 'https://www.fursys.com',
                'sort_order' => 25,
                'is_active' => true,
            ],

            // 식품 (26-30)
            [
                'name' => ['ko' => 'CJ제일제당', 'en' => 'CJ CheilJedang'],
                'slug' => 'cj',
                'website' => 'https://www.cj.co.kr',
                'sort_order' => 26,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '동원F&B', 'en' => 'Dongwon F&B'],
                'slug' => 'dongwon',
                'website' => 'https://www.dongwon.com',
                'sort_order' => 27,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '농심', 'en' => 'Nongshim'],
                'slug' => 'nongshim',
                'website' => 'https://www.nongshim.com',
                'sort_order' => 28,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '오뚜기', 'en' => 'Ottogi'],
                'slug' => 'ottogi',
                'website' => 'https://www.ottogi.co.kr',
                'sort_order' => 29,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '풀무원', 'en' => 'Pulmuone'],
                'slug' => 'pulmuone',
                'website' => 'https://www.pulmuone.com',
                'sort_order' => 30,
                'is_active' => true,
            ],

            // 책/출판 (31-34)
            [
                'name' => ['ko' => '민음사', 'en' => 'Minumsa'],
                'slug' => 'minumsa',
                'website' => 'https://www.minumsa.com',
                'sort_order' => 31,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '창비', 'en' => 'Changbi'],
                'slug' => 'changbi',
                'website' => 'https://www.changbi.com',
                'sort_order' => 32,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '문학동네', 'en' => 'Munhakdongne'],
                'slug' => 'munhakdongne',
                'website' => 'https://www.munhak.com',
                'sort_order' => 33,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '김영사', 'en' => 'Gimm-Young'],
                'slug' => 'gimm-young',
                'website' => 'https://www.gimmyoung.com',
                'sort_order' => 34,
                'is_active' => true,
            ],

            // 가전제품 (35-38)
            [
                'name' => ['ko' => '다이슨', 'en' => 'Dyson'],
                'slug' => 'dyson',
                'website' => 'https://www.dyson.co.kr',
                'sort_order' => 35,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '쿠쿠전자', 'en' => 'CUCKOO'],
                'slug' => 'cuckoo',
                'website' => 'https://www.cuckoo.co.kr',
                'sort_order' => 36,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '코웨이', 'en' => 'Coway'],
                'slug' => 'coway',
                'website' => 'https://www.coway.co.kr',
                'sort_order' => 37,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '필립스', 'en' => 'Philips'],
                'slug' => 'philips',
                'website' => 'https://www.philips.co.kr',
                'sort_order' => 38,
                'is_active' => true,
            ],

            // 건강/뷰티 (39-42)
            [
                'name' => ['ko' => '아모레퍼시픽', 'en' => 'AmorePacific'],
                'slug' => 'amorepacific',
                'website' => 'https://www.amorepacific.com',
                'sort_order' => 39,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => 'LG생활건강', 'en' => 'LG H&H'],
                'slug' => 'lg-household',
                'website' => 'https://www.lgcare.com',
                'sort_order' => 40,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '이니스프리', 'en' => 'innisfree'],
                'slug' => 'innisfree',
                'website' => 'https://www.innisfree.com',
                'sort_order' => 41,
                'is_active' => true,
            ],
            [
                'name' => ['ko' => '더페이스샵', 'en' => 'The Face Shop'],
                'slug' => 'the-face-shop',
                'website' => 'https://www.thefaceshop.com',
                'sort_order' => 42,
                'is_active' => true,
            ],
        ];

        foreach ($brands as $brand) {
            Brand::create($brand);
            $this->command->line("  - 브랜드 생성: {$brand['name']['ko']} ({$brand['name']['en']})");
        }

        $count = Brand::count();
        $this->command->info("브랜드 더미 데이터 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 브랜드 데이터를 삭제합니다.
     */
    private function deleteExistingBrands(): void
    {
        $deletedCount = Brand::withTrashed()->count();

        if ($deletedCount > 0) {
            Brand::withTrashed()->forceDelete();
            $this->command->warn("기존 브랜드 {$deletedCount}건을 삭제했습니다.");
        }
    }
}
