<?php
namespace GlpiPlugin\Glpiticketreportsign\Security;

use Group_User;
use Ticket;

/**
 * Per-ticket authorization: only the assigned technician (user
 * directly assigned, type=ASSIGN) or a supervisor of one of the
 * tech's groups (or of any group assigned to the ticket) may
 * generate or sign a report.
 *
 * "Supervisor" follows GLPI's convention: a user in a group with
 * `glpi_groups_users.is_manager = 1`.
 */
class Authorizer
{
    /**
     * True when the ticket is in the CLOSED state. Used to gate
     * report generation: once a ticket is closed the report list
     * is read-only.
     */
    public static function isTicketClosed(int $ticketId): bool
    {
        $t = new \Ticket();
        if (!$t->getFromDB($ticketId)) {
            return false;
        }
        return (int) $t->fields['status'] === \Ticket::CLOSED;
    }

    /**
     * True when the ticket's most recent ITILSolution has the type
     * "Mantenimiento Preventivo" (any casing / accent variant). The
     * Report tab uses this to flip between the two flows:
     *
     *   - Maintenance ticket  → MTTO's button enabled, Generate
     *                            disabled (use MTTO so the computer
     *                            data shows up in the report).
     *   - Anything else       → Generate enabled, MTTO disabled.
     *
     * The ticket status is not consulted here — adding the right
     * solution type is enough to flip into maintenance mode.
     */
    public static function isMaintenanceTicket(int $ticketId): bool
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => 'st.name',
            'FROM'   => 'glpi_itilsolutions AS s',
            'LEFT JOIN' => [
                'glpi_solutiontypes AS st' => ['ON' => ['s' => 'solutiontypes_id', 'st' => 'id']],
            ],
            'WHERE'  => ['s.itemtype' => 'Ticket', 's.items_id' => $ticketId],
            'ORDER'  => 's.date_creation DESC',
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($row)) {
            return false;
        }
        return self::normalize((string) ($row['name'] ?? ''))
            === self::normalize('Mantenimiento Preventivo');
    }

    /**
     * Back-compat alias used by older call sites.
     * @deprecated Use isMaintenanceTicket() — status check moved up.
     */
    public static function isMttoEligible(int $ticketId): bool
    {
        return self::isMaintenanceTicket($ticketId);
    }

    /**
     * True when the given solutiontype id refers to a row whose
     * name normalises to "mantenimiento preventivo". Used by the
     * auto-generate-on-solution hook to skip generation for
     * maintenance tickets (those go through the MTTO flow instead).
     */
    public static function isMaintenanceSolutionType(int $solutionTypesId): bool
    {
        if ($solutionTypesId <= 0) {
            return false;
        }
        global $DB;
        $row = $DB->request([
            'SELECT' => 'name',
            'FROM'   => 'glpi_solutiontypes',
            'WHERE'  => ['id' => $solutionTypesId],
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($row)) {
            return false;
        }
        return self::normalize((string) ($row['name'] ?? ''))
            === self::normalize('Mantenimiento Preventivo');
    }

    private static function normalize(string $s): string
    {
        $s = trim($s);
        $s = mb_strtolower($s, 'UTF-8');
        $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        return preg_replace('/\s+/', ' ', $s) ?? $s;
    }

    public static function canActOnTicket(int $ticketId, ?int $userId = null): bool
    {
        $userId ??= (int) ($_SESSION['glpiID'] ?? 0);
        if ($userId <= 0 || $ticketId <= 0) {
            return false;
        }

        // Super-admin always allowed.
        if (!empty($_SESSION['glpiactiveprofile']['interface'])
            && (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) === 4) {
            return true;
        }

        // Ticket requesters (clients) are allowed on their own
        // tickets — for read-only access to the report and to sign
        // their own (client) part. Sign endpoints enforce that they
        // can't pose as the technician.
        if (in_array($userId, self::ticketRequesterUsers($ticketId), true)) {
            return true;
        }

        $assignedUsers  = self::ticketAssignedUsers($ticketId);
        $assignedGroups = self::ticketAssignedGroups($ticketId);

        if (in_array($userId, $assignedUsers, true)) {
            return true;
        }

        $managedGroups = self::groupsManagedBy($userId);
        if ($managedGroups === []) {
            return false;
        }

        // Supervisor of an assigned group?
        foreach ($assignedGroups as $g) {
            if (in_array($g, $managedGroups, true)) {
                return true;
            }
        }

        // Supervisor of any group containing an assigned tech?
        if ($assignedUsers !== []) {
            $techGroups = self::groupsOfUsers($assignedUsers);
            foreach ($techGroups as $g) {
                if (in_array($g, $managedGroups, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * True when the active session belongs to a requester on the
     * given ticket — i.e. the client opening their own ticket from
     * the helpdesk/self-service interface.
     */
    public static function isTicketRequester(int $ticketId, ?int $userId = null): bool
    {
        $userId ??= (int) ($_SESSION['glpiID'] ?? 0);
        if ($userId <= 0 || $ticketId <= 0) {
            return false;
        }
        return in_array($userId, self::ticketRequesterUsers($ticketId), true);
    }

    /** @return int[] */
    private static function ticketRequesterUsers(int $ticketId): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request([
            'SELECT' => 'users_id',
            'FROM'   => 'glpi_tickets_users',
            'WHERE'  => ['tickets_id' => $ticketId, 'type' => \CommonITILActor::REQUESTER],
        ]) as $r) {
            $ids[] = (int) $r['users_id'];
        }
        return $ids;
    }

    /** @return int[] */
    private static function ticketAssignedUsers(int $ticketId): array
    {
        global $DB;
        $ids = [];
        $iter = $DB->request([
            'SELECT' => 'users_id',
            'FROM'   => 'glpi_tickets_users',
            'WHERE'  => ['tickets_id' => $ticketId, 'type' => \CommonITILActor::ASSIGN],
        ]);
        foreach ($iter as $r) {
            $ids[] = (int) $r['users_id'];
        }
        return $ids;
    }

    /** @return int[] */
    private static function ticketAssignedGroups(int $ticketId): array
    {
        global $DB;
        $ids = [];
        $iter = $DB->request([
            'SELECT' => 'groups_id',
            'FROM'   => 'glpi_groups_tickets',
            'WHERE'  => ['tickets_id' => $ticketId, 'type' => \CommonITILActor::ASSIGN],
        ]);
        foreach ($iter as $r) {
            $ids[] = (int) $r['groups_id'];
        }
        return $ids;
    }

    /** @return int[] */
    private static function groupsManagedBy(int $userId): array
    {
        global $DB;
        $ids = [];
        $iter = $DB->request([
            'SELECT' => 'groups_id',
            'FROM'   => 'glpi_groups_users',
            'WHERE'  => ['users_id' => $userId, 'is_manager' => 1],
        ]);
        foreach ($iter as $r) {
            $ids[] = (int) $r['groups_id'];
        }
        return $ids;
    }

    /**
     * @param int[] $userIds
     * @return int[]
     */
    private static function groupsOfUsers(array $userIds): array
    {
        global $DB;
        if ($userIds === []) {
            return [];
        }
        $ids  = [];
        $iter = $DB->request([
            'SELECT' => 'groups_id',
            'FROM'   => 'glpi_groups_users',
            'WHERE'  => ['users_id' => $userIds],
        ]);
        foreach ($iter as $r) {
            $ids[] = (int) $r['groups_id'];
        }
        return array_values(array_unique($ids));
    }
}
