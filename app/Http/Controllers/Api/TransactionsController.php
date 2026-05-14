<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\JsonDatabase;
use Illuminate\Http\Request;

class TransactionsController extends Controller
{
    /**
     * Get all transactions or a specific transaction
     */
    public function index(Request $request)
    {
        try {
            $db = new JsonDatabase();
            $id = $request->query('id');
            
            if ($id) {
                $transaction = $db->getById('transactions', $id);
                return response()->json($transaction ? $transaction : ['error' => 'Transaction not found'], $transaction ? 200 : 404);
            } else {
                $transactions = $db->getAll('transactions');
                return response()->json(is_array($transactions) ? $transactions : []);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Store a new transaction
     */
    public function store(Request $request)
    {
        try {
            $input = $request->getContent();
            $data = json_decode($input, true);
            
            if (!is_array($data)) {
                return response()->json(['error' => 'Invalid request data'], 400);
            }
            
            $db = new JsonDatabase();
            $result = $db->create('transactions', $data);
            
            if ($result) {
                return response()->json($result, 201);
            } else {
                return response()->json(['error' => 'Failed to create transaction'], 500);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update a transaction
     */
    public function update(Request $request, $id)
    {
        try {
            $input = $request->getContent();
            $data = json_decode($input, true);
            
            if (!is_array($data)) {
                return response()->json(['error' => 'Invalid request data'], 400);
            }
            
            $db = new JsonDatabase();
            $result = $db->update('transactions', $id, $data);
            
            if ($result) {
                return response()->json($result);
            } else {
                return response()->json(['error' => 'Failed to update transaction'], 500);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a transaction
     */
    public function destroy($id)
    {
        try {
            $db = new JsonDatabase();
            $result = $db->delete('transactions', $id);
            
            if ($result) {
                return response()->json(['message' => 'Transaction deleted successfully']);
            } else {
                return response()->json(['error' => 'Failed to delete transaction'], 500);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
