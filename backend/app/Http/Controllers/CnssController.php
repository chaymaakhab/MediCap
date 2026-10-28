<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CnssController
{
    /**
     * Suivi des dossiers de remboursement CNSS / AMO
     * Conforme à la section 9 du Cahier des charges
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'summary' => [
                'unsettled_claims_total' => 1180.00,
                'currency' => 'MAD',
                'pending_dossiers_count' => 5,
                'validation_tier' => 'Pending CNSS tier-3 validation'
            ],
            'cases' => [
                [
                    'id' => 1,
                    'patient_name' => 'Eleanor Pena',
                    'provider' => 'CNSS (AMO)',
                    'external_reference' => 'BORD-CNSS-0941',
                    'claimed_amount' => 360.00,
                    'estimated_reimbursement' => 288.00,
                    'status' => 'submitted',
                    'status_label' => 'Transmis à la CNSS',
                    'missing_docs' => []
                ],
                [
                    'id' => 2,
                    'patient_name' => 'Wade Warren',
                    'provider' => 'Wafa Assurance',
                    'external_reference' => 'BORD-WAFA-1102',
                    'claimed_amount' => 640.00,
                    'estimated_reimbursement' => 544.00,
                    'status' => 'in_review',
                    'status_label' => 'En Cours d’Étude',
                    'missing_docs' => []
                ],
                [
                    'id' => 3,
                    'patient_name' => 'Dianne Russell',
                    'provider' => 'CNSS AMO',
                    'external_reference' => 'BORD-CNSS-0942',
                    'claimed_amount' => 1280.00,
                    'estimated_reimbursement' => 1024.00,
                    'status' => 'documents_missing',
                    'status_label' => 'Pièces Justificatives Manquantes',
                    'missing_docs' => ['Vignette code-barre médicament', 'Rapport d’échocardiographie signé']
                ]
            ]
        ]);
    }
}
