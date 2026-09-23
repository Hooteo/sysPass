DELIMITER $$

CREATE TABLE `UserMfa`
(
  `id`      int(10) unsigned     NOT NULL AUTO_INCREMENT,
  `userId`  smallint(5) unsigned NOT NULL,
  `secret`  varbinary(2000)      NOT NULL,
  `key`     varbinary(2000)      NOT NULL,
  `dateAdd` int(10) unsigned     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_UserMfa_01` (`userId`),
  CONSTRAINT `fk_UserMfa_userId` FOREIGN KEY (`userId`) REFERENCES `User` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
)
  ENGINE = InnoDB
  DEFAULT CHARSET = utf8
  COLLATE utf8_unicode_ci $$
