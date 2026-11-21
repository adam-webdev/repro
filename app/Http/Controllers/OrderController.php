<?php

namespace App\Http\Controllers;

use App\Models\ProductionOrder;

class OrderController extends Controller
{
    public function show(string $orderNo)
    {
        $order = ProductionOrder::with(['customer', 'material', 'sap'])->where('order_no', $orderNo)->firstOrFail();
        $reports = $order->reports()->with('machine:id,code')
            ->orderBy('production_date')->orderBy('shift')->orderBy('start_time')->get();

        return view('orders.show', compact('order', 'reports'));
    }
}
