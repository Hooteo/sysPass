<?php
/**
 * @var ThemeIcons $icons
 * @var ConfigData $configData
 * @var callable   $_getvar
 * @var Template   $this
 */

use SP\Config\ConfigData;
use SP\Core\UI\ThemeIcons;
use SP\Mvc\View\Template;

$enabled = $_getvar('enabled');
?>

<div id="title" class="titleNormal">
    <i class="material-icons">vpn_key</i>
    <?php echo __('Two-Factor Authentication'); ?>
</div>

<?php if ($enabled): ?>
    <p><?php echo __('Two-factor authentication is currently enabled on your account.'); ?></p>
    <p><?php echo __('You will be asked for a code from your authenticator app every time you sign in.'); ?></p>

    <form method="post" name="frmMfaDisable" id="frmMfaDisable"
          class="form-action"
          data-onsubmit="user/saveSettings"
          data-action-route="userMfa/delete"
          data-hash="">
        <input type="hidden" name="isAjax" value="1"/>
        <input type="hidden" name="sk" value="<?php echo $sk; ?>">
    </form>

    <div class="tab-actions">
        <ul>
            <li>
                <button id="btnBack" type="button"
                        class="btn-back mdl-button mdl-js-button mdl-button--fab mdl-button--mini-fab mdl-button--colored <?php echo $icons->getIconBack()->getClassButton(); ?>"
                        title="<?php echo __('Back'); ?>">
                    <i class="material-icons"><?php echo $icons->getIconBack()->getIcon(); ?></i>
                </button>
            </li>
            <li>
                <button form="frmMfaDisable"
                        class="mdl-button mdl-js-button mdl-button--fab mdl-button--mini-fab mdl-button--colored <?php echo $icons->getIconDelete()->getClassButton(); ?>"
                        title="<?php echo __('Disable'); ?>">
                    <i class="material-icons"><?php echo $icons->getIconDelete()->getIcon(); ?></i>
                </button>
            </li>
        </ul>
    </div>
<?php else: ?>
    <p><?php echo __('Add this key manually in your authenticator app (Google Authenticator, Authy, etc.):'); ?></p>
    <p class="center"><code id="mfa-secret"><?php echo $_getvar('secret'); ?></code></p>
    <p><?php echo __('Then enter the 6-digit code it shows, and your current password to confirm, to enable it.'); ?></p>

    <form method="post" name="frmMfaEnable" id="frmMfaEnable"
          class="form-action"
          data-onsubmit="user/saveSettings"
          data-action-route="userMfa/save"
          data-hash="">
        <table class="data tblConfig round">
            <tr>
                <td class="descField"><?php echo __('Code'); ?></td>
                <td class="valField">
                    <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                        <input id="mfacode" name="mfacode" type="text"
                               class="mdl-textfield__input mdl-color-text--indigo-400"
                               maxlength="6" autocomplete="off" inputmode="numeric" pattern="[0-9]*"
                               required>
                        <label class="mdl-textfield__label"
                               for="mfacode"><?php echo __('Authentication Code'); ?></label>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="descField"><?php echo __('Your Password'); ?></td>
                <td class="valField">
                    <div class="mdl-textfield mdl-js-textfield mdl-textfield--floating-label">
                        <input id="pass" name="pass" type="password"
                               class="mdl-textfield__input mdl-color-text--indigo-400"
                               maxlength="255" autocomplete="off" required>
                        <label class="mdl-textfield__label"
                               for="pass"><?php echo __('Password'); ?></label>
                    </div>
                </td>
            </tr>
        </table>

        <input type="hidden" name="secret" value="<?php echo $_getvar('secret'); ?>"/>
        <input type="hidden" name="isAjax" value="1"/>
        <input type="hidden" name="sk" value="<?php echo $sk; ?>">
    </form>

    <div class="tab-actions">
        <ul>
            <li>
                <button id="btnBack" type="button"
                        class="btn-back mdl-button mdl-js-button mdl-button--fab mdl-button--mini-fab mdl-button--colored <?php echo $icons->getIconBack()->getClassButton(); ?>"
                        title="<?php echo __('Back'); ?>">
                    <i class="material-icons"><?php echo $icons->getIconBack()->getIcon(); ?></i>
                </button>
            </li>
            <li>
                <button form="frmMfaEnable"
                        class="mdl-button mdl-js-button mdl-button--fab mdl-button--mini-fab mdl-button--colored <?php echo $icons->getIconSave()->getClassButton(); ?>"
                        title="<?php echo $icons->getIconSave()->getTitle(); ?>">
                    <i class="material-icons"><?php echo $icons->getIconSave()->getIcon(); ?></i>
                </button>
            </li>
        </ul>
    </div>
<?php endif; ?>
