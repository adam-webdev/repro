<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionReport extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    protected $casts = ['production_date' => 'date'];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function order()
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function defects()
    {
        return $this->hasMany(ProductionDefect::class);
    }

    public function downtimes()
    {
        return $this->hasMany(ProductionDowntime::class);
    }

    public function batch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
