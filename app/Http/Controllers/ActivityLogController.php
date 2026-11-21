<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $to = Carbon::parse($request->input('to') ?: now())->endOfDay();
        $from = Carbon::parse($request->input('from') ?: now()->subDays(29))->startOfDay();

        $logs = ActivityLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')))
            ->when($request->filled('type'), fn ($q) => $q->where('subject_type', $request->input('type')))
            ->when($request->filled('q'), fn ($q) => $q->where('subject_label', 'like', '%'.trim($request->input('q')).'%'))
            ->latest('id')
            ->paginate(50)->withQueryString();

        return view('activity.index', [
            'logs' => $logs,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'users' => User::withTrashed()->orderBy('name')->get(['id', 'name', 'username']),
        ]);
    }
}
