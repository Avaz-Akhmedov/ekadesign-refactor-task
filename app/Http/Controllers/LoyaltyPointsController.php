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

    public function withdraw()
    {
        $data = $_POST;

        Log::info('Withdraw loyalty points transaction input: ' . print_r($data, true));

        $type = $data['account_type'];
        $id = $data['account_id'];
        if (($type == 'phone' || $type == 'card' || $type == 'email') && $id != '') {
            if ($account = LoyaltyAccount::where($type, '=', $id)->first()) {
                if ($account->active) {
                    if ($data['points_amount'] <= 0) {
                        Log::info('Wrong loyalty points amount: ' . $data['points_amount']);
                        return response()->json(['message' => 'Wrong loyalty points amount'], 400);
                    }
                    if ($account->getBalance() < $data['points_amount']) {
                        Log::info('Insufficient funds: ' . $data['points_amount']);
                        return response()->json(['message' => 'Insufficient funds'], 400);
                    }

                    $transaction = LoyaltyPointsTransaction::withdrawLoyaltyPoints($account->id, $data['points_amount'], $data['description']);
                    Log::info($transaction);
                    return $transaction;
                } else {
                    Log::info('Account is not active: ' . $type . ' ' . $id);
                    return response()->json(['message' => 'Account is not active'], 400);
                }
            } else {
                Log::info('Account is not found:' . $type . ' ' . $id);
                return response()->json(['message' => 'Account is not found'], 400);
            }
        } else {
            Log::info('Wrong account parameters');
            throw new \InvalidArgumentException('Wrong account parameters');
        }
    }
}
