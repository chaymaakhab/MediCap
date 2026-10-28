import 'package:flutter/material.dart';
import '../config/theme.dart';
import '../models/appointment.dart';
import '../services/api_service.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  int _currentBottomNavIndex = 0;
  bool _isLoading = true;
  List<AppointmentItem> _appointments = [];
  DashboardKPIs? _kpis;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    final data = await ApiService.fetchDashboardOverview();
    final scheduleJson = (data['today_schedule'] as List?) ?? [];
    
    setState(() {
      _kpis = DashboardKPIs.fromJson(data);
      _appointments = scheduleJson
          .where((item) => item['is_break'] != true)
          .map((item) => AppointmentItem.fromJson(item))
          .toList();
      _isLoading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(6),
              decoration: BoxDecoration(
                color: CliniqoTheme.brandLight,
                borderRadius: BorderRadius.circular(8),
              ),
              child: const Icon(Icons.local_hospital, color: CliniqoTheme.brandDark, size: 20),
            ),
            const SizedBox(width: 10),
            const Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Grand Central Polyclinic',
                  style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold),
                ),
                Text(
                  'Primary Care Branch • Live',
                  style: TextStyle(fontSize: 11, color: CliniqoTheme.textMuted),
                ),
              ],
            ),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.notifications_none, size: 22),
            onPressed: () {},
          ),
          const CircleAvatar(
            radius: 16,
            backgroundImage: NetworkImage('https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=120'),
          ),
          const SizedBox(width: 12),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: CliniqoTheme.brandDark))
          : RefreshIndicator(
              onRefresh: _loadData,
              color: CliniqoTheme.brandDark,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  // Greeting Card
                  Container(
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: CliniqoTheme.brandDark,
                      borderRadius: BorderRadius.circular(20),
                      boxShadow: [
                        BoxShadow(
                          color: CliniqoTheme.brandDark.withOpacity(0.15),
                          blurRadius: 10,
                          offset: const Offset(0, 4),
                        )
                      ],
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text(
                              'Good morning, Dr. Chen',
                              style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
                            ),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                              decoration: BoxDecoration(
                                color: Colors.white.withOpacity(0.15),
                                borderRadius: BorderRadius.circular(12),
                              ),
                              child: const Row(
                                children: [
                                  CircleAvatar(radius: 4, backgroundColor: CliniqoTheme.brandAccent),
                                  SizedBox(width: 5),
                                  Text('On Duty', style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
                                ],
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Text(
                          'Friday, Oct 24, 2026 • 38 Appointments Today',
                          style: TextStyle(color: Colors.white.withOpacity(0.85), fontSize: 12),
                        ),
                      ],
                    ),
                  ),

                  const SizedBox(height: 16),

                  // KPI 2x2 Grid
                  GridView.count(
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    crossAxisCount: 2,
                    childAspectRatio: 1.45,
                    crossAxisSpacing: 12,
                    mainAxisSpacing: 12,
                    children: [
                      _buildKpiCard('APPOINTMENTS', '${_kpis?.appointmentsToday ?? 38}', '+8% vs yday', Icons.calendar_today, CliniqoTheme.brandDark),
                      _buildKpiCard('CHECKED IN', '${_kpis?.patientsCheckedIn ?? 29}/38', '22 min avg', Icons.person_pin, CliniqoTheme.brandDark),
                      _buildKpiCard('PAYMENTS', '${_kpis?.paymentsCollected.toInt() ?? 4850} DH', 'Cash/POS/CNSS', Icons.payments, CliniqoTheme.brandDark),
                      _buildKpiCard('UNSETTLED CLAIMS', '${_kpis?.unsettledClaims.toInt() ?? 1180} DH', '5 dossiers CNSS', Icons.assignment_late, Colors.red[700]!),
                    ],
                  ),

                  const SizedBox(height: 20),

                  // Today's Schedule Stream Header
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text(
                        "Today's Patient Stream",
                        style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: CliniqoTheme.textDark),
                      ),
                      TextButton(
                        onPressed: () {},
                        child: const Text('View All', style: TextStyle(color: CliniqoTheme.brandDark, fontWeight: FontWeight.bold)),
                      ),
                    ],
                  ),

                  // Appointments List
                  ..._appointments.map((apt) => _buildAppointmentCard(apt)),
                ],
              ),
            ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: CliniqoTheme.brandDark,
        onPressed: () {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Nouveau rendez-vous : Conflits de créneaux vérifiés en temps réel.')),
          );
        },
        icon: const Icon(Icons.add, color: Colors.white),
        label: const Text('Nouveau RDV', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _currentBottomNavIndex,
        onDestinationSelected: (index) {
          setState(() => _currentBottomNavIndex = index);
        },
        destinations: const [
          NavigationDestination(icon: Icon(Icons.dashboard_outlined), selectedIcon: Icon(Icons.dashboard), label: 'Dashboard'),
          NavigationDestination(icon: Icon(Icons.calendar_month_outlined), selectedIcon: Icon(Icons.calendar_month), label: 'Schedule'),
          NavigationDestination(icon: Icon(Icons.people_outline), selectedIcon: Icon(Icons.people), label: 'Patients'),
          NavigationDestination(icon: Icon(Icons.receipt_long_outlined), selectedIcon: Icon(Icons.receipt_long), label: 'CNSS / Caisse'),
        ],
      ),
    );
  }

  Widget _buildKpiCard(String label, String value, String subtitle, IconData icon, Color iconColor) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.parseBorder(Border.all(color: const Color(0xFFE2E8F0))),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(label, style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: CliniqoTheme.textMuted)),
              Icon(icon, size: 16, color: iconColor),
            ],
          ),
          Text(value, style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w900, color: CliniqoTheme.textDark)),
          Text(subtitle, style: const TextStyle(fontSize: 10, color: CliniqoTheme.textMuted)),
        ],
      ),
    );
  }

  Widget _buildAppointmentCard(AppointmentItem apt) {
    Color badgeBg = Colors.grey[100]!;
    Color badgeText = Colors.grey[700]!;

    if (apt.status == 'completed') {
      badgeBg = CliniqoTheme.brandLight;
      badgeText = CliniqoTheme.brandDark;
    } else if (apt.status == 'in_progress') {
      badgeBg = Colors.green[50]!;
      badgeText = Colors.green[800]!;
    } else if (apt.status == 'pending') {
      badgeBg = Colors.amber[50]!;
      badgeText = Colors.amber[900]!;
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Text(
            apt.time,
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: CliniqoTheme.brandDark),
          ),
          const SizedBox(width: 12),
          CircleAvatar(
            radius: 18,
            backgroundImage: apt.avatarUrl != null ? NetworkImage(apt.avatarUrl!) : null,
            child: apt.avatarUrl == null ? const Icon(Icons.person, size: 18) : null,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text(apt.patientName, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                    if (apt.tag != null) ...[
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                        decoration: BoxDecoration(
                          color: CliniqoTheme.brandLight,
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: Text(apt.tag!, style: const TextStyle(fontSize: 9, fontWeight: FontWeight.bold, color: CliniqoTheme.brandDark)),
                      ),
                    ]
                  ],
                ),
                const SizedBox(height: 2),
                Text(apt.reason, style: const TextStyle(fontSize: 11, color: CliniqoTheme.textMuted), maxLines: 1, overflow: TextOverflow.ellipsis),
              ],
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
            decoration: BoxDecoration(
              color: badgeBg,
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(apt.statusLabel, style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: badgeText)),
          ),
        ],
      ),
    );
  }
}
