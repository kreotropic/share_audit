<?php

declare(strict_types=1);

script('share_audit_dashboard', 'share_audit_dashboard-main');
style('share_audit_dashboard', 'admin');
?>

<div id="share-audit-personal" class="section">
    <!-- The personal Vue 3 app is mounted here. Only rendered at all when
         PersonalSettings::getSection() returns non-null, i.e. the admin has
         the personal view enabled — see that class' docblock. -->
</div>
