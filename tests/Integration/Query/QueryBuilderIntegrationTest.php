<?php

declare(strict_types=1);

namespace PureMapper\Tests\Integration\Query;

use PureMapper\Mapping\EntityMapper;
use PureMapper\Tests\Integration\IntegrationTestCase;

final class QueryBuilderIntegrationTest extends IntegrationTestCase
{
    protected function createSchema(): void
    {
        $this->connection->statement('
            CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                price INTEGER NOT NULL
            )
        ');
    }

    protected function registerEntities(): void
    {
        $this->metadataRegistry->register(
            (new EntityMapper(QueryTestProduct::class))
                ->table('products')
                ->id('id')
                ->field('name', 'string')
                ->field('price', 'int')
                ->build(),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Seed test data
        $products = [
            ['name' => 'Apple', 'price' => 100],
            ['name' => 'Banana', 'price' => 50],
            ['name' => 'Cherry', 'price' => 200],
            ['name' => 'Date', 'price' => 150],
            ['name' => 'Elderberry', 'price' => 300],
        ];

        foreach ($products as $product) {
            $this->connection->execute(
                $this->connection->table('products')->toInsert($product),
            );
        }
    }

    public function testWhereAndOrderBy(): void
    {
        // Filter products with price >= 100, order by price desc
        $products = $this->em->query(QueryTestProduct::class)
            ->where('price', '>=', 100)
            ->orderBy('price', 'desc')
            ->get();

        $this->assertCount(4, $products);
        $this->assertSame('Elderberry', $products[0]->name);
        $this->assertSame('Cherry', $products[1]->name);
        $this->assertSame('Date', $products[2]->name);
        $this->assertSame('Apple', $products[3]->name);
    }

    public function testPaginationWithLimitOffset(): void
    {
        // Get products ordered by name, skip 2, take 2
        $products = $this->em->query(QueryTestProduct::class)
            ->orderBy('name', 'asc')
            ->limit(2)
            ->offset(2)
            ->get();

        $this->assertCount(2, $products);
        $this->assertSame('Cherry', $products[0]->name);
        $this->assertSame('Date', $products[1]->name);
    }
}

class QueryTestProduct
{
    public ?int $id = null;
    public string $name;
    public int $price;
}
