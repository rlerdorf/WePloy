CREATE TABLE `ploys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user` varchar(16) COLLATE utf8_bin NOT NULL,
  `description` varchar(255) COLLATE utf8_bin DEFAULT NULL,
  `log` varchar(128) COLLATE utf8_bin DEFAULT NULL,
  `target` varchar(16) COLLATE utf8_bin DEFAULT NULL,
  `revision` varchar(128) COLLATE utf8_bin DEFAULT NULL,
  `status` varchar(16) COLLATE utf8_bin DEFAULT NULL,
  `ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=340 DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

