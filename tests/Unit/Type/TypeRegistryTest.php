<?php

declare(strict_types=1);

namespace PureMapper\Tests\Unit\Type;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PureMapper\Exception\TypeNotFoundException;
use PureMapper\Type\Converter\EnumConverter;
use PureMapper\Type\TypeConverter;
use PureMapper\Type\TypeRegistry;

final class TypeRegistryTest extends TestCase
{
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new TypeRegistry();
    }

    public function testHasBuiltinTypes(): void
    {
        $this->assertTrue($this->registry->has('string'));
        $this->assertTrue($this->registry->has('int'));
        $this->assertTrue($this->registry->has('float'));
        $this->assertTrue($this->registry->has('bool'));
        $this->assertTrue($this->registry->has('datetime'));
        $this->assertTrue($this->registry->has('date'));
        $this->assertTrue($this->registry->has('json'));
    }

    public function testRegisterCustomType(): void
    {
        $converter = new EnumConverter(TestStatus::class);
        $this->registry->register('status', $converter);

        $this->assertTrue($this->registry->has('status'));
        $this->assertSame($converter, $this->registry->get('status'));
    }

    public function testGetThrowsExceptionForUnknownType(): void
    {
        $this->expectException(TypeNotFoundException::class);
        $this->expectExceptionMessage('No type converter found for type "unknown".');

        $this->registry->get('unknown');
    }

    public function testStringConverter(): void
    {
        $converter = $this->registry->get('string');

        $this->assertSame('hello', $converter->toPHP('hello'));
        $this->assertSame('123', $converter->toPHP(123));
        $this->assertNull($converter->toPHP(null));
        $this->assertSame('hello', $converter->toDatabase('hello'));
    }

    public function testIntConverter(): void
    {
        $converter = $this->registry->get('int');

        $this->assertSame(123, $converter->toPHP('123'));
        $this->assertSame(123, $converter->toPHP(123));
        $this->assertNull($converter->toPHP(null));
        $this->assertSame(123, $converter->toDatabase(123));
    }

    public function testFloatConverter(): void
    {
        $converter = $this->registry->get('float');

        $this->assertSame(12.34, $converter->toPHP('12.34'));
        $this->assertSame(12.0, $converter->toPHP(12));
        $this->assertNull($converter->toPHP(null));
    }

    public function testBoolConverter(): void
    {
        $converter = $this->registry->get('bool');

        $this->assertTrue($converter->toPHP(1));
        $this->assertTrue($converter->toPHP('1'));
        $this->assertFalse($converter->toPHP(0));
        $this->assertNull($converter->toPHP(null));
        $this->assertSame(1, $converter->toDatabase(true));
        $this->assertSame(0, $converter->toDatabase(false));
    }

    public function testDateTimeConverter(): void
    {
        $converter = $this->registry->get('datetime');

        $result = $converter->toPHP('2024-01-15 10:30:00');
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame('2024-01-15 10:30:00', $result->format('Y-m-d H:i:s'));

        $datetime = new DateTimeImmutable('2024-01-15 10:30:00');
        $this->assertSame('2024-01-15 10:30:00', $converter->toDatabase($datetime));
        $this->assertNull($converter->toPHP(null));
    }

    public function testDateConverter(): void
    {
        $converter = $this->registry->get('date');

        $result = $converter->toPHP('2024-01-15');
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame('2024-01-15', $result->format('Y-m-d'));

        $date = new DateTimeImmutable('2024-01-15');
        $this->assertSame('2024-01-15', $converter->toDatabase($date));
    }

    public function testJsonConverter(): void
    {
        $converter = $this->registry->get('json');

        $result = $converter->toPHP('{"name":"John","age":30}');
        $this->assertSame(['name' => 'John', 'age' => 30], $result);

        $this->assertSame('{"name":"John","age":30}', $converter->toDatabase(['name' => 'John', 'age' => 30]));
        $this->assertNull($converter->toPHP(null));
    }

    public function testEnumConverter(): void
    {
        $converter = new EnumConverter(TestStatus::class);

        $result = $converter->toPHP('active');
        $this->assertSame(TestStatus::Active, $result);

        $this->assertSame('active', $converter->toDatabase(TestStatus::Active));
        $this->assertNull($converter->toPHP(null));
    }
}

enum TestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
