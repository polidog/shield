<?php
namespace Polidog\Shield;

interface HandlerInterface
{
    public function handle(array $definitions): void;
}