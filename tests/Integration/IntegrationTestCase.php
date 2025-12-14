<?php

declare(strict_types=1);

namespace PureMapper\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;
use PureMapper\EntityManager;
use PureMapper\Mapping\MetadataRegistry;
use PureMapper\Type\TypeRegistry;

abstract class IntegrationTestCase extends TestCase
{
    protected ConnectionInterface $connection;
    protected MetadataRegistry $metadataRegistry;
    protected TypeRegistry $typeRegistry;
    protected EntityManager $em;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->connection = $capsule->getConnection();
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
