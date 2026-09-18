<?php
namespace GlpiPlugin\Glpiticketreportsign;

use GlpiPlugin\Glpiticketreportsign\Report\ReportRecord;

/**
 * Leaf used on GLPI 12+, where CommonDBTM::$rightname is typed
 * `string`. All real logic lives in ReportRecord — see the comment
 * there for why this class only adds the $rightname declaration.
 * Keep the two leaves (this file and Report.php) in sync on anything
 * other than the $rightname line.
 */
class Report extends ReportRecord
{
    public static string $rightname = Profile::RIGHTNAME;
}
