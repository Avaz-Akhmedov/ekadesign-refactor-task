<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyAccount;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function create(Request $request)
    {
        return LoyaltyAccount::create($request->all());
    }

    public function activate($type, $id)
    {
        if (($type == 'phone' || $type == 'card' || $type == 'email') && $id != '') {
            if ($account = LoyaltyAccount::where($type, '=', $id)->first()) {
                if (!$account->active) {
                    $account->active = true;
                    $account->save();
                    $account->notify('Account restored');
                }
            } else {
                return response()->json(['message' => 'Account is not found'], 400);
            }
        } else {
            throw new \InvalidArgumentException('Wrong parameters');
        }

        return response()->json(['success' => true]);
    }

    public function deactivate($type, $id)
    {
        if (($type == 'phone' || $type == 'card' || $type == 'email') && $id != '') {
            if ($account = LoyaltyAccount::where($type, '=', $id)->first()) {
                if ($account->active) {
                    $account->active = false;
                    $account->save();
                    $account->notify('Account banned');
                }
            } else {
                return response()->json(['message' => 'Account is not found'], 400);
            }
        } else {
            throw new \InvalidArgumentException('Wrong parameters');
        }

        return response()->json(['success' => true]);
    }

    public function balance($type, $id)
    {

        $validTypes = ['phone', 'card', 'email'];

        if (!in_array($type, $validTypes) || empty($id)) {
            throw ValidationException::withMessages(['message' => 'Invalid account type or ID']);
        }

        $account = LoyaltyAccount::query()->where($type, $id)->first();

        if (!$account) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        return response()->json(['balance' => $account->getBalance()], 200);

    }
}
