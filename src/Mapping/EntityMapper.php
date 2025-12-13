<?php

declare(strict_types=1);

namespace PureMapper\Mapping;

final class EntityMapper
{
    private ?string $table = null;

    /** @var string|array<string>|null */
    private string|array|null $primaryKey = null;

    /** @var array<string, FieldMetadata> */
    private array $fields = [];

    /** @var array<string, RelationMetadata> */
    private array $relations = [];

    /**
     * @param class-string $entityClass
     */
    public function __construct(
        private readonly string $entityClass,
    ) {
    }

    public function table(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    /**
     * @param string|array<string> $property
     */
    public function id(string|array $property): self
    {
        $this->primaryKey = $property;

        // Auto-register id fields if not already registered
        $properties = is_array($property) ? $property : [$property];
        foreach ($properties as $prop) {
            if (!isset($this->fields[$prop])) {
                $this->field($prop, 'int');
            }
        }

        return $this;
    }

    public function field(string $property, string $type, ?string $column = null): self
    {
        $this->fields[$property] = new FieldMetadata(
            property: $property,
            type: $type,
            column: $column ?? $property,
        );

        return $this;
    }

    /**
     * @param class-string $targetEntity
     */
    public function hasOne(string $property, string $targetEntity, string $foreignKey): self
    {
        $this->relations[$property] = new RelationMetadata(
            property: $property,
            type: RelationType::HasOne,
            targetEntity: $targetEntity,
            foreignKey: $foreignKey,
        );

        return $this;
    }

    /**
     * @param class-string $targetEntity
     */
    public function hasMany(string $property, string $targetEntity, string $foreignKey): self
    {
        $this->relations[$property] = new RelationMetadata(
            property: $property,
            type: RelationType::HasMany,
            targetEntity: $targetEntity,
            foreignKey: $foreignKey,
        );

        return $this;
    }

    /**
     * @param class-string $targetEntity
     */
    public function belongsTo(string $property, string $targetEntity, string $foreignKey): self
    {
        $this->relations[$property] = new RelationMetadata(
            property: $property,
            type: RelationType::BelongsTo,
            targetEntity: $targetEntity,
            foreignKey: $foreignKey,
        );

        return $this;
    }

    /**
     * @param class-string $targetEntity
     */
    public function manyToMany(
        string $property,
        string $targetEntity,
        string $pivotTable,
        string $foreignKey,
        string $relatedKey,
    ): self {
        $this->relations[$property] = new RelationMetadata(
            property: $property,
            type: RelationType::ManyToMany,
            targetEntity: $targetEntity,
            foreignKey: $foreignKey,
            pivotTable: $pivotTable,
            relatedKey: $relatedKey,
        );

        return $this;
    }

    public function build(): EntityMetadata
    {
        if ($this->table === null) {
            throw new \InvalidArgumentException('Table name must be set.');
        }

        if ($this->primaryKey === null) {
            throw new \InvalidArgumentException('Primary key must be set.');
        }

        return new EntityMetadata(
            entityClass: $this->entityClass,
            table: $this->table,
            primaryKey: $this->primaryKey,
            fields: $this->fields,
            relations: $this->relations,
        );
    }
}
