<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DashboardController
{
    /**
     * Get Complete Overview Dashboard Data (Matching Screenshot 2)
     */
    public function getOverview(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'centre' => [
                'name' => 'Grand Central Polyclinic',
                'branch' => 'Primary Care Branch',
                'date' => 'Friday, Oct 24, 2026',
                'status' => 'Live Overview',
                'compliance' => 'HIPAA / GDPR / CNDP Certified'
            ],
            'user' => [
                'name' => 'Dr. Sarah Chen',
                'role' => 'Medical Director',
                'duty_status' => 'On Duty',
                'avatar' => 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=120'
            ],
            'kpis' => [
                'appointments_today' => [
                    'count' => 38,
                    'trend' => '+8% vs yday',
                    'active' => 32,
                    'queued' => 6
                ],
                'patients_checked_in' => [
                    'count' => 29,
                    'total' => 38,
                    'avg_duration_minutes' => 22
                ],
                'payments_collected' => [
                    'amount' => 4850.00,
                    'currency' => 'MAD',
                    'breakdown' => [
                        'cash' => 1200.00,
                        'pos' => 2450.00,
                        'cnss' => 1200.00
                    ]
                ],
                'unsettled_claims' => [
                    'amount' => 1180.00,
                    'currency' => 'MAD',
                    'dossiers_count' => 5,
                    'alert_text' => 'Pending CNSS tier-3 validation'
                ]
            ],
            'today_schedule' => [
                [
                    'id' => 1,
                    'time' => '09:00 AM',
                    'patient_name' => 'Eleanor Pena',
                    'patient_id' => '#4429',
                    'type' => 'Routine Cardiology Checkup',
                    'doctor' => 'Dr. Anil Patel',
                    'room' => 'Room 102',
                    'status' => 'completed',
                    'status_label' => 'Completed',
                    'avatar' => 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=120'
                ],
                [
                    'id' => 2,
                    'time' => '09:45 AM',
                    'patient_name' => 'Wade Warren',
                    'patient_id' => '#PT-9014',
                    'tag' => 'ECG Active',
                    'type' => 'ECG Diagnostics & Rhythm Review',
                    'duration' => '45 min',
                    'room' => 'Room 204',
                    'status' => 'in_progress',
                    'status_label' => 'In Progress',
                    'avatar' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=120'
                ],
                [
                    'id' => 0,
                    'time' => '10:15 AM',
                    'is_break' => true,
                    'title' => 'Tea Break & Staff Sync (15 min)',
                    'subtitle' => 'Sanitization Interval'
                ],
                [
                    'id' => 3,
                    'time' => '10:30 AM',
                    'patient_name' => 'Jacob Jones',
                    'patient_id' => '#PT-9132',
                    'tag' => 'First Visit',
                    'type' => 'Initial Internal Consultation',
                    'doctor' => 'Dr. Sarah Chen',
                    'room' => 'Room 101',
                    'status' => 'pending',
                    'status_label' => 'Pending',
                    'avatar' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=120'
                ],
                [
                    'id' => 4,
                    'time' => '11:15 AM',
                    'patient_name' => 'Dianne Russell',
                    'patient_id' => '#PT-7945',
                    'tag' => 'CNSS 80%',
                    'type' => 'Post-Surgery Follow-up',
                    'doctor' => 'Dr. Michael Chen',
                    'room' => 'Room 104',
                    'status' => 'confirmed',
                    'status_label' => 'Confirmed',
                    'avatar' => 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=120'
                ]
            ],
            'staff_on_duty' => [
                [
                    'name' => 'Dr. Anil Patel',
                    'specialty' => 'Cardiologist',
                    'room' => 'Room 102',
                    'status' => 'In Consult',
                    'queue' => '3 queue',
                    'avatar' => 'https://images.unsplash.com/photo-1622253692010-333f2da6031d?w=120'
                ],
                [
                    'name' => 'Dr. Sarah Chen',
                    'is_you' => true,
                    'specialty' => 'Medical Director',
                    'room' => 'Room 101',
                    'status' => 'Available',
                    'queue' => '1 queue',
                    'avatar' => 'https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=120'
                ],
                [
                    'name' => 'Dr. Michael Chen',
                    'specialty' => 'Orthopedics',
                    'room' => 'OR Suite 3',
                    'status' => 'In Surgery',
                    'until' => 'Until 11:30',
                    'avatar' => 'https://images.unsplash.com/photo-1537368910025-700350fe46c7?w=120'
                ],
                [
                    'name' => 'Dr. Amina Ndiaye',
                    'specialty' => 'Pediatrics',
                    'room' => 'Room 108',
                    'status' => 'On Duty',
                    'queue' => '4 queue',
                    'avatar' => 'https://images.unsplash.com/photo-1594824813637-440263f9059f?w=120'
                ]
            ],
            'triage_alerts' => [
                [
                    'id' => 1,
                    'severity' => 'STAT',
                    'title' => 'Abnormal Potassium Lab',
                    'patient' => 'Eleanor Pena (Room 102)',
                    'detail' => 'Serum K+ 5.8 mEq/L flagged by Central Pathology.',
                    'action_primary' => 'Review Findings',
                    'action_secondary' => 'Page Dr. Patel'
                ]
            ]
        ]);
    }
}
