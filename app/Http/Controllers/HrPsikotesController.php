<?php

namespace App\Http\Controllers;

use App\Models\PsychotestSession;
use Illuminate\View\View;

/** Menu HR → Psikotes (izin hr.recruit + internal): daftar sesi psikotes & pratinjau bank soal. */
class HrPsikotesController extends Controller
{
    public function index(): View
    {
        return view('hr.psikotes.index', [
            'sesi' => PsychotestSession::with('candidate.opening')->latest('id')->limit(200)->get(),
        ]);
    }

    public function soal(): View
    {
        return view('hr.psikotes.soal');
    }
}
