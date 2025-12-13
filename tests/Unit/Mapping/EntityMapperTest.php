<?php

declare(strict_types=1);

namespace PureMapper\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use PureMapper\Mapping\EntityMapper;
use PureMapper\Mapping\RelationType;

final class EntityMapperTest extends TestCase
{
    public function testBuildSimpleEntity(): void
    {
        $metadata = (new EntityMapper(TestEntity::class))
            ->table('test_entities')
            ->id('id')
            ->field('name', 'string')
            ->field('email', 'string')
            ->build();

        $this->assertSame(TestEntity::class, $metadata->entityClass);
        $this->assertSame('test_entities', $metadata->table);
        $this->assertSame('id', $metadata->primaryKey);
        $this->assertCount(3, $metadata->fields);
        $this->assertSame('id', $metadata->fields['id']->column);
        $this->assertSame('name', $metadata->fields['name']->column);
    }

    public function testBuildWithColumnMapping(): void
    {
        $metadata = (new EntityMapper(TestEntity::class))
            ->table('test_entities')
            ->id('id')
            ->field('createdAt', 'datetime', column: 'created_at')
            ->build();

        $this->assertSame('created_at', $metadata->fields['createdAt']->column);
        $this->assertSame('datetime', $metadata->fields['createdAt']->type);
    }

    public function testBuildWithCompositeKey(): void
    {
        $metadata = (new EntityMapper(TestEntity::class))
            ->table('tenant_users')
            ->id(['tenant_id', 'user_id'])
            ->field('role', 'string')
            ->build();

        $this->assertSame(['tenant_id', 'user_id'], $metadata->primaryKey);
        $this->assertArrayHasKey('tenant_id', $metadata->fields);
        $this->assertArrayHasKey('user_id', $metadata->fields);
    }

    public function testBuildWithHasOneRelation(): void
    {
        $metadata = (new EntityMapper(TestEntity::class))
            ->table('users')
            ->id('id')
            ->hasOne('profile', TestProfile::class, 'user_id')
            ->build();

        $this->assertArrayHasKey('profile', $metadata->relations);
        $this->assertSame(RelationType::HasOne, $metadata->relations['profile']->type);
        $this->assertSame(TestProfile::class, $metadata->relations['profile']->targetEntity);
        $this->assertSame('user_id', $metadata->relations['profile']->foreignKey);
    }

    public function testBuildWithHasManyRelation(): void
    {
        $metadata = (new EntityMapper(TestEntity::class))
            ->table('users')
            ->id('id')
            ->hasMany('posts', TestPost::class, 'user_id')
            ->build();

        $this->assertArrayHasKey('posts', $metadata->relations);
        $this->assertSame(RelationType::HasMany, $metadata->relations['posts']->type);
    }

    public function testBuildWithBelongsToRelation(): void
    {
        $metadata = (new EntityMapper(TestPost::class))
            ->table('posts')
            ->id('id')
            ->belongsTo('author', TestEntity::class, 'author_id')
            ->build();

        $this->assertArrayHasKey('author', $metadata->relations);
        $this->assertSame(RelationType::BelongsTo, $metadata->relations['author']->type);
    }

    public function testBuildWithManyToManyRelation(): void
    {
        $metadata = (new EntityMapper(TestPost::class))
            ->table('posts')
            ->id('id')
            ->manyToMany('tags', TestTag::class, 'post_tags', 'post_id', 'tag_id')
            ->build();

        $this->assertArrayHasKey('tags', $metadata->relations);
        $this->assertSame(RelationType::ManyToMany, $metadata->relations['tags']->type);
        $this->assertSame('post_tags', $metadata->relations['tags']->pivotTable);
        $this->assertSame('post_id', $metadata->relations['tags']->foreignKey);
        $this->assertSame('tag_id', $metadata->relations['tags']->relatedKey);
    }

    public function testBuildThrowsExceptionWithoutTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Table name must be set.');

        (new EntityMapper(TestEntity::class))
            ->id('id')
            ->build();
    }

    public function testBuildThrowsExceptionWithoutPrimaryKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Primary key must be set.');

        (new EntityMapper(TestEntity::class))
            ->table('test_entities')
            ->build();
    }
}

// Test fixtures
class TestEntity
{
    public ?int $id = null;
    public string $name;
    public string $email;
}

class TestProfile
{
    public ?int $id = null;
    public int $userId;
}

class TestPost
{
    public ?int $id = null;
    public string $title;
}

class TestTag
{
    public ?int $id = null;
    public string $name;
}
