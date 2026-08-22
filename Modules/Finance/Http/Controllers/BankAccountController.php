<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\ApiController;
use Modules\Finance\Http\Requests\BankAccountStoreRequest;
use Modules\Finance\Http\Requests\BankAccountUpdateRequest;
use Modules\Finance\Http\Resources\BankAccountResource;
use Modules\Finance\Models\BankAccount;

class BankAccountController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = BankAccount::query()
            ->with('coaAccount')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $q = $request->string('q');
                $query->where(function ($where) use ($q): void {
                    $where->where('code', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('bank_name', 'like', "%{$q}%")
                        ->orWhere('account_no', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('code');

        return $this->listing($request, $query, BankAccountResource::class,
            sortable: ['code', 'name', 'bank_name']);
    }

    public function store(BankAccountStoreRequest $request): JsonResponse
    {
        $bankAccount = BankAccount::query()->create($request->validated());

        return $this->created(BankAccountResource::make($bankAccount->load('coaAccount')));
    }

    public function show(BankAccount $bankAccount): JsonResponse
    {
        return $this->ok(BankAccountResource::make($bankAccount->load('coaAccount')));
    }

    public function update(BankAccountUpdateRequest $request, BankAccount $bankAccount): JsonResponse
    {
        $bankAccount->update($request->validated());

        return $this->ok(BankAccountResource::make($bankAccount->load('coaAccount')));
    }

    public function destroy(BankAccount $bankAccount): JsonResponse
    {
        if ($bankAccount->payments()->exists()) {
            return $this->error("Bank account {$bankAccount->code} has payments and cannot be deleted.");
        }

        $bankAccount->delete();

        return $this->ok(null, 'Bank account deleted.');
    }
}
