<?php

declare(strict_types=1);

namespace PureMapper\Mapping;

final readonly class EntityMetadata
{
    /**
     * Precomputed column → property map for O(1) lookup.
     *
     * @var array<string, string>
     */
    public array $columnToProperty;

    /**
     * Precomputed property → column map for O(1) lookup.
     *
     * @var array<string, string>
     */
    public array $propertyToColumn;

    /**
     * Precomputed property → type map for O(1) lookup.
     *
     * @var array<string, string>
     */
    public array $propertyToType;

    /**
     * @param class-string $entityClass
     * @param string|array<string> $primaryKey
     * @param array<string, FieldMetadata> $fields
     * @param array<string, RelationMetadata> $relations
     */
    public function __construct(
        public string $entityClass,
        public string $table,
        public string|array $primaryKey,
        public array $fields = [],
        public array $relations = [],
    ) {
        // Precompute lookup maps at construction time (once)
        $columnToProperty = [];
        $propertyToColumn = [];
        $propertyToType = [];

        foreach ($fields as $property => $field) {
            $columnToProperty[$field->column] = $property;
            $propertyToColumn[$property] = $field->column;
            $propertyToType[$property] = $field->type;
        }

        $this->columnToProperty = $columnToProperty;
        $this->propertyToColumn = $propertyToColumn;
        $this->propertyToType = $propertyToType;
    }

    /**
     * Get the column name for a property.
     */
    public function getColumnForProperty(string $property): ?string
    {
        return $this->propertyToColumn[$property] ?? null;
    }

    /**
     * Get the property name for a column.
     */
    public function getPropertyForColumn(string $column): ?string
    {
        return $this->columnToProperty[$column] ?? null;
    }

    /**
     * Get the type for a property.
     */
    public function getTypeForProperty(string $property): ?string
    {
        return $this->propertyToType[$property] ?? null;
    }

    /**
     * Get primary key column(s).
     *
     * @return array<string>
     */
    public function getPrimaryKeyColumns(): array
    {
        $keys = \is_array($this->primaryKey) ? $this->primaryKey : [$this->primaryKey];
        $columns = [];

        foreach ($keys as $key) {
            $columns[] = $this->propertyToColumn[$key] ?? $key;
        }

        return $columns;
    }
}
