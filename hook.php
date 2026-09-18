<?php
/**
 * Ticket Report & Sign plugin install / uninstall lifecycle.
 */

use GlpiPlugin\Glpiticketreportsign\Cron\CleanupDrafts;
use GlpiPlugin\Glpiticketreportsign\Install\Installer;
use GlpiPlugin\Glpiticketreportsign\Profile;

function plugin_glpiticketreportsign_install(): bool
{
    require_once __DIR__ . '/src/Install/Installer.php';

    (new Installer())->install();

    // Daily cron that purges abandoned drafts older than 15 days.
    // Idempotent: CronTask::register no-ops if the row exists.
    CronTask::register(
        CleanupDrafts::class,
        CleanupDrafts::TASK_NAME,
        DAY_TIMESTAMP,
        [
            'state'         => CronTask::STATE_WAITING,
            'mode'          => CronTask::MODE_EXTERNAL,
            'allowmode'     => CronTask::MODE_EXTERNAL | CronTask::MODE_INTERNAL,
            'logs_lifetime' => 30,
            'comment'       => 'Remove draft Ticket Report & Sign rows (and their PDFs) older than 15 days.',
        ]
    );

    Profile::createFirstAccess((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0));
    return true;
}

function plugin_glpiticketreportsign_uninstall(): bool
{
    require_once __DIR__ . '/src/Install/Installer.php';

    CronTask::unregister('glpiticketreportsign');

    (new Installer())->uninstall();
    return true;
}
