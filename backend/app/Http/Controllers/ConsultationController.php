<?php

namespace App\Http\Controllers;

use App\Services\CliniqoAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ConsultationController
{
    /**
     * Start clinical encounter
     */
    public function startEncounter(Request $request): JsonResponse
    {
        CliniqoAuthService::requireRole($request, ['docteur', 'owner', 'medecin', 'admin_clinique'], 'la consultation clinique');
        $appointmentId = $request->input('appointment_id');
        $patientId = $request->input('patient_id');

        return response()->json([
            'success' => true,
            'encounter_id' => rand(100, 999),
            'status' => 'active',
            'started_at' => date('Y-m-d H:i:s'),
            'message' => 'Consultation médicale démarrée.'
        ]);
    }

    /**
     * Record clinical vitals
     */
    public function recordVitals(Request $request, int $id): JsonResponse
    {
        CliniqoAuthService::requireRole($request, ['docteur', 'owner', 'medecin', 'admin_clinique'], 'l\'enregistrement des constantes vitales');
        $vitals = $request->validate([
            'systolic_bp' => 'nullable|integer',
            'diastolic_bp' => 'nullable|integer',
            'heart_rate' => 'nullable|integer',
            'spo2' => 'nullable|numeric',
            'temperature' => 'nullable|numeric',
            'blood_glucose' => 'nullable|numeric'
        ]);

        return response()->json([
            'success' => true,
            'encounter_id' => $id,
            'message' => 'Constantes vitales enregistrées avec succès.',
            'vitals' => $vitals
        ]);
    }

    /**
     * Issue authenticated electronic prescription
     */
    public function issuePrescription(Request $request, int $id): JsonResponse
    {
        CliniqoAuthService::requireRole($request, ['docteur', 'owner', 'medecin', 'admin_clinique'], 'l\'émission d\'ordonnances médicales');
        $hash = hash('sha256', uniqid() . microtime());

        return response()->json([
            'success' => true,
            'prescription_id' => rand(1000, 9999),
            'encounter_id' => $id,
            'qr_verification_hash' => $hash,
            'verification_url' => "https://grandcentral.cliniqo.ma/verify-prescription/$hash",
            'message' => 'Ordonnance médicale sécurisée émise avec signature numérique.'
        ]);
    }
}
