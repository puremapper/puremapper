<?php

declare(strict_types=1);

namespace PureMapper\Mapping;

final readonly class EntityMetadata
{
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
    }

    /**
     * Get the column name for a property.
     */
    public function getColumnForProperty(string $property): ?string
    {
        if (isset($this->fields[$property])) {
            return $this->fields[$property]->column;
        }

        return null;
    }

    /**
     * Get the property name for a column.
     */
    public function getPropertyForColumn(string $column): ?string
    {
        foreach ($this->fields as $property => $field) {
            if ($field->column === $column) {
                return $property;
            }
        }

        return null;
    }

    /**
     * Get primary key column(s).
     *
     * @return array<string>
     */
    public function getPrimaryKeyColumns(): array
    {
        $keys = is_array($this->primaryKey) ? $this->primaryKey : [$this->primaryKey];
        $columns = [];

        foreach ($keys as $key) {
            $columns[] = $this->fields[$key]->column ?? $key;
        }

        return $columns;
    }
}
