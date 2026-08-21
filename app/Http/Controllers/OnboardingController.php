<?php

namespace App\Http\Controllers;

use App\Models\JoinPackage;
use App\Models\User;
use App\Services\AuditService;
use App\Services\OnboardingService;
use App\Services\PartnerHierarchyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;
use RuntimeException;

class OnboardingController extends Controller
{
    public function __construct(private OnboardingService $onboarding, private PartnerHierarchyService $hierarchy) {}

    public function create()
    {
        return view('onboarding.create', [
            'packages' => JoinPackage::where('is_active', true)->orderBy('name')->get(),
            'hierarchy' => $this->hierarchy,
            // Calon perekrut (sponsor): mitra aktif mana pun (Sponsor/GD/Distributor/Reseller).
            'sponsors' => User::where('status', User::STATUS_ACTIVE)
                ->whereIn('role', User::PARTNER_ROLES)->orderBy('fullname')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'fullname' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'username' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'company_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'region' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'join_package_id' => ['required', 'integer', 'exists:join_packages,id'],
            'upline_id' => ['nullable', 'integer', 'exists:users,id'],
            'sponsor_id' => ['nullable', 'integer', 'exists:users,id'], // perekrut (jalur rekrutmen)
            'paid' => ['accepted'], // admin konfirmasi sudah bayar
        ]);

        $paket = JoinPackage::where('is_active', true)->findOrFail($data['join_package_id']);

        try {
            $reseller = $this->onboarding->onboard($data, $paket, $data['upline_id'] ?? null, $request->user()->id, $data['sponsor_id'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        AuditService::log(action: 'onboard_reseller', targetType: 'user', targetId: $reseller->id,
            after: ['paket' => $paket->name, 'upline_id' => $data['upline_id'] ?? null, 'sponsor_id' => $data['sponsor_id'] ?? null]);

        return redirect()->route('users.index')->with('status', "Reseller {$reseller->fullname} berhasil didaftarkan via paket {$paket->name}.");
    }
}
