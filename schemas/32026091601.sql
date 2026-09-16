DELIMITER $$

INSERT INTO CustomFieldDefinition (name, moduleId, required, help, showInList, typeId, isEncrypted)
SELECT 'OTP', 1, 0, 'TOTP secret used to generate a login code for this account', 0, 11, 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM CustomFieldDefinition WHERE moduleId = 1 AND typeId = 11
) $$
