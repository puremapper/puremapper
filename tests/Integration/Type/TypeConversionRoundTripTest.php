<?php

declare(strict_types=1);

namespace PureMapper\Tests\Integration\Type;

use DateTimeImmutable;
use PureMapper\Mapping\EntityMapper;
use PureMapper\Tests\Integration\IntegrationTestCase;

final class TypeConversionRoundTripTest extends IntegrationTestCase
{
    protected function createSchema(): void
    {
        $this->connection->statement('
            CREATE TABLE articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                created_at TEXT,
                metadata TEXT
            )
        ');
    }

    protected function registerEntities(): void
    {
        $this->metadataRegistry->register(
            (new EntityMapper(TypeTestArticle::class))
                ->table('articles')
                ->id('id')
                ->field('title', 'string')
                ->field('createdAt', 'datetime', 'created_at')
                ->field('metadata', 'json')
                ->build()
        );
    }

    public function testDateTimeRoundTrip(): void
    {
        $now = new DateTimeImmutable('2024-06-15 14:30:00');

        $article = new TypeTestArticle();
        $article->title = 'DateTime Test';
        $article->createdAt = $now;

        $this->em->persist($article);
        $this->em->commit();

        $id = $article->id;
        $this->em->clear();

        // Query back
        $fetched = $this->em->query(TypeTestArticle::class)->find($id);

        $this->assertInstanceOf(DateTimeImmutable::class, $fetched->createdAt);
        $this->assertSame(
            $now->format('Y-m-d H:i:s'),
            $fetched->createdAt->format('Y-m-d H:i:s')
        );
    }

    public function testJsonRoundTrip(): void
    {
        $metadata = [
            'tags' => ['php', 'orm', 'testing'],
            'views' => 1000,
            'nested' => ['key' => 'value'],
        ];

        $article = new TypeTestArticle();
        $article->title = 'JSON Test';
        $article->metadata = $metadata;

        $this->em->persist($article);
        $this->em->commit();

        $id = $article->id;
        $this->em->clear();

        // Query back
        $fetched = $this->em->query(TypeTestArticle::class)->find($id);

        $this->assertIsArray($fetched->metadata);
        $this->assertSame($metadata, $fetched->metadata);
    }
}

class TypeTestArticle
{
    public ?int $id = null;
    public string $title;
    public ?DateTimeImmutable $createdAt = null;
    public ?array $metadata = null;
}
