<?php

declare(strict_types=1);

namespace PhpSsrReact\Attributes;

use Attribute;

/**
 * Define SSR route for Props class
 *
 * @example #[Route('GET', '/board/:id')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Route
{
    public function __construct(
        public string $method,
        public string $path
    ) {}
}
