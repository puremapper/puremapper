<?php

declare(strict_types=1);

namespace PureMapper\Tests\Integration\Query;

use PureMapper\Mapping\EntityMapper;
use PureMapper\Tests\Integration\IntegrationTestCase;

final class RelationEagerLoadingTest extends IntegrationTestCase
{
    protected function createSchema(): void
    {
        $this->connection->statement('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )
        ');

        $this->connection->statement('
            CREATE TABLE profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                bio TEXT
            )
        ');

        $this->connection->statement('
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL
            )
        ');

        $this->connection->statement('
            CREATE TABLE tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )
        ');

        $this->connection->statement('
            CREATE TABLE post_tags (
                post_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (post_id, tag_id)
            )
        ');
    }

    protected function registerEntities(): void
    {
        $this->metadataRegistry->register(
            (new EntityMapper(RelationTestUser::class))
                ->table('users')
                ->id('id')
                ->field('name', 'string')
                ->hasOne('profile', RelationTestProfile::class, 'user_id')
                ->hasMany('posts', RelationTestPost::class, 'user_id')
                ->build(),
        );

        $this->metadataRegistry->register(
            (new EntityMapper(RelationTestProfile::class))
                ->table('profiles')
                ->id('id')
                ->field('userId', 'int', 'user_id')
                ->field('bio', 'string')
                ->belongsTo('user', RelationTestUser::class, 'userId')
                ->build(),
        );

        $this->metadataRegistry->register(
            (new EntityMapper(RelationTestPost::class))
                ->table('posts')
                ->id('id')
                ->field('userId', 'int', 'user_id')
                ->field('title', 'string')
                ->belongsTo('author', RelationTestUser::class, 'userId')
                ->manyToMany('tags', RelationTestTag::class, 'post_tags', 'post_id', 'tag_id')
                ->build(),
        );

        $this->metadataRegistry->register(
            (new EntityMapper(RelationTestTag::class))
                ->table('tags')
                ->id('id')
                ->field('name', 'string')
                ->build(),
        );
    }

    public function testHasOneEagerLoading(): void
    {
        // Insert data
        $userId = $this->connection->table('users')->insertGetId(['name' => 'John']);
        $this->connection->table('profiles')->insert(['user_id' => $userId, 'bio' => 'Developer']);

        // Query with eager loading (use where + first since find() doesn't load relations)
        $user = $this->em->query(RelationTestUser::class)
            ->with('profile')
            ->where('id', '=', $userId)
            ->first();

        $this->assertNotNull($user->profile);
        $this->assertInstanceOf(RelationTestProfile::class, $user->profile);
        $this->assertSame('Developer', $user->profile->bio);
    }

    public function testHasManyEagerLoading(): void
    {
        // Insert data
        $userId = $this->connection->table('users')->insertGetId(['name' => 'John']);
        $this->connection->table('posts')->insert(['user_id' => $userId, 'title' => 'Post 1']);
        $this->connection->table('posts')->insert(['user_id' => $userId, 'title' => 'Post 2']);
        $this->connection->table('posts')->insert(['user_id' => $userId, 'title' => 'Post 3']);

        // Query with eager loading
        $user = $this->em->query(RelationTestUser::class)
            ->with('posts')
            ->where('id', '=', $userId)
            ->first();

        $this->assertCount(3, $user->posts);
        $this->assertInstanceOf(RelationTestPost::class, $user->posts[0]);
    }

    public function testBelongsToEagerLoading(): void
    {
        // Insert data
        $userId = $this->connection->table('users')->insertGetId(['name' => 'John']);
        $postId = $this->connection->table('posts')->insertGetId(['user_id' => $userId, 'title' => 'My Post']);

        // Query with eager loading
        $this->em->clear();
        $post = $this->em->query(RelationTestPost::class)
            ->with('author')
            ->where('id', '=', $postId)
            ->first();

        $this->assertNotNull($post->author);
        $this->assertInstanceOf(RelationTestUser::class, $post->author);
        $this->assertSame('John', $post->author->name);
    }

    public function testManyToManyEagerLoading(): void
    {
        // Insert data
        $userId = $this->connection->table('users')->insertGetId(['name' => 'John']);
        $postId = $this->connection->table('posts')->insertGetId(['user_id' => $userId, 'title' => 'Tagged Post']);

        $tagId1 = $this->connection->table('tags')->insertGetId(['name' => 'PHP']);
        $tagId2 = $this->connection->table('tags')->insertGetId(['name' => 'ORM']);

        $this->connection->table('post_tags')->insert(['post_id' => $postId, 'tag_id' => $tagId1]);
        $this->connection->table('post_tags')->insert(['post_id' => $postId, 'tag_id' => $tagId2]);

        // Query with eager loading
        $post = $this->em->query(RelationTestPost::class)
            ->with('tags')
            ->where('id', '=', $postId)
            ->first();

        $this->assertCount(2, $post->tags);
        $this->assertInstanceOf(RelationTestTag::class, $post->tags[0]);

        $tagNames = array_map(fn($t) => $t->name, $post->tags);
        $this->assertContains('PHP', $tagNames);
        $this->assertContains('ORM', $tagNames);
    }
}

class RelationTestUser
{
    public ?int $id = null;
    public string $name;
    public ?RelationTestProfile $profile = null;
    public array $posts = [];
}

class RelationTestProfile
{
    public ?int $id = null;
    public int $userId;
    public ?string $bio = null;
    public ?RelationTestUser $user = null;
}

class RelationTestPost
{
    public ?int $id = null;
    public int $userId;
    public string $title;
    public ?RelationTestUser $author = null;
    public array $tags = [];
}

class RelationTestTag
{
    public ?int $id = null;
    public string $name;
}
