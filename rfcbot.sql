/* Create the SQL structure necessary for rfcbot.php 
 * (c) 2011 Chris Grant - http://en.wikipedia.org/wiki/User:Chris_G
 * I hereby release the code below into the public domain.
 */

CREATE TABLE IF NOT EXISTS `rfc` (
  `rfc_id` varchar(7) NOT NULL PRIMARY KEY,
  `rfc_page` varchar(255) NOT NULL,
  `rfc_contacted` tinyint(1) NOT NULL,
  `rfc_expired` tinyint(1) NOT NULL DEFAULT FALSE,
  `rfc_timestamp` int(11) DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS `rfc_category` (
  `rfcc_id` varchar(7) NOT NULL,
  `rfcc_category` varchar(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS `frs_user` (
  `frs_userid` int(11) NOT NULL PRIMARY KEY,
  `frs_username` varchar(255) NOT NULL,
  `frs_disqualified` tinyint(1) NOT NULL DEFAULT FALSE
);

CREATE TABLE IF NOT EXISTS `frs_limits` (
  `frsl_id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `frsl_userid` int(11) NOT NULL,
  `frsl_category` varchar(255) NOT NULL,
  `frsl_limit` int(11) NOT NULL
);

CREATE TABLE IF NOT EXISTS `frs_contacts` (
  `frsc_id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `frsc_userid` int(11) NOT NULL,
  `frsc_rfcid` varchar(7) NOT NULL,
  `frsc_timestamp` int(11) NOT NULL
);
