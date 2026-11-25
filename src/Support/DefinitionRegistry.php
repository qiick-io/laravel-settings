<?php

declare(strict_types=1);

namespace Qiick\Settings\Support;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Qiick\Settings\Definition;
use Qiick\Settings\ScopeRegistry;
use Qiick\Settings\SettingsSchema;
use Throwable;

/** @internal Combines application schemas after providers have registered their scopes. */
final readonly class DefinitionRegistry
{
    public function __construct(
        private Container $container,
        private ScopeRegistry $scopes,
    ) {}

    /** @return array<array-key, Definition> */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->container->tagged(SettingsSchema::class) as $schema) {
            if (! $schema instanceof SettingsSchema) {
                throw new InvalidArgumentException('A tagged settings schema must implement SettingsSchema.');
            }

            try {
                $declared = $schema->definitions();
            } catch (Throwable) {
                throw new InvalidArgumentException('A settings schema failed to declare its definitions.');
            }

            foreach ($declared as $definition) {
                if (! $definition instanceof Definition) {
                    throw new InvalidArgumentException('A settings schema must return Definition instances.');
                }

                if (isset($definitions[$definition->key])) {
                    throw new InvalidArgumentException("Settings key {$definition->key} is declared more than once.");
                }

                foreach ($definition->scopeNames() as $scope) {
                    $this->scopes->isInstanceLike($scope);
                }

                $definitions[$definition->key] = $definition;
            }
        }

        return $definitions;
    }
}
