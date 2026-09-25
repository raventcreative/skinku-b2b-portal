@extends('legal._layout')
@section('title', 'Kebijakan Privasi / Privacy Policy')

@section('body')
<p>Kebijakan ini menjelaskan data apa yang dikumpulkan dan diproses oleh <b>SKINKU B2B Portal</b>
(system.skinku.id), sistem internal SKINKU untuk distribusi, operasional, dan pengelolaan konten media sosial resmi SKINKU.</p>

<h2>1. Data yang kami kumpulkan</h2>
<ul>
    <li><b>Akun pengguna portal</b>: nama, username, email, peran, dan data yang diisi pengguna saat bekerja di portal.</li>
    <li><b>Konten</b>: foto, video, dan caption yang diunggah content creator untuk dipublikasikan di akun resmi SKINKU.</li>
    <li><b>Akun media sosial brand</b> (Facebook Page, Instagram, Threads, TikTok) yang dihubungkan administrator:
        ID akun, nama tampilan/username, serta token akses dan refresh token dari platform.</li>
    <li><b>Log aktivitas</b>: jejak audit aksi penting (siapa melakukan apa dan kapan) serta alamat IP.</li>
</ul>

<h2>2. Cara kami menggunakan data</h2>
<ul>
    <li>Token platform <b>hanya</b> dipakai untuk mempublikasikan konten yang sudah disetujui ke akun resmi SKINKU,
        membaca info akun yang dibutuhkan untuk posting (nama tampilan, opsi privasi), dan mengecek status publikasi.</li>
    <li>Kami tidak membaca pesan pribadi, follower, atau data audiens, dan tidak memakai data platform untuk iklan atau profiling.</li>
    <li>Data tidak dijual dan tidak dibagikan ke pihak ketiga, kecuali kepada platform tujuan saat konten dipublikasikan.</li>
</ul>

<h2>3. Penyimpanan &amp; keamanan</h2>
<ul>
    <li>Token akses disimpan <b>terenkripsi</b> di database dan tidak pernah ditampilkan di antarmuka.</li>
    <li>Akses portal dibatasi dengan login dan hak akses per peran.</li>
    <li>File media disimpan di server portal selama dibutuhkan untuk publikasi dan arsip internal.</li>
</ul>

<h2>4. Mencabut akses</h2>
<p>Administrator dapat memutus akun media sosial kapan saja lewat menu <i>Akun Sosial Media</i>; token langsung dihapus dari database.
Akses juga bisa dicabut dari pengaturan aplikasi terhubung di masing-masing platform (mis. TikTok → Settings → Security → Manage app permissions).
Permintaan penghapusan data dapat dikirim ke email kontak di bawah.</p>

<h2>English summary</h2>
<p>SKINKU B2B Portal is an internal tool used by SKINKU to manage distribution and publish approved content to SKINKU's own
social media accounts. When an administrator connects a TikTok, Facebook, Instagram, or Threads account, we store the account ID,
display name/username and OAuth tokens (encrypted at rest). Tokens are used solely to publish approved content, read the posting
options required to publish (e.g., privacy levels), and check publishing status. We do not sell or share this data, do not access
private messages or audience data, and delete tokens immediately when the account is disconnected. Contact us to request data deletion.</p>
@endsection
