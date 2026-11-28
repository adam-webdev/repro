<?php

/*
|--------------------------------------------------------------------------
| Katalog Produksi
|--------------------------------------------------------------------------
| Dipetakan dari header Excel "daily-operation.xlsx" (Sheet1 = hasil, DT = downtime).
| Header dicocokkan setelah dinormalisasi (huruf kecil, simbol -> spasi),
| jadi perbedaan kapital / underscore / spasi tidak masalah.
*/

return [
    // Lokasi mysqldump untuk backup otomatis (php artisan produksi:reset). Kosong = cari di PATH.
    'mysqldump_path' => env('MYSQLDUMP_PATH', 'mysqldump'),

    // Monitoring Karu (Monitoring Daily): target bawaan jika kolom target di tabel mesin kosong.
    'monitoring' => [
        'target_efficiency' => (float) env('MONITORING_TARGET_EFFICIENCY', 85), // % Act/Std
    ],

    // Data yang lebih lama dari N hari hanya boleh DIGANTI (import ulang) oleh Admin Master.
    'lock_days' => (int) env('PRODUCTION_LOCK_DAYS', 7),

    // Batas penandaan data janggal (grafik sebar downtime vs capaian)
    'anomaly' => [
        'high_ach_high_dt' => ['ach' => 0.90, 'dt' => 0.25], // capaian >= 90% padahal downtime >= 25%
        'low_ach_low_dt' => ['ach' => 0.60, 'dt' => 0.10],   // capaian < 60% padahal downtime <= 10%
        'over_speed' => 1.30,                                // capaian > 130% kapasitas
        'yield_diff' => 2.0,                                 // selisih yield SAP vs lapangan (poin %)
    ],

    // Master mesin (dari Sheet2): kode => [area, gedung]
    'machines' => [
        'CUTC-503' => ['Area 02', 'Gd Eksisting'], 'CUTC-504' => ['Area 03', 'Gd Eksisting'],
        'CUTC-508' => ['Area 03', 'Gd Eksisting'], 'CUTC-511' => ['Area 03', 'Gd Eksisting'],
        'CUTC-513' => ['Area 02', 'Gd Eksisting'], 'CUTC-514' => ['Area 03', 'Gd Eksisting'],
        'CUTC-516' => ['Area 03', 'Gd Eksisting'], 'CUTC-517' => ['Area 02', 'Gd Eksisting'],
        'CUTC-518' => ['Area 03', 'Gd Eksisting'], 'CUTC-521' => ['Area 03', 'Gd Ekspansi'],
        'CUTC-522' => ['Area 03', 'Gd Ekspansi'], 'CUTC-525' => ['Area 03', 'Gd Ekspansi'],
        'CUTH-512' => ['Area 01', 'Gd Eksisting'], 'CUTL-501' => ['Area 01', 'Gd Eksisting'],
        'CUTL-502' => ['Area 01', 'Gd Eksisting'], 'CUTP-501' => ['Area 02', 'Gd Eksisting'],
        'CUTP-502' => ['Area 02', 'Gd Eksisting'], 'CUTP-503' => ['Area 01', 'Gd Eksisting'],
        'CUTP-504' => ['Area 03', 'Gd Eksisting'], 'CUTP-505' => ['Area 03', 'Gd Eksisting'],
        'CUTP-506' => ['Area 03', 'Gd Ekspansi'], 'CUTP-507' => ['Area 03', 'Gd Ekspansi'],
        'CUTP-508' => ['Area 02', 'Gd Eksisting'], 'CUTP-509' => ['Area 03', 'Gd Ekspansi'],
        'CUTP-510' => ['Area 03', 'Gd Ekspansi'], 'CUTP-511' => ['Area 01', 'Gd Eksisting'],
        'CUTP-512' => ['Area 03', 'Gd Ekspansi'], 'CUTS-501' => ['Area 01', 'Gd Eksisting'],
        'DEB-502' => ['Area 03', 'Gd Eksisting'], 'DEB-505' => ['Area 02', 'Gd Eksisting'],
        'DEB-510' => ['Area 03', 'Gd Ekspansi'], 'DEB-511' => ['Area 03', 'Gd Ekspansi'],
        'EBR-507' => ['Area 03', 'Gd Ekspansi'], 'EBR-511' => ['Area 03', 'Gd Eksisting'],
        'EBR-515' => ['Area 03', 'Gd Eksisting'], 'EXSW-501' => ['Area 03', 'Gd Ekspansi'],
        'EXSW-502' => ['Area 03', 'Gd Ekspansi'], 'EXSW-503' => ['Area 03', 'Gd Ekspansi'],
        // Ada di data tapi tidak ada di Sheet2 -> area belum diketahui
        'HZS-501' => [null, null],
    ],

    // Penyebab NC (dari "Keterangan NC") dengan ejaan berbeda tapi maksudnya sama: nama baku => ejaan lain.
    // Teks asli Excel tetap tersimpan di nc_note; yang disamakan hanya nc_cause (grafik Penyebab NC).
    'nc_cause_aliases' => [
        'IB NON REMOVE' => ['IB NO REMOVE', 'IB NO REMOVE N', 'IB TIDAK REMOVE'],
    ],

    // Kolom jenis reject di Sheet1: header (dinormalisasi) => label tampilan
    'defects' => [
        'baret' => 'Baret',
        'seting awal coaxial over swaging' => 'Seting Awal / Coaxial Over',
        'champer miring cembung swaging' => 'Champer Miring / Cembung',
        'ngecap' => 'Ngecap',
        'scratch' => 'Scratch',
        'kependekan circle lost champer haven tidak kepotong laser' => 'Kependekan / Lost Champer',
        'kepanjangan' => 'Kepanjangan',
        'karat' => 'Karat',
        'dent cuting' => 'Dent Cutting',
        'dent champer' => 'Dent Champer',
        'dent smaco' => 'Dent Smaco',
        'champer kasar' => 'Champer Kasar',
        'champer ngetril' => 'Champer Ngetril',
        'inside bead tinggi' => 'Inside Bead Tinggi',
        'las lasan' => 'Las-lasan',
        'burry tinggi kasar' => 'Burry Tinggi / Kasar',
        'penyok cacat remove' => 'Penyok / Cacat Remove',
        'bengkok' => 'Bengkok',
        'dent p horizontal' => 'Dent P. Horizontal',
        'pin tidak masuk' => 'Pin Tidak Masuk',
        'melintir' => 'Melintir',
        'penyok lt' => 'Penyok LT',
        'champer nabrak' => 'Champer Nabrak',
        'cacat lt' => 'Cacat LT',
        'tidak terpotong awal' => 'Tidak Terpotong Awal',
        'tidak terpotong akhir' => 'Tidak Terpotong Akhir',
        'tidak masuk jig' => 'Tidak Masuk Jig',
        'penyok pipa jatuh' => 'Penyok Pipa Jatuh',
        'sambungan' => 'Sambungan',
        'melipat' => 'Melipat',
    ],

    // Kelompok downtime = judul kolom grup di sheet DT Excel: "Set Up" (kolom P–R) dan "Downtime" (kolom T dst.).
    'downtime_categories' => [
        'setup' => 'Set Up',
        'downtime' => 'Downtime',
    ],

    // Kolom downtime di sheet DT: header (dinormalisasi) => [kelompok, nama kolom persis seperti di Excel, header kolom frekuensi (opsional)]
    // Nama ditampilkan sama dengan judul kolom Excel; hanya garis bawah (_) yang dibaca sebagai spasi.
    'downtimes' => [
        'seting awal' => ['setup', 'Seting Awal'],
        'ganti panjang' => ['setup', 'Ganti Panjang'],
        'ganti tebal' => ['setup', 'Ganti Tebal'],

        'persiapan' => ['downtime', 'Persiapan'],
        'g karpet' => ['downtime', 'G Karpet'],
        'seting cuting baret' => ['downtime', 'Seting Cuting Baret'],
        'seting cuting penyok' => ['downtime', 'Seting Cuting Penyok'],
        'seting lost champer' => ['downtime', 'Seting Lost Champer'],
        'seting cekam ngecap' => ['downtime', 'Seting Cekam Ngecap'],
        'potongan lolos' => ['downtime', 'Potongan Lolos'],
        'seting cuting macet' => ['downtime', 'Seting Cuting Macet'],
        'bongkar cekam cuting' => ['downtime', 'Bongkar Cekam Cuting'],
        'seting champer miring' => ['downtime', 'Seting Champer Miring'],
        'pipa nyangkut conveyor penuh circle tunggu proses deburring inline' => ['downtime', 'Pipa Nyangkut Conveyor/Penuh (*Circle Tunggu Proses Deburring Inline)'],
        'seting cropcut' => ['downtime', 'Seting Cropcut'],
        'seting brushing' => ['downtime', 'Seting Brushing'],
        'rantai brushing putus' => ['downtime', 'Rantai Brushing Putus'],
        'seting pipasuport' => ['downtime', 'Seting Pipasuport'],
        'seting conveyor' => ['downtime', 'Seting Conveyor'],
        'seting cuting muter' => ['downtime', 'Seting Cuting Muter'],
        'g rumahpahat' => ['downtime', 'G RumahPahat'],
        'seting champer muter' => ['downtime', 'Seting Champer Muter'],
        'seting panjangpendek' => ['downtime', 'Seting PanjangPendek'],
        'seting pahat champer' => ['downtime', 'Seting Pahat Champer', 'frek seting pahat'],
        'p horizontal' => ['downtime', 'P Horizontal', 'frek horizontal'],
        'p vertical' => ['downtime', 'P Vertical', 'frek vertical'],
        'p plan' => ['downtime', 'P Plan', 'frek plan'],
        'p dalam' => ['downtime', 'P Dalam', 'frek dalam'],
        'p luar' => ['downtime', 'P Luar', 'frek luar'],
        'g gergaji' => ['downtime', 'G Gergaji', 'f gergaji'],
        'cek hitung hasil produksi' => ['downtime', 'Cek & Hitung Hasil produksi'],
        'ganti air collant' => ['downtime', 'Ganti Air Collant'],
        'tambah oli hidrolik' => ['downtime', 'Tambah Oli Hidrolik'],
        'bersihkan sisa potongan' => ['downtime', 'Bersihkan Sisa Potongan'],
        'lain lain' => ['downtime', 'Lain -Lain'],
        'komponen mekanik elektrik rusak' => ['downtime', 'Komponen Mekanik/Elektrik Rusak'],
        'hydrolik rusak' => ['downtime', 'Hydrolik Rusak'],
        'perbaikan panel rusak' => ['downtime', 'Perbaikan Panel Rusak'],
        'listrik mati' => ['downtime', 'Listrik Mati'],
        'compressor tekanan kurang rusak' => ['downtime', 'COMPRESSOR TEKANAN KURANG / RUSAK'],
        'preventive' => ['downtime', 'PREVENTIVE'],
        '-' => ['downtime', '-'], // dua kolom berjudul "-" (BQ & CE) dijumlah
        'asah pahat' => ['downtime', 'Asah Pahat'],
        'burning feed roll' => ['downtime', 'Burning Feed Roll'],
        'tunggu mtc' => ['downtime', 'Tunggu MTC'],
        'be' => ['downtime', 'BE'],
        'tunggu kep ppic' => ['downtime', 'Tunggu Kep PPIC'],
        'tunggu kep qc verifikasi lt' => ['downtime', 'Verifikasi LT'],
        'tunggu bahan' => ['downtime', 'Tunggu Bahan'],
        'pindah box hasil produksi' => ['downtime', 'Pindah Box Hasil Produksi'],
        'tunggu asah gergaji' => ['downtime', 'Tunggu Asah Gergaji'],
        'tunggu box andang' => ['downtime', 'Tunggu Box/Andang'],
        'pindah ke mesin lain' => ['downtime', 'Pindah Ke Mesin Lain'],
        'waktu hilang' => ['downtime', 'Waktu Hilang'],
        'meeting' => ['downtime', 'Meeting'],
        '5r' => ['downtime', '5R'],
    ],

    // Kolom teks keterangan di sheet DT (digabung jadi satu catatan)
    'downtime_notes' => [
        'keterangan seting pahat', 'keterangan ganti pahat', 'keterangan',
        'keterangan down time tinggi', 'ket',
    ],

    /*
    |--------------------------------------------------------------------------
    | Form Karu
    |--------------------------------------------------------------------------
    | Jenis mesin menentukan opsi DT yang muncul. Mesin dikenali dari awalan kodenya.
    | Opsi DT ditulis: label => key kolom DT di Excel (untuk dicocokkan dengan data import), null = tidak ada padanan.
    | Setiap dropdown otomatis punya opsi "Lainnya" (isi manual) di form.
    */
    'machine_types' => [
        'laser' => ['label' => 'Mesin Laser', 'prefixes' => ['CUTL']],
        'exs' => ['label' => 'Mesin EXS (EX Swaging)', 'prefixes' => ['EXSW']],
        'umum' => ['label' => 'Mesin lainnya', 'prefixes' => []],
    ],

    'form_options' => [
        'setup' => [
            'Setting Awal' => 'seting awal',
            'Ganti Panjang' => 'ganti panjang',
            'Ganti Tebal' => 'ganti tebal',
        ],
        // Bongkar (di form karu tampil setelah Set Up); dihitung sebagai downtime
        'bongkar' => [
            'Bongkar Pasang Cekam' => 'bongkar cekam cuting',
            'Lain-lain' => 'lain lain',
        ],
        'dt' => [
            'umum' => [
                'Briefing' => null,
                '5R' => '5r',
                'Ganti Karpet' => 'g karpet',
                'Seting Cuting Baret' => 'seting cuting baret',
                'Seting Cuting Penyok' => 'seting cuting penyok',
                'Seting Lost Champer' => 'seting lost champer',
                'Seting Cekam Ngecap' => 'seting cekam ngecap',
                'Potongan Lolos / Setting Stopper' => 'potongan lolos',
                'Seting Cuting Macet' => 'seting cuting macet',
                'Bongkar pasang Cekam (setting deformasi)' => 'bongkar cekam cuting',
                'Setting Champer Miring / Cutting Miring' => 'seting champer miring',
                'Pipa Nyangkut' => 'pipa nyangkut conveyor penuh circle tunggu proses deburring inline',
                'Seting Conveyer' => 'seting conveyor',
                'Seting Cropcut' => 'seting cropcut',
                'Seting Brushing' => 'seting brushing',
                'Rantai Brushing Putus' => 'rantai brushing putus',
                'Seting Pipasuport' => 'seting pipasuport',
                'Seting Cuting Muter' => 'seting cuting muter',
                'Ganti Rumah Pahat' => 'g rumahpahat',
                'Seting Champer Muter' => 'seting champer muter',
                'Seting Panjang Pendek' => 'seting panjangpendek',
                'Seting Pahat Champer' => 'seting pahat champer',
                'Ganti Pahat Horizontal' => 'p horizontal',
                'Ganti Pahat Vertikal' => 'p vertical',
                'Ganti Pahat Flank' => null,
                'Ganti Pahat Dalam' => 'p dalam',
                'Ganti Pahat Luar' => 'p luar',
                'Ganti Gergaji' => 'g gergaji',
                'Asah Pahat' => 'asah pahat',
                'Burning Feed Roll' => 'burning feed roll',
                'Tunggu Asah Gergaji' => 'tunggu asah gergaji',
                'Ganti Air Collant' => 'ganti air collant',
                'Tambah Oli Hidrolik' => 'tambah oli hidrolik',
                'Bersihkan Sisa Potongan' => 'bersihkan sisa potongan',
                'Komponen Mekanik/Elektrik Rusak' => 'komponen mekanik elektrik rusak',
                'Hydrolik Rusak' => 'hydrolik rusak',
                'Perbaikan Panel Rusak' => 'perbaikan panel rusak',
                'Listrik Mati' => 'listrik mati',
                'Compressor Tekanan Kurang / Rusak' => 'compressor tekanan kurang rusak',
                'Preventive' => 'preventive',
                'Tunggu MTC' => 'tunggu mtc',
                'Tunggu Kep PPIC' => 'tunggu kep ppic',
                'Tunggu Kep QC' => 'tunggu kep qc verifikasi lt',
                'Verifikasi LT' => 'tunggu kep qc verifikasi lt',
                'Tunggu Bahan' => 'tunggu bahan',
                'Pindah Box Hasil Produksi' => 'pindah box hasil produksi',
                'Tunggu Box/Andang' => 'tunggu box andang',
                'Pindah Ke Mesin Lain' => 'pindah ke mesin lain',
                'Packing Inline' => null,
            ],
            'laser' => [
                'Ganti Gas' => null, 'Marking LT' => null, 'Charge Cekam' => null, 'Bersihkan Nozzle' => null,
                'Seting Parameter' => null, 'Loader Error' => null, 'Repair Burry' => null, 'Pembersihan Lensa' => null,
                'Ganti Lensa' => null, 'Seting Bearing' => null, 'Setting Enginering' => null,
                'Greasing Mesin Laser' => null, 'Pembuatan Sample' => null,
            ],
            'exs' => [
                'Seting Coaxial Ekpand' => null, 'Seting Coaxial Swaging' => null, 'Bongkar/Pasang Dies' => null,
                'Pembersihan Dies' => null, 'Repair Gosok Dies' => null,
            ],
        ],
        'compliance' => [
            'Penggunaan APD', '5R', 'Pengisian history P.cutting', 'Pengisian history P chamfer', 'Pengisian history Gergaji',
            'Pengecekan visual pipa', // wajib disertai foto / link gambar (lihat FormOption::VISUAL_PIPE)
        ],
        'visual' => ['OK', 'NG', 'Perlu tindak lanjut'],
        'jig' => ['JIG In', 'JIG Out', 'JIG Panjang', 'JIG Go/Nogo'],
    ],

    // Kontak Admin Master untuk reset password (link "Lupa password?" di halaman login)
    'admin_contact' => [
        'name' => env('ADMIN_CONTACT_NAME', 'Rizki'),
        'whatsapp' => env('ADMIN_CONTACT_WHATSAPP', '6285156532626'), // format wa.me: kode negara tanpa +
        'whatsapp_display' => env('ADMIN_CONTACT_WHATSAPP_DISPLAY', '+62 851-5653-2626'),
    ],

    // Jadwal shift karu: 2 shift sehari, 6 karu per shift. Shift 2 melewati tengah malam (tanggal = tanggal mulai shift).
    'karu_shifts' => [
        1 => ['start' => '07:00', 'end' => '18:00'],
        2 => ['start' => '18:00', 'end' => '07:00'],
    ],

    // Data awal Karu (seed)
    'karu' => [
        ['KA 044', 'Kurniawan'], ['KA 072', 'Wakhyu Hendrik Subekti'], ['KA 155', 'Andika Mandala Putra'],
        ['KA 033', 'Purwadi'], ['KA 067', 'Firyono'], ['KA 021', 'Endang Suhendar'], ['KA 075', 'Edi Supriadi'],
        ['KA 071', 'Triyono'], ['KA 078', 'Safrul Hidayat'], ['KA 054', 'Syarif Hidayatulloh'],
        ['KA 070', 'Sugeng Ismail'], ['KA 046', 'Dadang Iskandar'],
    ],
];
