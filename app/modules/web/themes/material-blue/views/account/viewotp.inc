<?php
/**
 * @var callable   $_getvar
 * @var ThemeIcons $icons
 */

use SP\Core\UI\ThemeIcons;

?>
<div id="box-popup" class="box-password-view">
    <h2 class="center"><?php echo $_getvar('header'); ?></h2>
    <table>
        <tbody>
        <tr>
            <td class="dialog-text dialog-pass-text" id="otp-code"><?php echo $_getvar('code'); ?></td>
            <td class="dialog-button right">
                <button class="dialog-clip-button mdl-button mdl-js-button mdl-js-ripple-effect"
                        title="<?php echo __('Copy OTP Code'); ?>"
                        data-clipboard-target="#otp-code">
                    <?php echo __('OTP'); ?>
                    <i class="material-icons"><?php echo $icons->getIconClipboard()->getIcon(); ?></i>
                </button>
            </td>
        </tr>
        </tbody>
    </table>
    <p class="center"><?php echo __('Refreshes in'); ?> <?php echo $_getvar('secondsRemaining'); ?>s</p>
</div>
