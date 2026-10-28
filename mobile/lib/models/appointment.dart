class AppointmentItem {
  final int id;
  final String time;
  final String patientName;
  final String patientId;
  final String? tag;
  final String reason;
  final String doctorName;
  final String room;
  final String status;
  final String statusLabel;
  final String? avatarUrl;
  final bool isBreak;
  final String? breakTitle;

  AppointmentItem({
    required this.id,
    required this.time,
    this.patientName = '',
    this.patientId = '',
    this.tag,
    this.reason = '',
    this.doctorName = '',
    this.room = '',
    this.status = '',
    this.statusLabel = '',
    this.avatarUrl,
    this.isBreak = false,
    this.breakTitle,
  });

  factory AppointmentItem.fromJson(Map<String, dynamic> json) {
    return AppointmentItem(
      id: json['id'] ?? 0,
      time: json['time'] ?? '',
      patientName: json['patient_name'] ?? '',
      patientId: json['patient_id'] ?? '',
      tag: json['tag'],
      reason: json['type'] ?? '',
      doctorName: json['doctor'] ?? '',
      room: json['room'] ?? '',
      status: json['status'] ?? '',
      statusLabel: json['status_label'] ?? '',
      avatarUrl: json['avatar'],
      isBreak: json['is_break'] ?? false,
      breakTitle: json['title'],
    );
  }
}

class DashboardKPIs {
  final int appointmentsToday;
  final String appointmentsTrend;
  final int patientsCheckedIn;
  final int patientsTotal;
  final double paymentsCollected;
  final String currency;
  final double unsettledClaims;
  final int dossiersCount;

  DashboardKPIs({
    required this.appointmentsToday,
    required this.appointmentsTrend,
    required this.patientsCheckedIn,
    required this.patientsTotal,
    required this.paymentsCollected,
    required this.currency,
    required this.unsettledClaims,
    required this.dossiersCount,
  });

  factory DashboardKPIs.fromJson(Map<String, dynamic> json) {
    final kpis = json['kpis'] ?? {};
    return DashboardKPIs(
      appointmentsToday: kpis['appointments_today']?['count'] ?? 38,
      appointmentsTrend: kpis['appointments_today']?['trend'] ?? '+8% vs yday',
      patientsCheckedIn: kpis['patients_checked_in']?['count'] ?? 29,
      patientsTotal: kpis['patients_checked_in']?['total'] ?? 38,
      paymentsCollected: (kpis['payments_collected']?['amount'] as num?)?.toDouble() ?? 4850.0,
      currency: kpis['payments_collected']?['currency'] ?? 'MAD',
      unsettledClaims: (kpis['unsettled_claims']?['amount'] as num?)?.toDouble() ?? 1180.0,
      dossiersCount: kpis['unsettled_claims']?['dossiers_count'] ?? 5,
    );
  }
}
