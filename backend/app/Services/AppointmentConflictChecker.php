<?php

namespace App\Services;

use DateTime;
use Exception;

/**
 * Service de Contrôle de Conflits d'Agenda
 * Conformément à la section 6.5 du Cahier des charges :
 * "Interdire le chevauchement de deux rendez-vous pour un même praticien.
 * Vérifier la disponibilité côté serveur au moment de la sauvegarde, avec transaction ou verrouillage approprié."
 */
class AppointmentConflictChecker
{
    /**
     * Vérifie si un créneau est disponible pour un praticien donné
     */
    public static function checkSlotAvailability(
        int $tenantId,
        int $practitionerId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $excludeAppointmentId = null,
        array $existingAppointments = []
    ): bool {
        $start = new DateTime("$date $startTime");
        $end = new DateTime("$date $endTime");

        if ($start >= $end) {
            throw new Exception("L'heure de début doit être strictement antérieure à l'heure de fin.");
        }

        foreach ($existingAppointments as $apt) {
            if ($excludeAppointmentId && ($apt['id'] ?? null) === $excludeAppointmentId) {
                continue;
            }

            if (($apt['practitioner_id'] ?? null) !== $practitionerId) {
                continue;
            }

            if (($apt['status'] ?? '') === 'cancelled') {
                continue;
            }

            $existingStart = new DateTime($date . ' ' . $apt['start_time']);
            $existingEnd = new DateTime($date . ' ' . $apt['end_time']);

            // Overlap condition: start < existingEnd AND end > existingStart
            if ($start < $existingEnd && $end > $existingStart) {
                return false; // Conflit détecté !
            }
        }

        return true;
    }
}
