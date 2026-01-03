<?php

declare(strict_types=1);

namespace PhpSsrReact\Attributes;

use Attribute;

/**
 * Define data loader for Props property
 *
 * @example #[Loader('BoardGet', ['id'])]
 * @example #[Loader('BoardList')]
 * @example #[Loader('BoardGet', ['id'], optional: true)]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Loader
{
    public function __construct(
        public string $method,
        public array $args = [],
        public bool $optional = false
    ) {}
}
