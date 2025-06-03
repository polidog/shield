<?php

namespace Polidog\Shield;

use function Polidog\Shield\Http\handleHttpRequest;

class Handler implements HandlerInterface
{
    public function handle(array $definitions): void
    {
        try {
            // Validate the definitions
            if (empty($definitions)) {
                throw new \InvalidArgumentException('Definitions cannot be empty.');
            }
            handleHttpRequest($definitions);
            exit(0);
        } catch (\Exception $e) {
            // Handle exceptions gracefully
            error_log('Error processing definitions: ' . $e->getMessage());
            exit(1);
        }
    }
}