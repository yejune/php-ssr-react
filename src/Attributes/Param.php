<?php

declare(strict_types=1);

namespace PhpSsrReact\Attributes;

use Attribute;

/**
 * Define URL parameter mapping for Props property
 *
 * @example #[Param('id')]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Param
{
    public function __construct(
        public string $name
    ) {}
}
