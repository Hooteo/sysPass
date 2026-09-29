DELIMITER $$

UPDATE CustomFieldDefinition
SET help = 'TOTP secret used to generate a login code for this account. Click the eye icon to reveal it in plain text (the same format an authenticator app calls "enter code manually") - useful to also set this same code up on another device or app.'
WHERE moduleId = 1 AND typeId = 11 $$
