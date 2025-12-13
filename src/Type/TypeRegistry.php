<?php

declare(strict_types=1);

namespace PureMapper\Type;

use PureMapper\Exception\TypeNotFoundException;
use PureMapper\Type\Converter\BoolConverter;
use PureMapper\Type\Converter\DateConverter;
use PureMapper\Type\Converter\DateTimeConverter;
use PureMapper\Type\Converter\FloatConverter;
use PureMapper\Type\Converter\IntConverter;
use PureMapper\Type\Converter\JsonConverter;
use PureMapper\Type\Converter\StringConverter;

final class TypeRegistry
{
    /**
     * @var array<string, TypeConverter>
     */
    private array $converters = [];

    public function __construct()
    {
        $this->registerBuiltinTypes();
    }

    public function register(string $name, TypeConverter $converter): void
    {
        $this->converters[$name] = $converter;
    }

    /**
     * @throws TypeNotFoundException
     */
    public function get(string $name): TypeConverter
    {
        if (!isset($this->converters[$name])) {
            throw TypeNotFoundException::forType($name);
        }

        return $this->converters[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->converters[$name]);
    }

    private function registerBuiltinTypes(): void
    {
        $this->converters = [
            'string' => new StringConverter(),
            'int' => new IntConverter(),
            'float' => new FloatConverter(),
            'bool' => new BoolConverter(),
            'datetime' => new DateTimeConverter(),
            'date' => new DateConverter(),
            'json' => new JsonConverter(),
        ];
    }
}
