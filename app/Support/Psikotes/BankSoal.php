<?php

namespace App\Support\Psikotes;

/**
 * Bank soal psikotes rekrutmen (menu HR → Psikotes), disusun sendiri — BUKAN kuesioner MBTI® resmi (merek dagang,
 * berlisensi) maupun instrumen DISC komersial. Belum divalidasi psikometri: hasil = info pendukung wawancara, bukan
 * satu-satunya dasar keputusan. Ubah soal = ubah konstanta di sini (urutan = nomor soal; jawaban tersimpan per indeks,
 * jadi JANGAN menyisipkan/menghapus di tengah setelah ada sesi berjalan — tambah di akhir saja).
 */
final class BankSoal
{
    /** Batas waktu tes logika & hitung (menit). */
    public const MENIT_LOGIKA = 15;

    /**
     * Kepribadian 16 tipe: 40 pernyataan, skala 1 (sangat tidak setuju) – 5 (sangat setuju).
     * [pernyataan, dimensi, kutub yang didukung bila setuju]
     */
    public const KEPRIBADIAN = [
        ['Saya mendapat energi setelah mengobrol dengan banyak orang.', 'EI', 'E'],
        ['Saya lebih suka bekerja sendiri di tempat yang tenang.', 'EI', 'I'],
        ['Dalam rapat, saya biasanya cepat menyampaikan pendapat.', 'EI', 'E'],
        ['Saya perlu waktu menyendiri setelah acara yang ramai.', 'EI', 'I'],
        ['Saya mudah memulai percakapan dengan orang yang baru dikenal.', 'EI', 'E'],
        ['Saya lebih nyaman berkomunikasi lewat tulisan daripada telepon.', 'EI', 'I'],
        ['Saya senang bekerja dalam tim yang ramai dan dinamis.', 'EI', 'E'],
        ['Saya berpikir matang-matang dulu sebelum bicara.', 'EI', 'I'],
        ['Akhir pekan ideal saya adalah berkumpul bersama teman-teman.', 'EI', 'E'],
        ['Saya lebih suka punya sedikit teman dekat daripada banyak kenalan.', 'EI', 'I'],
        ['Saya lebih percaya pada fakta dan pengalaman nyata.', 'SN', 'S'],
        ['Saya senang membayangkan kemungkinan dan ide-ide baru.', 'SN', 'N'],
        ['Saya suka instruksi kerja yang jelas langkah demi langkah.', 'SN', 'S'],
        ['Saya cepat bosan dengan pekerjaan yang rutin.', 'SN', 'N'],
        ['Saya teliti memperhatikan detail-detail kecil.', 'SN', 'S'],
        ['Saya lebih tertarik pada gambaran besar daripada detailnya.', 'SN', 'N'],
        ['Saya lebih suka cara yang sudah terbukti berhasil.', 'SN', 'S'],
        ['Saya sering punya ide untuk mengubah cara kerja yang sudah ada.', 'SN', 'N'],
        ['Saya menilai sesuatu dari hasil yang terlihat nyata.', 'SN', 'S'],
        ['Saya senang membahas konsep dan teori.', 'SN', 'N'],
        ['Saat mengambil keputusan, saya mengutamakan logika dan data.', 'TF', 'T'],
        ['Saat mengambil keputusan, saya memikirkan perasaan orang yang terdampak.', 'TF', 'F'],
        ['Saya bisa memberi kritik secara langsung walau terasa tidak enak.', 'TF', 'T'],
        ['Menjaga suasana tim tetap harmonis itu penting bagi saya.', 'TF', 'F'],
        ['Aturan sebaiknya diterapkan sama untuk semua orang tanpa pengecualian.', 'TF', 'T'],
        ['Saya mudah merasakan ketika rekan kerja sedang punya masalah.', 'TF', 'F'],
        ['Saya memilih berkata jujur apa adanya meski bisa menyinggung.', 'TF', 'T'],
        ['Saya senang membantu orang lain walau itu bukan tugas saya.', 'TF', 'F'],
        ['Saya menilai ide dari masuk akal atau tidaknya, bukan dari siapa pengusulnya.', 'TF', 'T'],
        ['Pujian dan penghargaan sangat memotivasi saya.', 'TF', 'F'],
        ['Saya suka membuat rencana dan jadwal sebelum mulai bekerja.', 'JP', 'J'],
        ['Saya nyaman menyesuaikan diri ketika rencana berubah mendadak.', 'JP', 'P'],
        ['Saya biasanya menyelesaikan tugas jauh sebelum tenggat.', 'JP', 'J'],
        ['Saya sering mengerjakan tugas mendekati tenggat.', 'JP', 'P'],
        ['Meja dan berkas kerja saya selalu rapi dan teratur.', 'JP', 'J'],
        ['Saya suka membiarkan pilihan tetap terbuka selama mungkin.', 'JP', 'P'],
        ['Saya merasa lega setelah sebuah keputusan diambil.', 'JP', 'J'],
        ['Saya senang melakukan hal spontan tanpa banyak rencana.', 'JP', 'P'],
        ['Saya lebih suka menyelesaikan satu pekerjaan sebelum memulai yang lain.', 'JP', 'J'],
        ['Saya bisa mengerjakan beberapa hal sekaligus dengan fleksibel.', 'JP', 'P'],
    ];

    public const SKALA = [1 => 'Sangat tidak setuju', 2 => 'Tidak setuju', 3 => 'Netral', 4 => 'Setuju', 5 => 'Sangat setuju'];

    /** Ringkasan 16 tipe (bahasa kerja, bukan diagnosis). */
    public const TIPE = [
        'ISTJ' => 'Teliti dan bertanggung jawab, kuat menjalankan prosedur.',
        'ISFJ' => 'Setia dan suka membantu, rapi menjaga detail.',
        'INFJ' => 'Visioner dan peduli, kuat pada makna dan nilai.',
        'INTJ' => 'Strategis dan mandiri, suka rencana jangka panjang.',
        'ISTP' => 'Praktis dan tenang, cepat memecahkan masalah teknis.',
        'ISFP' => 'Lembut dan fleksibel, peka terhadap estetika.',
        'INFP' => 'Idealis dan empatik, kreatif dalam ide.',
        'INTP' => 'Analitis dan ingin tahu, menyukai konsep dan logika.',
        'ESTP' => 'Energik dan berani, cepat bertindak.',
        'ESFP' => 'Ceria dan spontan, pandai mencairkan suasana.',
        'ENFP' => 'Antusias dan kreatif, pandai memotivasi orang.',
        'ENTP' => 'Inovatif dan suka adu ide, cepat melihat peluang.',
        'ESTJ' => 'Teratur dan tegas, kuat mengelola orang dan proses.',
        'ESFJ' => 'Hangat dan kooperatif, menjaga keharmonisan tim.',
        'ENFJ' => 'Karismatik dan peduli, pandai menggerakkan tim.',
        'ENTJ' => 'Pemimpin alami, tegas pada target dan strategi.',
    ];

    /**
     * Gaya kerja DISC: 24 kelompok kata [D, I, S, C]. Peserta memilih satu yang PALING dan satu yang PALING TIDAK
     * menggambarkan dirinya. Urutan tampil diacak tetap per kelompok (lihat PsikotesService::urutanDisc).
     */
    public const DISC = [
        ['Tegas', 'Ramah', 'Sabar', 'Teliti'],
        ['Berani', 'Antusias', 'Setia', 'Akurat'],
        ['Kompetitif', 'Persuasif', 'Tenang', 'Sistematis'],
        ['Lugas', 'Ekspresif', 'Pendengar yang baik', 'Hati-hati'],
        ['Mandiri', 'Optimis', 'Konsisten', 'Disiplin'],
        ['Berani ambil risiko', 'Mudah bergaul', 'Suka membantu', 'Patuh aturan'],
        ['Cepat memutuskan', 'Penuh semangat', 'Stabil', 'Analitis'],
        ['Berorientasi hasil', 'Pandai bicara', 'Kooperatif', 'Perfeksionis'],
        ['Keras kepala', 'Spontan', 'Penurut', 'Kritis'],
        ['Pemimpin', 'Penghibur', 'Pendamai', 'Perencana'],
        ['Ambisius', 'Supel', 'Rendah hati', 'Logis'],
        ['Gigih', 'Inspiratif', 'Penyabar', 'Rapi'],
        ['Blak-blakan', 'Hangat', 'Pengertian', 'Cermat'],
        ['Pemberani', 'Ceria', 'Dapat diandalkan', 'Teratur'],
        ['Suka tantangan', 'Suka keramaian', 'Suka ketenangan', 'Suka kepastian'],
        ['Pantang menyerah', 'Mudah akrab', 'Setia kawan', 'Taat prosedur'],
        ['Mengambil alih', 'Menyemangati', 'Mendukung', 'Memeriksa'],
        ['Tegas soal target', 'Pandai meyakinkan', 'Tekun', 'Objektif'],
        ['Agresif', 'Lincah', 'Santai', 'Formal'],
        ['Berinisiatif', 'Humoris', 'Tenggang rasa', 'Berhati-hati'],
        ['Fokus menang', 'Fokus relasi', 'Fokus harmoni', 'Fokus kualitas'],
        ['Dominan', 'Komunikatif', 'Loyal', 'Presisi'],
        ['Cepat bertindak', 'Kreatif', 'Mantap', 'Terstruktur'],
        ['Tidak sabaran', 'Mudah teralihkan', 'Enggan berubah', 'Terlalu detail'],
    ];

    public const GAYA_DISC = [
        'D' => 'Dominan — tegas, berorientasi hasil, menyukai tantangan.',
        'I' => 'Influence — komunikatif, antusias, pandai membangun relasi.',
        'S' => 'Steady — sabar, setia, stabil, pendukung tim yang andal.',
        'C' => 'Compliance — teliti, analitis, menjaga standar dan kualitas.',
    ];

    /** Logika & hitung: [soal, [4 pilihan], indeks jawaban benar]. */
    public const LOGIKA = [
        ['Lanjutkan deret: 2, 4, 8, 16, …', ['24', '30', '32', '34'], 2],
        ['Lanjutkan deret: 3, 6, 9, 12, …', ['13', '14', '15', '18'], 2],
        ['Lanjutkan deret: 1, 4, 9, 16, 25, …', ['30', '34', '36', '49'], 2],
        ['Lanjutkan deret: 50, 45, 40, 35, …', ['25', '30', '32', '33'], 1],
        ['Lanjutkan deret: 2, 3, 5, 8, 13, …', ['18', '20', '21', '26'], 2],
        ['Harga produk Rp80.000 mendapat diskon 25%. Berapa harga setelah diskon?', ['Rp55.000', 'Rp60.000', 'Rp62.500', 'Rp65.000'], 1],
        ['Stok awal 120 pcs, terjual 45 pcs, lalu masuk lagi 30 pcs. Berapa stok akhir?', ['95 pcs', '100 pcs', '105 pcs', '115 pcs'], 2],
        ['3 orang menyelesaikan sebuah pekerjaan dalam 6 hari. Dengan kecepatan yang sama, berapa hari jika dikerjakan 6 orang?', ['2 hari', '3 hari', '4 hari', '12 hari'], 1],
        ['Satu dus berisi 24 pcs. Berapa dus minimal untuk mengemas 300 pcs?', ['12 dus', '12,5 dus', '13 dus', '14 dus'], 2],
        ['Omzet bulan lalu Rp10 juta, bulan ini Rp12 juta. Berapa persen kenaikannya?', ['12%', '15%', '20%', '25%'], 2],
        ['Semua produk serum adalah produk wajah. Semua produk wajah wajib lolos uji BPOM. Kesimpulan yang pasti benar:', ['Semua produk ber-BPOM adalah serum', 'Semua serum wajib lolos uji BPOM', 'Sebagian produk wajah bukan serum', 'Serum tidak perlu uji BPOM'], 1],
        ['Jika hujan, jalanan basah. Hari ini jalanan tidak basah. Maka …', ['Hari ini hujan', 'Hari ini tidak hujan', 'Mungkin hujan', 'Jalanan kering karena panas'], 1],
        ['Dokter : Rumah sakit = Guru : …', ['Murid', 'Sekolah', 'Buku', 'Pelajaran'], 1],
        ['Panas : Dingin = Terang : …', ['Lampu', 'Gelap', 'Siang', 'Sinar'], 1],
        ['Rina lebih tinggi dari Budi. Budi lebih tinggi dari Citra. Siapa yang paling pendek?', ['Rina', 'Budi', 'Citra', 'Tidak bisa ditentukan'], 2],
        ['Paket A: 3 pcs Rp45.000. Paket B: 5 pcs Rp70.000. Mana yang lebih murah per pcs?', ['Paket A', 'Paket B', 'Sama saja', 'Tidak bisa dihitung'], 1],
        ['Pesanan masuk pukul 09.15 dan selesai dikemas 1 jam 50 menit kemudian. Pukul berapa selesai dikemas?', ['10.55', '11.05', '11.15', '11.50'], 1],
        ['Komisi 10% dari penjualan Rp2.500.000 adalah …', ['Rp25.000', 'Rp200.000', 'Rp250.000', 'Rp275.000'], 2],
        ['Lanjutkan deret huruf: A, C, E, G, …', ['H', 'I', 'J', 'K'], 1],
        ['Harga 4 botol serum Rp200.000. Berapa harga 7 botol?', ['Rp300.000', 'Rp325.000', 'Rp350.000', 'Rp400.000'], 2],
    ];
}
