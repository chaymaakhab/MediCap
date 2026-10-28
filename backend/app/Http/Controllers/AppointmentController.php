<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\AppointmentConflictChecker;

class AppointmentController
{
    /**
     * Liste des rendez-vous avec filtres
     */
    public function index(Request $request): JsonResponse
    {
        $practitionerId = $request->query('practitioner_id', 2); // Default Dr. Patel
        $date = $request->query('date', date('Y-m-d'));

        // Mocked dataset matching Screenshot 3: Schedule Matrix
        $slots = [
            [
                'time' => '08:30 AM',
                'type' => 'internal_activity',
                'title' => 'Morning Clinical Briefing & Patient Records Audit',
                'subtitle' => 'Cardiology Departmental Rounds with Resident Staff',
                'duration' => '30m',
                'badge' => 'Internal'
            ],
            [
                'id' => 1,
                'time' => '09:00 AM',
                'patient_name' => 'Eleanor Pena',
                'patient_id' => 'PT-8841',
                'type' => 'Routine Cardiovascular Checkup',
                'duration' => '30 min',
                'room' => 'Room 102',
                'status' => 'completed',
                'avatar' => 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=120'
            ],
            [
                'id' => 2,
                'time' => '09:30 AM',
                'patient_name' => 'Wade Warren',
                'patient_id' => 'PT-9014',
                'priority' => 'HIGH PRIORITY',
                'type' => 'ECG & Stress Echo Review',
                'duration' => '45 min',
                'room' => 'ECG Lab B',
                'status' => 'in_progress',
                'progress_elapsed' => '28 min elapsed',
                'avatar' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=120'
            ],
            [
                'time' => '10:15 AM',
                'type' => 'sanitization_break',
                'title' => 'Hygiene Disinfection & Tea Break (15 min)',
                'subtitle' => 'Lab Sanitization'
            ],
            [
                'id' => 3,
                'time' => '10:30 AM',
                'patient_name' => 'Jacob Jones',
                'patient_id' => 'PT-9132',
                'type' => 'General Cardiac Follow-up',
                'duration' => '30 min',
                'room' => 'Room 102',
                'status' => 'waiting_room',
                'status_label' => 'Arrived • In Waiting Room',
                'avatar' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=120'
            ],
            [
                'time' => '11:00 AM',
                'type' => 'open_slot',
                'title' => 'Open Slot Available (30 min)',
                'subtitle' => 'Click to assign walk-in or emergency'
            ],
            [
                'id' => 4,
                'time' => '11:30 AM',
                'patient_name' => 'Dianne Russell',
                'patient_id' => 'PT-7945',
                'type' => 'Post-Surgery Valvular Follow-up',
                'duration' => '45 min',
                'room' => 'Room 102',
                'status' => 'confirmed',
                'status_label' => 'Confirmed • 11:30',
                'avatar' => 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=120'
            ],
            [
                'time' => '12:15 PM',
                'type' => 'inpatient_rounds',
                'title' => 'Lunch Break & Inpatient Ward Rounds (45 min)',
                'subtitle' => 'Inpatient Cardiology'
            ]
        ];

        return response()->json([
            'success' => true,
            'date' => $date,
            'practitioner' => [
                'id' => 2,
                'name' => 'Dr. Anil Patel',
                'role' => 'Chief Cardiologist • Room 102',
                'duty_status' => 'ON DUTY • CONSULT ACTIVE',
                'patients_today' => 8,
                'available_time' => '3h 45m',
                'avatar' => 'https://images.unsplash.com/photo-1622253692010-333f2da6031d?w=120'
            ],
            'slots' => $slots
        ]);
    }

    /**
     * Création d'un rendez-vous avec vérification d'anti-chevauchement
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('active_tenant_id', 1);
        $data = $request->validate([
            'practitioner_id' => 'required|integer',
            'patient_id' => 'required|integer',
            'appointment_date' => 'required|date',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'channel' => 'nullable|string',
            'reason' => 'nullable|string'
        ]);

        // Vérification de disponibilité côté serveur
        $isAvailable = AppointmentConflictChecker::checkSlotAvailability(
            $tenantId,
            (int) $data['practitioner_id'],
            $data['appointment_date'],
            $data['start_time'],
            $data['end_time']
        );

        if (!$isAvailable) {
            return response()->json([
                'success' => false,
                'error' => 'SLOT_CONFLICT',
                'message' => 'Ce créneau est déjà réservé pour ce praticien. Veuillez choisir un autre créneau.'
            ], 409);
        }

        $refCode = 'APT-' . date('Y') . '-' . strtoupper(substr(uniqid(), -5)) . '-MA';

        return response()->json([
            'success' => true,
            'message' => 'Rendez-vous créé avec succès.',
            'reference_code' => $refCode,
            'appointment' => array_merge($data, [
                'id' => rand(100, 999),
                'reference_code' => $refCode,
                'status' => 'confirmed'
            ])
        ], 201);
    }

    /**
     * Mise à jour du statut (arrived, in_consultation, completed, cancelled)
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $newStatus = $request->input('status');
        return response()->json([
            'success' => true,
            'message' => "Statut du rendez-vous #$id mis à jour vers: $newStatus",
            'appointment_id' => $id,
            'status' => $newStatus
        ]);
    }
}
