<?php

namespace App\Providers;

use App\Models\EvaluationCheck;
use App\Models\ProductionFormItem;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Relation::morphMap([
            'production_item' => ProductionFormItem::class,
            'evaluation_check' => EvaluationCheck::class,
        ]);
    }
}
