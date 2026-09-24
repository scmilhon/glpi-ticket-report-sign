<?php
namespace GlpiPlugin\Glpiticketreportsign\Mail;

use CommonITILActor;
use User;

/**
 * Resolves the email address(es) to notify for a ticket's
 * requester(s) — used by ReportMailer for the automatic sign-request
 * / view-link emails. Covers both linked GLPI users (default email
 * via User::getDefaultEmail()) and anonymous requesters (the
 * alternative_email column glpi_tickets_users carries for those).
 */
class Recipients
{
    /** @return string[] deduplicated, non-empty */
    public static function requesterEmails(int $ticketId): array
    {
        return array_column(self::requesterContacts($ticketId), 'email');
    }

    /**
     * Same requesters as requesterEmails(), paired with a display name
     * — a linked GLPI user's own name, or '' for an anonymous
     * requester (glpi_tickets_users has no name field for those, only
     * alternative_email). Used to pre-fill the "Name" field in
     * front/sign.form.php once the matching in-person code is verified
     * (see SigningToken::mint()/verifyOtpForTicket()).
     *
     * @return array<int,array{email:string,name:string}> deduplicated by email
     */
    public static function requesterContacts(int $ticketId): array
    {
        global $DB;
        $contacts = [];
        foreach ($DB->request([
            'SELECT' => ['users_id', 'alternative_email'],
            'FROM'   => 'glpi_tickets_users',
            'WHERE'  => ['tickets_id' => $ticketId, 'type' => CommonITILActor::REQUESTER],
        ]) as $row) {
            $usersId = (int) ($row['users_id'] ?? 0);
            if ($usersId > 0) {
                $user = new User();
                if ($user->getFromDB($usersId)) {
                    $email = (string) $user->getDefaultEmail();
                    if ($email !== '') {
                        $contacts[$email] = [
                            'email' => $email,
                            'name'  => formatUserName(
                                $usersId,
                                (string) ($user->fields['name']      ?? ''),
                                (string) ($user->fields['realname']  ?? ''),
                                (string) ($user->fields['firstname'] ?? '')
                            ),
                        ];
                    }
                }
                continue;
            }
            $alt = trim((string) ($row['alternative_email'] ?? ''));
            if ($alt !== '' && filter_var($alt, FILTER_VALIDATE_EMAIL)) {
                $contacts[$alt] = ['email' => $alt, 'name' => ''];
            }
        }
        return array_values($contacts);
    }
}
