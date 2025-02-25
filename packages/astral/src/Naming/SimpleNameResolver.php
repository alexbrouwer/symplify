<?php

declare(strict_types=1);

namespace Symplify\Astral\Naming;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Property;
use Symplify\Astral\Contract\NodeNameResolverInterface;

final class SimpleNameResolver
{
    /**
     * @var NodeNameResolverInterface[]
     */
    private $nodeNameResolvers = [];

    /**
     * @param NodeNameResolverInterface[] $nodeNameResolvers
     */
    public function __construct(array $nodeNameResolvers)
    {
        $this->nodeNameResolvers = $nodeNameResolvers;
    }

    /**
     * @param Node|string $node
     */
    public function getName($node): ?string
    {
        if (is_string($node)) {
            return $node;
        }

        foreach ($this->nodeNameResolvers as $nodeNameResolver) {
            if (! $nodeNameResolver->match($node)) {
                continue;
            }

            return $nodeNameResolver->resolve($node);
        }

        if ($node instanceof ClassConstFetch && $this->isName($node->name, 'class')) {
            return $this->getName($node->class);
        }

        if ($node instanceof Property) {
            $propertyProperty = $node->props[0];
            return $this->getName($propertyProperty->name);
        }

        if ($node instanceof Variable) {
            return $this->getName($node->name);
        }

        return null;
    }

    /**
     * @param string[] $desiredNames
     */
    public function isNames(Node $node, array $desiredNames): bool
    {
        foreach ($desiredNames as $desiredName) {
            if ($this->isName($node, $desiredName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string|Node $node
     */
    public function isName($node, string $desiredName): bool
    {
        $name = $this->getName($node);
        if ($name === null) {
            return false;
        }

        if (Strings::contains($desiredName, '*')) {
            return fnmatch($desiredName, $name);
        }

        return $name === $desiredName;
    }

    public function areNamesEqual(Node $firstNode, Node $secondNode): bool
    {
        $firstName = $this->getName($firstNode);
        if ($firstName === null) {
            return false;
        }

        $secondName = $this->getName($secondNode);
        return $firstName === $secondName;
    }

    public function getShortClassName(string $className): string
    {
        if (! Strings::contains($className, '\\')) {
            return $className;
        }

        return (string) Strings::after($className, '\\', -1);
    }
}
