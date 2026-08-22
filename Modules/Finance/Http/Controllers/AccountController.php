<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Finance\Http\Requests\AccountStoreRequest;
use Modules\Finance\Http\Requests\AccountUpdateRequest;
use Modules\Finance\Http\Resources\AccountResource;
use Modules\Finance\Models\Account;

class AccountController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Account::query()
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('account_type'), fn ($query) => $query->where('account_type', $request->string('account_type')))
            ->when($request->filled('is_postable'), fn ($query) => $query->where('is_postable', $request->boolean('is_postable')))
            /*
             * Saringan keluarga kode, untuk pemilih yang gerbangnya lebih sempit
             * daripada bagan akun. Laci kas kecil WAJIB berakun 1-11xx dan
             * PettyCashFundService menolak selainnya — tanpa ini comboboxnya
             * menawarkan 1-1400 Persediaan, operator memilihnya, lalu baru
             * mendengar penolakan setelah menekan Simpan. Pemilih tidak boleh
             * menawarkan apa yang penjaganya pasti tolak.
             */
            ->when($request->filled('code_prefix'), fn ($query) => $query->where('code', 'like', $request->string('code_prefix').'%'))
            ->orderBy('code');

        // Whitelist sengaja kosong: urutan kode COA adalah hierarkinya —
        // layar mengindentasi nama berdasarkan kode (indentBy), jadi sort lain
        // akan mencerabut anak akun dari induknya. Adopsi tetap dilakukan agar
        // kontrak meta seragam dan ?sort ditolak dengan jelas, bukan diabaikan.
        return $this->listing($request, $query, AccountResource::class, perPageDefault: 100);
    }

    public function store(AccountStoreRequest $request): JsonResponse
    {
        $account = Account::query()->create($request->validated());

        return $this->created(AccountResource::make($account));
    }

    public function show(Account $account): JsonResponse
    {
        return $this->ok(AccountResource::make($account->load('children')));
    }

    public function update(AccountUpdateRequest $request, Account $account): JsonResponse
    {
        $account->update($request->validated());

        return $this->ok(AccountResource::make($account));
    }

    public function destroy(Account $account): JsonResponse
    {
        if ($account->journalLines()->exists()) {
            return $this->error("Account {$account->code} has journal lines and cannot be deleted.");
        }

        if ($account->children()->exists()) {
            return $this->error("Account {$account->code} has child accounts and cannot be deleted.");
        }

        $account->delete();

        return $this->ok(null, 'Account deleted.');
    }
}
