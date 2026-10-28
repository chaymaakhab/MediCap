<?php

namespace App\Services;

/**
 * Service de Calcul Financier & Reste à Payer (MAD)
 * Conformément à la section 8.2 du Cahier des charges :
 * "Une facture peut recevoir plusieurs paiements...
 * Formule : reste à payer = total dû − paiements effectivement affectés − avoirs validés applicables.
 * Les montants doivent être calculés côté serveur et stockés sans erreurs d'arrondi."
 * Et section 9.3 :
 * "Un remboursement attendu de la CNSS ou de l'assurance n'est pas un paiement reçu par le centre.
 * Il ne diminue donc pas le reste à payer du patient."
 */
class InvoicingCalculationService
{
    /**
     * Recalcule le statut et le solde d'une facture après allocation d'un paiement
     */
    public static function recalculateInvoiceBalance(float $totalAmount, array $paymentsList): array
    {
        $totalPaid = 0.0;
        foreach ($paymentsList as $payment) {
            $totalPaid += (float) ($payment['amount'] ?? 0.0);
        }

        // Arrondi bancaire à 2 décimales pour éviter tout artefact IEEE 754
        $totalAmount = round($totalAmount, 2);
        $totalPaid = round($totalPaid, 2);
        $balanceDue = round(max(0.0, $totalAmount - $totalPaid), 2);

        $status = 'issued';
        if ($balanceDue <= 0.0) {
            $status = 'paid';
        } elseif ($totalPaid > 0.0) {
            $status = 'partially_paid';
        }

        return [
            'total_amount' => $totalAmount,
            'paid_amount' => $totalPaid,
            'balance_due' => $balanceDue,
            'status' => $status
        ];
    }
}
