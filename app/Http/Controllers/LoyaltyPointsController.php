<?php

namespace App\Http\Controllers;

use App\Mail\LoyaltyPointsReceived;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointsTransaction;
use http\Env\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LoyaltyPointsController extends Controller
{
    public function deposit(Request $request)
    {
        $validated = $request->validate([
            'account_type' => 'required|in:phone,card,email',
            'account_id' => 'required|string',
            'loyalty_points_rule' => 'required|integer',
            'description' => 'required|string|max:255',
            'payment_id' => 'required|string|max:255',
            'payment_amount' => 'required|numeric|min:0',
            'payment_time' => 'required|date',
        ]);


        Log::info('Deposit transaction input: ', $validated);

        $account = LoyaltyAccount::query()->where($validated['account_type'], $validated['account_id'])->firstOrFail();


        if (!$account->active) {
            return response()->json(['message' => 'Account is not active'], 400);
        }

        $transaction = LoyaltyPointsTransaction::performPaymentLoyaltyPoints(
            $account->id,
            $validated['loyalty_points_rule'],
            $validated['description'],
            $validated['payment_id'],
            $validated['payment_amount'],
            $validated['payment_time']
        );

        Log::info('Transaction completed: ', ['transaction' => $transaction]);


        if ($account->email && $account->email_notification) {
            Mail::to($account->email)->send(new LoyaltyPointsReceived($transaction->points_amount, $account->getBalance()));
        }
        if ($account->phone && $account->phone_notification) {
            Log::info('SMS sent: You received ' . $transaction->points_amount . '. Your balance is ' . $account->getBalance());
        }

        return response()->json($transaction);
    }

    public function cancel(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => 'required|integer|exists:loyalty_points_transactions,id',
            'cancellation_reason' => 'required|string|max:255',
        ]);

        $transaction = LoyaltyPointsTransaction::where('id', $validated['transaction_id'])
            ->where('canceled', 0)
            ->first();

        if (!$transaction) {
            Log::warning('Transaction not found or already canceled', ['transaction_id' => $validated['transaction_id']]);
            return response()->json(['message' => 'Transaction is not found or already canceled'], 400);
        }

        $transaction->update([
            'canceled' => now(),
            'cancellation_reason' => $validated['cancellation_reason'],
        ]);

        Log::info('Transaction canceled successfully', ['transaction_id' => $transaction->id]);

        return response()->json(['message' => 'Transaction canceled successfully']);
    }

    public function withdraw(Request $request)
    {
        $validated = $request->validate([
            'account_type'   => 'required|in:phone,card,email',
            'account_id'     => 'required|string',
            'points_amount'  => 'required|numeric|min:1',
            'description'    => 'nullable|string|max:255',
        ]);

        Log::info('Withdraw loyalty points transaction input: ', $validated);


        $account = LoyaltyAccount::query()->where($validated['account_type'], $validated['account_id'])->first();

        if (!$account) {
            Log::warning('Account not found', ['account_type' => $validated['account_type'], 'account_id' => $validated['account_id']]);
            return response()->json(['message' => 'Account not found'], 400);
        }


        if (!$account->active) {
            Log::warning('Inactive account', ['account_id' => $account->id]);
            return response()->json(['message' => 'Account is not active'], 400);
        }

        if ($validated['points_amount'] > $account->getBalance()) {
            Log::warning('Insufficient funds', [
                'account_id'     => $account->id,
                'requested'      => $validated['points_amount'],
                'available'      => $account->getBalance(),
            ]);
            return response()->json(['message' => 'Insufficient funds'], 400);
        }

        $transaction = LoyaltyPointsTransaction::withdrawLoyaltyPoints(
            $account->id,
            $validated['points_amount'],
            $validated['description']
        );

        Log::info('Loyalty points withdrawn', ['transaction' => $transaction]);

        return response()->json($transaction);
    }
}
