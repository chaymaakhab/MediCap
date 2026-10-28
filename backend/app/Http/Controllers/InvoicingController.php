<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\InvoicingCalculationService;

class InvoicingController
{
    /**
     * Liste des factures et caisse journalière (MAD)
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'summary' => [
                'total_payments_today' => 4850.00,
                'currency' => 'MAD',
                'cash' => 1200.00,
                'pos' => 2450.00,
                'cnss_direct' => 1200.00,
                'unsettled_invoices_count' => 1
            ],
            'invoices' => [
                [
                    'id' => 1,
                    'invoice_number' => 'INV-2026-0081',
                    'patient_name' => 'Eleanor Pena',
                    'total_amount' => 450.00,
                    'paid_amount' => 450.00,
                    'balance_due' => 0.00,
                    'status' => 'paid',
                    'payment_method' => 'TPE (Carte)'
                ],
                [
                    'id' => 2,
                    'invoice_number' => 'INV-2026-0082',
                    'patient_name' => 'Wade Warren',
                    'total_amount' => 800.00,
                    'paid_amount' => 500.00,
                    'balance_due' => 300.00,
                    'status' => 'partially_paid',
                    'notes' => 'Paiement échelonné : Acompte 1 (300 DH) + Acompte 2 (200 DH)'
                ],
                [
                    'id' => 3,
                    'invoice_number' => 'INV-2026-0083',
                    'patient_name' => 'Dianne Russell',
                    'total_amount' => 1600.00,
                    'paid_amount' => 1600.00,
                    'balance_due' => 0.00,
                    'status' => 'paid',
                    'payment_method' => 'TPE + Virement'
                ]
            ]
        ]);
    }

    /**
     * Enregistrer un paiement partiel ou total
     */
    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $amount = (float) $request->input('amount', 300.00);
        $method = $request->input('method', 'cash');

        // Exemple concret : Facture de 800 DH
        $totalAmount = 800.00;
        $existingPayments = [
            ['amount' => 300.00],
            ['amount' => 200.00]
        ];

        // Ajouter le nouveau paiement
        $existingPayments[] = ['amount' => $amount];

        $recalc = InvoicingCalculationService::recalculateInvoiceBalance($totalAmount, $existingPayments);

        return response()->json([
            'success' => true,
            'message' => 'Paiement enregistré avec succès.',
            'receipt_number' => 'REC-2026-' . rand(1000, 9999),
            'currency' => 'MAD',
            'financial_status' => $recalc
        ]);
    }

    public function getCurrentCashSession(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'session' => [
                'session_id' => 14,
                'status' => 'open',
                'opened_at' => date('Y-m-d 08:00:00'),
                'opened_by' => 'Nadia Bennani (Caisse Principale)',
                'opening_balance' => 1500.00,
                'current_cash_in_drawer' => 2700.00,
                'currency' => 'MAD'
            ]
        ]);
    }
}
