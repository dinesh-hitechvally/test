<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['source', 'status', 'records_processed', 'message'])]
class ScrapeLog extends Model
{
    public const UPDATED_AT = null;
}
