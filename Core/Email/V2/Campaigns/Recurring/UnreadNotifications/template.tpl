
<tr>
    <td>
    <p
        <?php echo $emailStyles->getStyles('m-unreadNotifications__title', 'm-fonts'); ?>
    >
        Hi <?php echo $vars['user']->getName(); ?>! Here's some things you may have missed.
    </p>
    </td>
</tr>

<tr>
    <td>
        <table
            border="0"
            cellpadding="0"
            cellspacing="0"
            <?php echo $emailStyles->getStyles('m-unreadNotifications__count'); ?>>

            <tr>
                <td>
                    <a href="<?php echo $vars['site_url']; ?>notifications/v3?<?php echo $vars['tracking']; ?>"
                        <?php echo $emailStyles->getStyles('m-fonts', 'm-unreadNotificationsCount__text', 'm-textColor--primary'); ?>
                    >
                        <?php echo $vars['unreadNotificationsCount']; ?> unseen notifications
                    </a>
                </td>
            </tr>
        </table>
    </td>
</tr>

<tr>
    <td>
        <table
            border="0"
            cellpadding="20"
            cellspacing="0"
            <?php echo $emailStyles->getStyles('m-unreadNotifications__previews'); ?>
            >
            <?php foreach ($vars['unreadPushNotifications'] as $pushNotification) { ?>
            <tr <?php echo $emailStyles->getStyles('m-unreadNotifications__preview'); ?>>
                <td>
                    <?php echo $pushNotification->getTitle(); ?>: <?php echo $pushNotification->getBody(); ?> 
                </td>
            </tr>
            <?php } ?>
        </table>
    </td>
</tr>

<?php echo $vars['actionButton']; ?>