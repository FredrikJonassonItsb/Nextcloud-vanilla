<?php
declare(strict_types=1);

?>

<div class="guest-box">
    <div class="authenticate-form">
        <h2><?php p($l->t('Logged Out')); ?></h2>
        <p class="logout-message">
            <?php p($l->t('You have been successfully logged out.')); ?>
        </p>
    </div>
</div>

<style>
.logout-message {
    text-align: center;
    margin: 20px 0;
    color: var(--color-text-light);
}
</style>
