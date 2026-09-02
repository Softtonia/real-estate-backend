<?php

namespace Tests\Unit;

use App\Services\PropertySearch\PropertyCategoryAliasResolver;
use App\Services\PropertySearch\PropertySearchQueryBuilder;
use Illuminate\Http\Request;
use Tests\TestCase;

class PropertySearchQueryBuilderTest extends TestCase
{
    public function test_alias_resolver_returns_array(): void
    {
        $resolver = new PropertyCategoryAliasResolver();
        $priceIds = $resolver->resolveFieldIds('price', ['residential']);
        $this->assertIsArray($priceIds);

        $bhkIds = $resolver->resolveFieldIds('bhk', ['residential']);
        $this->assertIsArray($bhkIds);

        $galleryIds = $resolver->resolveFieldIds('gallery', ['residential']);
        $this->assertIsArray($galleryIds);
    }

    public function test_query_builder_generates_valid_sql_for_combined_filters(): void
    {
        $resolver = new PropertyCategoryAliasResolver();
        $builder = new PropertySearchQueryBuilder($resolver);

        $request = new Request([
            'purpose' => 'sell',
            'category' => 'residential',
            'type' => ['apartment', 'villa'],
            'city_id' => [1, 2],
            'locality' => 'Banjara Hills',
            'price_min' => 5000000,
            'price_max' => 15000000,
            'bhk' => ['2 BHK', '3 BHK'],
            'furnishing' => ['Furnished'],
            'verified' => '1',
            'has_photos' => '1',
            'has_videos' => '1',
            'rera' => '1',
            'featured' => '1',
            'q' => 'Luxury Apartment',
            'sort' => 'price_asc',
            'per_page' => 15,
        ]);

        $built = $builder->forRequest($request)->build();
        $sql = $built->getQuery()->toSql();

        // Check SQL structure
        $this->assertStringContainsString('select', strtolower($sql));
        $this->assertStringContainsString('dynamic_posts', $sql);

        // Check applied chips
        $chips = $builder->getAppliedChips();
        $this->assertNotEmpty($chips);

        $chipKeys = array_column($chips, 'key');
        $this->assertContains('purpose', $chipKeys);
        $this->assertContains('category', $chipKeys);
        $this->assertContains('city_id', $chipKeys);
        $this->assertContains('locality', $chipKeys);
        $this->assertContains('verified', $chipKeys);
        $this->assertContains('has_photos', $chipKeys);
        $this->assertContains('has_videos', $chipKeys);
        $this->assertContains('rera', $chipKeys);
        $this->assertContains('featured', $chipKeys);
        $this->assertContains('q', $chipKeys);
    }

    public function test_query_builder_handles_rent_and_area_ranges(): void
    {
        $resolver = new PropertyCategoryAliasResolver();
        $builder = new PropertySearchQueryBuilder($resolver);

        $request = new Request([
            'purpose' => 'rent',
            'category' => 'commercial',
            'rent_min' => 20000,
            'rent_max' => 80000,
            'area_min' => 1000,
            'area_max' => 5000,
            'floor_min' => 2,
            'floor_max' => 8,
            'sort' => 'area_desc',
        ]);

        $built = $builder->forRequest($request)->build();
        $sql = $built->getQuery()->toSql();

        $this->assertNotEmpty($sql);
        $chips = $builder->getAppliedChips();
        $chipKeys = array_column($chips, 'key');

        $this->assertContains('purpose', $chipKeys);
        $this->assertContains('category', $chipKeys);
        $this->assertContains('rent', $chipKeys);
        $this->assertContains('area', $chipKeys);
        $this->assertContains('floor', $chipKeys);
    }

    public function test_query_builder_sort_options(): void
    {
        $resolver = new PropertyCategoryAliasResolver();

        $sorts = ['newest', 'oldest', 'price_asc', 'price_desc', 'area_asc', 'area_desc', 'featured_first'];

        foreach ($sorts as $sort) {
            $builder = new PropertySearchQueryBuilder($resolver);
            $built = $builder->forRequest(new Request(['sort' => $sort]))->build();
            $sql = $built->getQuery()->toSql();
            $this->assertNotEmpty($sql, "Failed building SQL for sort: {$sort}");
        }
    }
}
