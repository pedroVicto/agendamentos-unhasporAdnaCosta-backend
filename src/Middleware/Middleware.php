<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

interface Middleware
{
    /**
     * @param callable(Request): Response $proximo
     */
    public function processar(Request $request, callable $proximo): Response;
}
