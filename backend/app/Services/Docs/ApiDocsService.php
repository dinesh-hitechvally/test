<?php

namespace App\Services\Docs;

use GraphQL\Language\AST\ListValueNode;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Schema\SchemaBuilder;

/**
 * Reads the live GraphQL schema (graphql/*.graphql) and shapes it for the
 * /docs pages, so the API reference can never drift from the code: a new
 * query, argument or field shows up here as soon as it is in the schema.
 * Descriptions are the "..." strings written above fields in the schema files.
 */
class ApiDocsService
{
    /** Operations anyone can call; everything else needs a bearer token (@guard in the schema). */
    private const PUBLIC_HINT = 'guard';

    public function __construct(private readonly SchemaBuilder $builder) {}

    /**
     * Queries and mutations grouped by the resolver that serves them
     * (Portfolio, Stock, Report…), each group sorted by name.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function operations(): array
    {
        $schema = $this->builder->schema();
        $groups = [];

        foreach (['query' => $schema->getQueryType(), 'mutation' => $schema->getMutationType()] as $kind => $root) {
            foreach ($root?->getFields() ?? [] as $field) {
                $resolver = $this->resolverOf($field);
                $group = $resolver ? Str::before(class_basename(Str::before($resolver, '@')), 'Resolver') : 'Other';

                $groups[$group][] = [
                    'kind' => $kind,
                    'name' => $field->name,
                    'description' => $field->description,
                    'public' => ! $this->hasDirective($field, self::PUBLIC_HINT),
                    'returns' => (string) $field->getType(),
                    'args' => array_map(fn ($a) => [
                        'name' => $a->name,
                        'type' => (string) $a->getType(),
                        'required' => str_ends_with((string) $a->getType(), '!'),
                        'description' => $a->description,
                        'default' => $a->defaultValueExists() ? json_encode($a->defaultValue) : null,
                    ], $field->args),
                    'example' => $this->example($kind, $field),
                    // What the API console runs: the same operation but reaching one level into related objects, with sample values.
                    'console_query' => $this->example($kind, $field, 2),
                    'sample_variables' => $this->sampleVariables($field),
                ];
            }
        }

        foreach ($groups as &$operations) {
            usort($operations, fn ($a, $b) => [$a['kind'], $a['name']] <=> [$b['kind'], $b['name']]);
        }
        unset($operations);
        ksort($groups);

        return $groups;
    }

    /**
     * Every object, input and enum type with its fields, A–Z.
     *
     * @return list<array<string, mixed>>
     */
    public function types(): array
    {
        $types = [];

        foreach ($this->builder->schema()->getTypeMap() as $name => $type) {
            if (Str::startsWith($name, '__') || in_array($name, ['Query', 'Mutation'], true) || Type::isBuiltInScalar($type)) {
                continue;
            }

            if ($type instanceof ObjectType || $type instanceof InputObjectType) {
                $types[] = [
                    'name' => $name,
                    'kind' => $type instanceof InputObjectType ? 'input' : 'type',
                    'description' => $type->description,
                    'fields' => array_values(array_map(fn ($f) => [
                        'name' => $f->name,
                        'type' => (string) $f->getType(),
                        'description' => $f->description,
                    ], $type->getFields())),
                ];
            } elseif ($type instanceof EnumType) {
                $types[] = ['name' => $name, 'kind' => 'enum', 'description' => $type->description, 'fields' => array_map(fn ($v) => ['name' => $v->name, 'type' => '', 'description' => $v->description], $type->getValues())];
            }
        }

        usort($types, fn ($a, $b) => $a['name'] <=> $b['name']);

        return $types;
    }

    /** A ready-to-run operation: every required argument as a variable, and a selection of the return type's fields. */
    private function example(string $kind, FieldDefinition $field, int $depth = 1): string
    {
        $vars = [];
        $callArgs = [];

        foreach ($field->args as $arg) {
            if (! str_ends_with((string) $arg->getType(), '!')) {
                continue;
            }
            $vars[] = "\${$arg->name}: {$arg->getType()}";
            $callArgs[] = "{$arg->name}: \${$arg->name}";
        }

        $selection = $this->selection(Type::getNamedType($field->getType()), $depth);
        $call = $field->name.($callArgs ? '('.implode(', ', $callArgs).')' : '').($selection ? " { {$selection} }" : '');

        return $kind.($vars ? ' ('.implode(', ', $vars).')' : '').' { '.$call.' }';
    }

    /** Scalar fields first, then (depth > 1) a few related objects, each reduced to its own scalars. */
    private function selection(Type $type, int $depth): string
    {
        if (! $type instanceof ObjectType) {
            return '';
        }

        $scalars = [];
        $objects = [];

        foreach ($type->getFields() as $name => $f) {
            if (str_starts_with($name, 'created_at') || str_starts_with($name, 'updated_at') || $f->args) {
                continue;
            }

            if (Type::getNamedType($f->getType()) instanceof ObjectType) {
                if ($depth > 1 && count($objects) < 3 && ($inner = $this->selection(Type::getNamedType($f->getType()), 1))) {
                    $objects[] = "{$name} { {$inner} }";
                }

                continue;
            }

            $scalars[] = $name;
        }

        return implode(' ', [...array_slice($scalars, 0, 6), ...$objects]);
    }

    /**
     * Believable values for an operation's required arguments, so the console can run it as is:
     * a real-looking symbol, id 1, a month of days. Only a starting point; edit them in the console.
     *
     * @return array<string, mixed>
     */
    private function sampleVariables(FieldDefinition $field): array
    {
        $vars = [];

        foreach ($field->args as $arg) {
            if (! str_ends_with((string) $arg->getType(), '!')) {
                continue;
            }

            $named = Type::getNamedType($arg->getType());
            $vars[$arg->name] = match (true) {
                $arg->name === 'symbol' => 'NABIL',
                $arg->name === 'email' => 'you@example.com',
                $arg->name === 'bias' => 'buy',
                $named->name === 'Int' => str_contains($arg->name, 'days') ? 30 : 1,
                $named->name === 'Float' => 1,
                $named->name === 'Boolean' => true,
                str_ends_with((string) $arg->getType(), ']!') => [],
                default => 'text',
            };
        }

        return $vars;
    }

    private function resolverOf(FieldDefinition $field): ?string
    {
        foreach ($field->astNode?->directives ?? [] as $directive) {
            if ($directive->name->value !== 'field') {
                continue;
            }

            foreach ($directive->arguments as $arg) {
                if ($arg->name->value === 'resolver' && ! $arg->value instanceof ListValueNode) {
                    return $arg->value->value;
                }
            }
        }

        return null;
    }

    private function hasDirective(FieldDefinition $field, string $name): bool
    {
        foreach ($field->astNode?->directives ?? [] as $directive) {
            if ($directive->name->value === $name) {
                return true;
            }
        }

        return false;
    }
}
