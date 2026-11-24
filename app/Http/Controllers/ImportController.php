<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Services\Import\ImportService;
use App\Services\Import\ProductionDataReset;
use App\Services\Import\TemplateBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportController extends Controller
{
    public function index(Request $request)
    {
        $month = Carbon::parse(($request->input('month') ?: now()->format('Y-m')).'-01');
        $counts = DB::table('production_reports')->whereNull('deleted_at')
            ->whereBetween('production_date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->groupBy('production_date', 'shift')
            ->selectRaw('production_date d, shift s, COUNT(*) c')->get()
            ->groupBy(fn ($r) => Carbon::parse($r->d)->toDateString())
            ->map(fn ($rows) => $rows->pluck('c', 's')->all());

        return view('imports.index', [
            'batches' => ImportBatch::with('user')->latest()->paginate(15),
            'month' => $month,
            'counts' => $counts,
            'resetCounts' => $request->user()->role === 'master' ? app(ProductionDataReset::class)->counts() : null,
        ]);
    }

    /** Unduh template Excel. ?contoh=1 -> berisi 3 baris contoh (tanggal hari ini, shift 1) untuk uji coba. */
    public function template(Request $request, TemplateBuilder $builder)
    {
        $withExamples = $request->boolean('contoh');
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $builder->build($path, $withExamples);

        return response()->download($path, $withExamples ? 'template_import_produksi_CONTOH.xlsx' : 'template_import_produksi.xlsx')
            ->deleteFileAfterSend();
    }

    public function store(Request $request, ImportService $service)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx|max:51200'], [
            'file.required' => 'Pilih file Excel terlebih dahulu.',
            'file.mimes' => 'File harus berformat .xlsx',
            'file.max' => 'Ukuran file maksimal 50 MB',
        ]);
        $file = $request->file('file');
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());
        $path = $file->storeAs('imports', now()->format('Ymd_His').'_'.$safe, 'local');

        $batch = ImportBatch::create([
            'type' => 'daily',
            'user_id' => $request->user()->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => 'preview',
        ]);
        $service->preview($batch);

        return redirect()->route('imports.show', $batch);
    }

    public function show(ImportBatch $import, ImportService $service, Request $request)
    {
        return view('imports.show', [
            'batch' => $import->load('user'),
            'issues' => $import->errors()->orderByRaw("level = 'error' DESC")->orderBy('row_no')->limit(300)->get(),
            'errorCount' => $import->errors()->where('level', 'error')->count(),
            'warningCount' => $import->errors()->where('level', 'warning')->count(),
            'locked' => $import->type === 'daily' && $import->status === 'preview'
                ? $service->lockedGroups($import->groups ?? [], $request->user()) : [],
        ]);
    }

    public function commit(ImportBatch $import, ImportService $service, Request $request)
    {
        try {
            $service->commit($import, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $msg = "{$import->rows_imported} baris berhasil disimpan";
        if ($import->rows_replaced) {
            $msg .= ", {$import->rows_replaced} baris lama diganti";
        }

        return redirect()->route('imports.show', $import)->with('success', $msg.'.');
    }

    /** Hapus seluruh data satu tanggal + shift (soft delete, wajib alasan, tercatat di log). */
    /** Admin Master: kosongkan seluruh data hasil import Excel (akun, master data & form karu tetap). */
    public function reset(Request $request, ProductionDataReset $reset): RedirectResponse
    {
        $request->validate([
            'confirmation' => 'required|in:KOSONGKAN',
            'reason' => 'required|string|min:5|max:200',
            'skip_backup' => 'nullable|boolean',
        ], [
            'confirmation.required' => 'Ketik KOSONGKAN untuk konfirmasi.',
            'confirmation.in' => 'Ketik KOSONGKAN (huruf besar) untuk konfirmasi.',
            'reason.required' => 'Alasan wajib diisi.',
            'reason.min' => 'Alasan minimal 5 karakter.',
        ]);

        @set_time_limit(0); // backup + hapus puluhan ribu baris bisa lebih dari batas default 30 detik
        $backup = null;
        if (! $request->boolean('skip_backup')) {
            try {
                $backup = $reset->backup();
            } catch (RuntimeException $e) {
                return back()->withInput()->with('error', $e->getMessage().' Centang "Lanjut tanpa backup" bila tetap ingin mengosongkan.');
            }
        }

        try {
            $counts = $reset->reset($request->user(), trim($request->input('reason')));
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('imports.index')->with('success', number_format($counts['production_reports'], 0, ',', '.')
            .' baris data produksi dan '.$counts['import_batches'].' riwayat import dihapus. Akun, master data & form karu tetap.'
            .($backup ? ' Backup: storage/backups/'.basename($backup) : ''));
    }

    public function destroyGroup(Request $request, ImportService $service)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'shift' => 'required|integer|in:1,2,3',
            'reason' => 'required|string|min:5|max:200',
        ], ['reason.required' => 'Alasan penghapusan wajib diisi.', 'reason.min' => 'Alasan minimal 5 karakter.']);

        $date = Carbon::parse($data['date'])->toDateString();
        try {
            $n = $service->deleteGroup($date, (int) $data['shift'], $request->user(), trim($data['reason']));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('reports.index', ['from' => $date, 'to' => $date, 'shift' => $data['shift']])
            ->with('success', "{$n} baris data ".Carbon::parse($date)->format('d/m/Y')." shift {$data['shift']} dihapus. Data lama tetap tersimpan di riwayat.");
    }

    public function cancel(ImportBatch $import)
    {
        if ($import->status === 'preview') {
            $import->update(['status' => 'cancelled']);
            @unlink($import->parsedPath());
        }

        return redirect()->route('imports.index')->with('success', 'Import dibatalkan.');
    }
}
