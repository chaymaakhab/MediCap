import 'dart:convert';
import 'package:http/http.dart' as http;
import '../models/appointment.dart';

class ApiService {
  // Use 10.0.2.2 for Android emulator to reach localhost, or configure via environment
  static const String baseUrl = 'http://10.0.2.2:8000/api/v1';

  static Future<Map<String, dynamic>> fetchDashboardOverview() async {
    try {
      final response = await http.get(
        Uri.parse('$baseUrl/dashboard/overview'),
        headers: {'X-Tenant-ID': '1', 'Accept': 'application/json'},
      ).timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        return json.decode(response.body);
      }
    } catch (_) {
      // Fallback to local offline cache / mock matching screenshots
    }

    return _getFallbackDashboardData();
  }

  static Map<String, dynamic> _getFallbackDashboardData() {
    return {
      'centre': {
        'name': 'Grand Central Polyclinic',
        'branch': 'Primary Care Branch',
        'date': 'Friday, Oct 24, 2026',
      },
      'kpis': {
        'appointments_today': {'count': 38, 'trend': '+8% vs yday'},
        'patients_checked_in': {'count': 29, 'total': 38},
        'payments_collected': {'amount': 4850.00, 'currency': 'MAD'},
        'unsettled_claims': {'amount': 1180.00, 'currency': 'MAD', 'dossiers_count': 5},
      },
      'today_schedule': [
        {
          'id': 1,
          'time': '09:00 AM',
          'patient_name': 'Eleanor Pena',
          'patient_id': '#4429',
          'type': 'Routine Cardiology Checkup',
          'doctor': 'Dr. Anil Patel',
          'room': 'Room 102',
          'status': 'completed',
          'status_label': 'Completed',
          'avatar': 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=120'
        },
        {
          'id': 2,
          'time': '09:45 AM',
          'patient_name': 'Wade Warren',
          'patient_id': '#PT-9014',
          'tag': 'ECG Active',
          'type': 'ECG Diagnostics & Rhythm Review',
          'duration': '45 min',
          'room': 'Room 204',
          'status': 'in_progress',
          'status_label': 'In Progress',
          'avatar': 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=120'
        },
        {
          'id': 3,
          'time': '10:30 AM',
          'patient_name': 'Jacob Jones',
          'patient_id': '#PT-9132',
          'tag': 'First Visit',
          'type': 'Initial Internal Consultation',
          'doctor': 'Dr. Sarah Chen',
          'room': 'Room 101',
          'status': 'pending',
          'status_label': 'Pending',
          'avatar': 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=120'
        },
        {
          'id': 4,
          'time': '11:15 AM',
          'patient_name': 'Dianne Russell',
          'patient_id': '#PT-7945',
          'tag': 'CNSS 80%',
          'type': 'Post-Surgery Follow-up',
          'doctor': 'Dr. Michael Chen',
          'room': 'Room 104',
          'status': 'confirmed',
          'status_label': 'Confirmed',
          'avatar': 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=120'
        }
      ]
    };
  }
}
