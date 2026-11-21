<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrder extends Model
{
    protected $guarded = [];

    protected $casts = ['first_date' => 'date', 'last_date' => 'date'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function reports()
    {
        return $this->hasMany(ProductionReport::class);
    }

    public function sap()
    {
        return $this->hasOne(SapOrderResult::class, 'order_no', 'order_no');
    }

    public function yieldOk(): ?float
    {
        return $this->gi_mm > 0 ? $this->gr_ok_mm / $this->gi_mm * 100 : null;
    }

    public function yieldSapLike(): ?float
    {
        return $this->gi_mm > 0 ? $this->gr_nc_mm / $this->gi_mm * 100 : null;
    }
}
