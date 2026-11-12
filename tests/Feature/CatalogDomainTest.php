<?php

namespace Tests\Feature;

use InvalidArgumentException;
use Modules\Catalog\Contracts\CategoryQuery;
use Modules\Catalog\Contracts\ImageReference;
use Modules\Catalog\Contracts\ProductQuery;
use Modules\Catalog\Domain\CatalogText;
use Modules\Catalog\Domain\Price;
use Modules\Core\Contracts\SecurityEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CatalogDomainTest extends TestCase
{
    public static function validPrices(): array
    {
        return [['0', '0.0000'], [0, '0.0000'], ['0012.5', '12.5000'], [' 2.0001 ', '2.0001'], ['999999999999999.9999', '999999999999999.9999']];
    }

    #[DataProvider('validPrices')]
    public function test_prices_are_exact_canonical_strings(mixed $input, string $expected): void
    {
        $this->assertSame($expected, Price::from($input)->value);
    }

    public static function invalidPrices(): array
    {
        return [[1.25], [-1], ['-0.01'], ['1.00001'], ['1000000000000000'], ['1e2'], ['1,25'], [''], [null], [true], [[]], ['NaN'], ['.5'], ['+1'], ["1\n2"]];
    }

    #[DataProvider('invalidPrices')]
    public function test_prices_reject_lossy_or_invalid_input(mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Price::from($input);
    }

    public function test_names_normalize_unicode_and_spaces_without_erasing_punctuation(): void
    {
        $this->assertSame('Café con-leche, 2%', CatalogText::normalize("  Cafe\u{0301}   con-leche,  2%  "));
        $this->assertSame('', CatalogText::normalize('   ', 100, false));
        $this->assertSame('Producto 🥤', CatalogText::normalize('Producto 🥤'));
    }

    public static function invalidNames(): array
    {
        return [[''], ['  '], ['---'], ["Café\0"], ["A\u{202e}B"], [str_repeat('X', 151)], [str_repeat('🥤', 76).'A']];
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names_are_rejected(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        CatalogText::normalize($input);
    }

    public function test_query_contracts_reject_sql_sort_injection_and_excessive_pages(): void
    {
        foreach ([fn () => new ProductQuery(sort: 'price; DROP TABLE users'), fn () => new ProductQuery(direction: 'random'),
            fn () => new ProductQuery(perPage: 101), fn () => new ProductQuery(page: 0), fn () => new ProductQuery(categoryId: 'bad'),
            fn () => new ProductQuery(deleted: 'all'), fn () => new CategoryQuery(perPage: 0)] as $invalid) {
            try {
                $invalid();
                $this->fail('Debe rechazar filtros inválidos antes del SQL.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_image_references_are_relative_webp_keys_with_an_immutable_identifier(): void
    {
        $reference = new ImageReference('public', 'catalog/products/01arz3ndektsv4rrffq69g5fav.webp');
        $this->assertSame('public', $reference->disk);
        $this->assertStringEndsWith('.webp', $reference->key);
        $key = 'catalog/products/01arZ3ndektsv4rrffq69g5fav.webp';
        $this->expectException(InvalidArgumentException::class);
        new ImageReference('public', $key);
    }

    public static function unsafeImages(): array
    {
        return [['public', '../file.webp'], ['public', 'C:\\secret.webp'], ['public', 'https://host/file.webp'],
            ['public', '/absolute.webp'], ['public', 'catalog/products/picture.jpg'], ['../disk', 'catalog/products/01arz3ndektsv4rrffq69g5fav.webp']];
    }

    #[DataProvider('unsafeImages')]
    public function test_images_never_accept_urls_traversal_or_absolute_paths(string $disk, string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ImageReference($disk, $key);
    }

    public function test_catalog_audit_metadata_remains_a_closed_schema(): void
    {
        foreach ([fn () => new SecurityEvent('login', changedFields: ['name']),
            fn () => new SecurityEvent('catalog.product.updated', changedFields: ['password']),
            fn () => new SecurityEvent('catalog.product.updated', changedFields: ['Secret!' => 'name']),
            fn () => new SecurityEvent('catalog.product.updated', changedFields: ['name', 'name']),
            fn () => new SecurityEvent('catalog.product.price_changed', priceBefore: 'Secret!', priceAfter: '1.0000'),
            fn () => new SecurityEvent('catalog.product.updated', priceBefore: '1.0000', priceAfter: '2.0000')] as $invalid) {
            try {
                $invalid();
                $this->fail('No debe admitir campos ni texto arbitrario en auditoría.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringNotContainsString('Secret!', $exception->getMessage());
            }
        }
    }
}
