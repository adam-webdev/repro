<form method="GET" class="card card-pad !py-3 flex flex-wrap items-end gap-2.5">
    <label class="block"><span class="label">Dari</span><input type="date" name="from" value="{{ $from }}" class="input"></label>
    <label class="block"><span class="label">Sampai</span><input type="date" name="to" value="{{ $to }}" class="input"></label>
    <label class="block"><span class="label">Shift</span>
        <select name="shift" class="input">
            <option value="">Semua</option>
            @foreach ([1, 2, 3] as $s)<option value="{{ $s }}" @selected(request('shift') == $s)>Shift {{ $s }}</option>@endforeach
        </select>
    </label>
    <label class="block"><span class="label">Area</span>
        <select name="area_id" class="input">
            <option value="">Semua</option>
            @foreach ($areas as $a)<option value="{{ $a->id }}" @selected(request('area_id') == $a->id)>{{ $a->name }}</option>@endforeach
        </select>
    </label>
    @if (auth()->user()->role !== 'karu')
        <label class="block"><span class="label">Karu</span>
            <select name="user_id" class="input">
                <option value="">Semua</option>
                @foreach ($karus as $k)<option value="{{ $k->id }}" @selected(request('user_id') == $k->id)>{{ $k->name }}</option>@endforeach
            </select>
        </label>
    @endif
    <button class="btn">Terapkan</button>
    @if (request()->hasAny(['from', 'to', 'shift', 'area_id', 'user_id']))
        <a href="{{ url()->current() }}" class="btn">Reset</a>
    @endif
</form>
