<?php

declare(strict_types=1);

namespace PureMapper\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use PureMapper\EntityManager;
use PureMapper\Mapping\MetadataRegistry;
use PureMapper\Query\Connection;
use PureMapper\Query\DatabaseDriver;
use PureMapper\Type\TypeRegistry;

abstract class IntegrationTestCase extends TestCase
{
    protected Connection $connection;
    protected MetadataRegistry $metadataRegistry;
    protected TypeRegistry $typeRegistry;
    protected EntityManager $em;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->connection = new Connection($pdo, DatabaseDriver::SQLite);
        $this->metadataRegistry = new MetadataRegistry();
        $this->typeRegistry = new TypeRegistry();

        $this->createSchema();
        $this->registerEntities();

        $this->em = new EntityManager(
            $this->connection,
            $this->metadataRegistry,
            $this->typeRegistry,
        );
    }

    protected function tearDown(): void
    {
        $this->em->clear();
    }

    abstract protected function createSchema(): void;

    abstract protected function registerEntities(): void;
}
