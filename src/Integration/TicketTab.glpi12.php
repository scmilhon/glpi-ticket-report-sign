<?php
namespace GlpiPlugin\Glpiticketreportsign\Integration;

use GlpiPlugin\Glpiticketreportsign\Profile;

/**
 * Leaf used on GLPI 12+, where CommonGLPI::$rightname is typed
 * `string`. All real logic lives in TicketTabBase — see the comment
 * there for why this class only adds the $rightname declaration.
 * Keep the two leaves (this file and TicketTab.php) in sync on
 * anything other than the $rightname line.
 */
class TicketTab extends TicketTabBase
{
    public static string $rightname = Profile::RIGHTNAME;
}
