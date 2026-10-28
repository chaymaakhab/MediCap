<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PatientController
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('q', '');
        
        $patients = [
            [
                'id' => 1,
                'file_number' => 'PT-4429',
                'first_name' => 'Eleanor',
                'last_name' => 'Pena',
                'cin' => 'BE-882910',
                'age' => 58,
                'gender' => 'female',
                'phone' => '+212 662 14 20 88',
                'coverage' => 'CNSS AMO (80%)',
                'last_visit' => '2026-10-24',
                'allergies' => 'Pénicilline'
            ],
            [
                'id' => 2,
                'file_number' => 'PT-9014',
                'first_name' => 'Wade',
                'last_name' => 'Warren',
                'cin' => 'BK-339182',
                'age' => 47,
                'gender' => 'male',
                'phone' => '+212 661 99 88 77',
                'coverage' => 'Wafa Assurance (85%)',
                'last_visit' => '2026-10-24',
                'allergies' => 'Aucune'
            ],
            [
                'id' => 3,
                'file_number' => 'PT-9132',
                'first_name' => 'Jacob',
                'last_name' => 'Jones',
                'cin' => 'BJ-129984',
                'age' => 34,
                'gender' => 'male',
                'phone' => '+212 663 44 22 11',
                'coverage' => 'AMO Indépendants (70%)',
                'last_visit' => '2026-10-24',
                'allergies' => 'Aspirine'
            ],
            [
                'id' => 4,
                'file_number' => 'PT-7945',
                'first_name' => 'Dianne',
                'last_name' => 'Russell',
                'cin' => 'BH-772183',
                'age' => 41,
                'gender' => 'female',
                'phone' => '+212 665 88 11 33',
                'coverage' => 'CNSS Salariés (80%)',
                'last_visit' => '2026-10-24',
                'allergies' => 'Sulfamides'
            ]
        ];

        return response()->json([
            'success' => true,
            'count' => count($patients),
            'patients' => $patients
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'patient' => [
                'id' => $id,
                'file_number' => 'PT-4429',
                'name' => 'Eleanor Pena',
                'cin' => 'BE-882910',
                'birth_date' => '1968-04-12',
                'gender' => 'female',
                'blood_group' => 'A+',
                'phone' => '+212 662 14 20 88',
                'email' => 'eleanor.pena@gmail.com',
                'address' => 'Quartier Racine, Casablanca',
                'family' => [
                    'family_code' => 'FAM-0142',
                    'role' => 'Tuteur / Mère',
                    'members_count' => 3
                ],
                'coverages' => [
                    [
                        'provider' => 'CNSS AMO',
                        'policy_number' => 'CNSS-884910283',
                        'rate' => '80%'
                    ]
                ],
                'medical_history' => [
                    'hypertension' => 'Stade 1 sous traitement',
                    'allergies' => 'Pénicilline (réaction urticaire)'
                ]
            ]
        ]);
    }
}
