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
        'CUTC-516' => ['Area 04', 'Gd Eksisting'], 'CUTC-517' => ['Area 02', 'Gd Eksisting'],
        'CUTC-518' => ['Area 03', 'Gd Eksisting'], 'CUTC-521' => ['Area 04', 'Gd Ekspansi'],
        'CUTC-522' => ['Area 04', 'Gd Ekspansi'], 'CUTC-525' => ['Area 04', 'Gd Ekspansi'],
        'CUTH-512' => ['Area 01', 'Gd Eksisting'], 'CUTL-501' => ['Area 01', 'Gd Eksisting'],
        'CUTL-502' => ['Area 01', 'Gd Eksisting'], 'CUTP-501' => ['Area 02', 'Gd Eksisting'],
        'CUTP-502' => ['Area 02', 'Gd Eksisting'], 'CUTP-503' => ['Area 01', 'Gd Eksisting'],
        'CUTP-504' => ['Area 03', 'Gd Eksisting'], 'CUTP-505' => ['Area 03', 'Gd Eksisting'],
        'CUTP-506' => ['Area 04', 'Gd Ekspansi'], 'CUTP-507' => ['Area 04', 'Gd Ekspansi'],
        'CUTP-508' => ['Area 02', 'Gd Eksisting'], 'CUTP-509' => ['Area 04', 'Gd Ekspansi'],
        'CUTP-510' => ['Area 04', 'Gd Ekspansi'], 'CUTP-511' => ['Area 01', 'Gd Eksisting'],
        'CUTP-512' => ['Area 04', 'Gd Ekspansi'], 'CUTS-501' => ['Area 01', 'Gd Eksisting'],
        'DEB-502' => ['Area 03', 'Gd Eksisting'], 'DEB-505' => ['Area 02', 'Gd Eksisting'],
        'DEB-510' => ['Area 04', 'Gd Ekspansi'], 'DEB-511' => ['Area 04', 'Gd Ekspansi'],
        'EBR-507' => ['Area 04', 'Gd Ekspansi'], 'EBR-511' => ['Area 03', 'Gd Eksisting'],
        'EBR-515' => ['Area 03', 'Gd Eksisting'], 'EXSW-501' => ['Area 04', 'Gd Ekspansi'],
        'EXSW-502' => ['Area 04', 'Gd Ekspansi'], 'EXSW-503' => ['Area 04', 'Gd Ekspansi'],
        // Ada di data tapi tidak ada di Sheet2 -> area belum diketahui
        'HZS-501' => [null, null],
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

    // Kategori downtime (urutan = urutan warna di grafik)
    'downtime_categories' => [
        'menunggu' => 'Menunggu',
        'setup' => 'Set Up',
        'setting' => 'Setting Proses',
        'tooling' => 'Ganti Pahat & Gergaji',
        'rutin' => 'Persiapan & Rutin',
        'breakdown' => 'Kerusakan Mesin',
        'preventive' => 'Preventive',
        'lain' => 'Lain-lain',
    ],

    // Kolom downtime di sheet DT: header (dinormalisasi) => [kategori, label, header kolom frekuensi (opsional)]
    'downtimes' => [
        'seting awal' => ['setup', 'Seting Awal'],
        'ganti panjang' => ['setup', 'Ganti Panjang'],
        'ganti tebal' => ['setup', 'Ganti Tebal'],

        'persiapan' => ['rutin', 'Persiapan'],
        'cek hitung hasil produksi' => ['rutin', 'Cek & Hitung Hasil Produksi'],
        'ganti air collant' => ['rutin', 'Ganti Air Coolant'],
        'tambah oli hidrolik' => ['rutin', 'Tambah Oli Hidrolik'],
        'bersihkan sisa potongan' => ['rutin', 'Bersihkan Sisa Potongan'],
        'pindah box hasil produksi' => ['rutin', 'Pindah Box Hasil Produksi'],
        'meeting' => ['rutin', 'Meeting'],
        '5r' => ['rutin', '5R'],

        'g karpet' => ['setting', 'Ganti Karpet'],
        'seting cuting baret' => ['setting', 'Seting Cutting Baret'],
        'seting cuting penyok' => ['setting', 'Seting Cutting Penyok'],
        'seting lost champer' => ['setting', 'Seting Lost Champer'],
        'seting cekam ngecap' => ['setting', 'Seting Cekam Ngecap'],
        'potongan lolos' => ['setting', 'Potongan Lolos'],
        'seting cuting macet' => ['setting', 'Seting Cutting Macet'],
        'bongkar cekam cuting' => ['setting', 'Bongkar Cekam Cutting'],
        'seting champer miring' => ['setting', 'Seting Champer Miring'],
        'pipa nyangkut conveyor penuh circle tunggu proses deburring inline' => ['setting', 'Pipa Nyangkut / Conveyor Penuh'],
        'seting cropcut' => ['setting', 'Seting Cropcut'],
        'seting brushing' => ['setting', 'Seting Brushing'],
        'seting pipasuport' => ['setting', 'Seting Pipa Support'],
        'seting conveyor' => ['setting', 'Seting Conveyor'],
        'seting cuting muter' => ['setting', 'Seting Cutting Muter'],
        'seting champer muter' => ['setting', 'Seting Champer Muter'],
        'seting panjangpendek' => ['setting', 'Seting Panjang Pendek'],

        'g rumahpahat' => ['tooling', 'Ganti Rumah Pahat'],
        'seting pahat champer' => ['tooling', 'Seting Pahat Champer', 'frek seting pahat'],
        'p horizontal' => ['tooling', 'Pahat Horizontal', 'frek horizontal'],
        'p vertical' => ['tooling', 'Pahat Vertical', 'frek vertical'],
        'p plan' => ['tooling', 'Pahat Plan', 'frek plan'],
        'p dalam' => ['tooling', 'Pahat Dalam', 'frek dalam'],
        'p luar' => ['tooling', 'Pahat Luar', 'frek luar'],
        'g gergaji' => ['tooling', 'Ganti Gergaji', 'f gergaji'],
        'asah pahat' => ['tooling', 'Asah Pahat'],

        'rantai brushing putus' => ['breakdown', 'Rantai Brushing Putus'],
        'komponen mekanik elektrik rusak' => ['breakdown', 'Komponen Mekanik/Elektrik Rusak'],
        'hydrolik rusak' => ['breakdown', 'Hidrolik Rusak'],
        'perbaikan panel rusak' => ['breakdown', 'Perbaikan Panel Rusak'],
        'listrik mati' => ['breakdown', 'Listrik Mati'],
        'compressor tekanan kurang rusak' => ['breakdown', 'Compressor Kurang/Rusak'],
        'burning feed roll' => ['breakdown', 'Burning Feed Roll'],
        'be' => ['breakdown', 'BE'],

        'preventive' => ['preventive', 'Preventive'],

        'tunggu mtc' => ['menunggu', 'Tunggu MTC'],
        'tunggu kep ppic' => ['menunggu', 'Tunggu Keputusan PPIC'],
        'tunggu kep qc verifikasi lt' => ['menunggu', 'Tunggu Kep. QC / Verifikasi LT'],
        'tunggu bahan' => ['menunggu', 'Tunggu Bahan'],
        'tunggu asah gergaji' => ['menunggu', 'Tunggu Asah Gergaji'],
        'tunggu box andang' => ['menunggu', 'Tunggu Box / Andang'],

        'lain lain' => ['lain', 'Lain-lain'],
        '-' => ['lain', 'Tanpa Keterangan'],
        'pindah ke mesin lain' => ['lain', 'Pindah ke Mesin Lain'],
        'waktu hilang' => ['lain', 'Waktu Hilang'],
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
        ],
        'visual' => ['OK', 'NG', 'Perlu tindak lanjut'],
        'jig' => ['JIG In/Out', 'JIG Panjang', 'JIG GRONOGO'],
    ],

    // Data awal Karu (seed)
    'karu' => [
        ['KA 044', 'Kurniawan'], ['KA 072', 'Wakhyu Hendrik Subekti'], ['KA 155', 'Andika Mandala Putra'],
        ['KA 033', 'Purwadi'], ['KA 067', 'Firyono'], ['KA 021', 'Endang Suhendar'], ['KA 075', 'Edi Supriadi'],
        ['KA 071', 'Triyono'], ['KA 078', 'Safrul Hidayat'], ['KA 054', 'Syarif Hidayatulloh'],
        ['KA 070', 'Sugeng Ismail'], ['KA 046', 'Dadang Iskandar'],
    ],
];
