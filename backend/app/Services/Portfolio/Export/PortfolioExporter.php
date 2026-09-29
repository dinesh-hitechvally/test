<?php

namespace App\Services\Portfolio\Export;

use App\Models\Portfolio;
use Symfony\Component\HttpFoundation\Response;

/**
 * One downloadable portfolio report format. A new format (e.g. JSON) is a
 * new implementation plus a route — PortfolioExportController and the existing
 * formats stay untouched.
 */
interface PortfolioExporter
{
    public function download(Portfolio $portfolio): Response;
}
