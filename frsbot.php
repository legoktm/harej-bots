#!/usr/bin/php
<?php

/** frsbot.php - Send users notification about RFC's
 *  (c) 2011 Chris Grant - http://en.wikipedia.org/wiki/User:Chris_G
 *	
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 *   
 *  Developers (add yourself here if you worked on the code):
 *    Kunal Mehta - [[User:Legoktm]]  - Minor fix to run on Tool Labs
 *    Chris Grant - [[User:Chris G]]  - Completely rewrote the code
 *    James Hare  - [[User:Harej]]    - Wrote the orignial bot
 **/

ini_set("display_errors", 1);
error_reporting(E_ALL ^ E_NOTICE);

$botuser = 'Legobot';

require_once 'botclasses.php';
require_once 'new_mediawiki.php';
require_once 'harejpass.php';
$wiki = new mediawiki($botuser, $botpass);

$RFC_categories	= array(
	"bio", "hist", "econ", "sci", "lang", "media", "pol", "reli", "soc", "style", "policy", "proj", "tech", "prop", "unsorted","all"
			);

$toolserver_mycnf = parse_ini_file("/data/project/legobot/replica.my.cnf");
$toolserver_username = $toolserver_mycnf['user'];
$toolserver_password = $toolserver_mycnf['password'];


$rfcdb = new mysqli('tools-db',$toolserver_username,$toolserver_password,'s51043__legobot');
if(mysqli_connect_errno()) {
	echo "Connection Failed: " . mysqli_connect_errno();
	die();
}

$new_time = array();
foreach ($RFC_categories as $cat) {
	$frsquery = $rfcdb->prepare("SELECT frs_userid,frs_username,frsl_limit FROM frs_user JOIN frs_limits 
				     ON frs_userid=frsl_userid WHERE frs_disqualified=0 AND frsl_category=?;");
	$frsquery->bind_param("s",$cat);
	$frsquery->execute();
	$frsquery->bind_result($frs_userid,$frs_username,$frsl_limit);
	$temp01 = array();
	while ($frsquery->fetch()) {
		$temp01[] = array($frs_userid,$frs_username,$frsl_limit);
	}
	$frsquery->close();
	
	foreach ($temp01 as $temp) {
		$frs_userid = $temp[0];
		$frs_username = $temp[1];
		$frsl_limit = $temp[2];
		
		$time = 30/$frsl_limit;
		$time = 86400*$time;
		$oldtime = time() - $time;
		$count = 0;
		
		$frsquery2 = $rfcdb->stmt_init();
		$frsquery2->prepare("SELECT count(frsc_id) FROM frs_contacts WHERE frsc_userid=? AND frsc_timestamp > ?;");
		$frsquery2->bind_param("ii",$frs_userid,$oldtime);
		$frsquery2->execute();
		$frsquery2->bind_result($count);
		$frsquery2->fetch();
		$frsquery2->close();
		if ($count > 0) {
			echo "Skipping $frs_username\n";
			continue;
		}
		
		$rfc_pool = array();
		$frsquery3 = $rfcdb->stmt_init();
		$timestamp_expire = time();
		$timestamp_expire = $timestamp_expire - 3600*24*25;
		if ($cat != 'all') {
			$frsquery3->prepare("SELECT DISTINCT rfc_id,rfc_page FROM rfc JOIN rfc_category ON rfc_id=rfcc_id 
			WHERE rfcc_category=? AND rfc_expired=0 AND rfc_timestamp > ? AND NOT EXISTS 
			(SELECT * FROM frs_contacts WHERE frsc_userid=? AND frsc_rfcid=rfc_id);");
			$frsquery3->bind_param("sii",$cat,$timestamp_expire,$frs_userid);
		} else {
			$frsquery3->prepare("SELECT DISTINCT rfc_id,rfc_page FROM rfc JOIN rfc_category ON rfc_id=rfcc_id 
			WHERE rfc_expired=0 AND rfc_timestamp > ? AND NOT EXISTS 
			(SELECT * FROM frs_contacts WHERE frsc_userid=? AND frsc_rfcid=rfc_id);");
			$frsquery3->bind_param("ii",$timestamp_expire,$frs_userid);
		}
		$frsquery3->execute();
		$frsquery3->bind_result($rfc_id,$rfc_page);
		$temp02=array();
		while ($frsquery3->fetch()) {
			$temp02[]=array($rfc_id,$rfc_page);
		}
		$frsquery3->close();
		foreach ($temp02 as $temp2) {
			$rfc_id=$temp2[0];
			$rfc_page=$temp2[1];
			
			$timestamp = 0;
			$frsquery4 = $rfcdb->stmt_init();
			$frsquery4->prepare("SELECT frsc_timestamp FROM frs_contacts WHERE frsc_rfcid=? ORDER BY frsc_timestamp DESC LIMIT 1;");
			$frsquery4->bind_param("s",$rfc_id);
			$frsquery4->execute();
			$frsquery4->bind_result($timestamp);
			$frsquery4->fetch();
			$frsquery4->close();
			
			$rfc_pool[$rfc_id]['page'] = $rfc_page;
			$rfc_pool[$rfc_id]['time'] = $timestamp;
		}
		
		$rfctouse = null;
		foreach ($rfc_pool as $a => $b) {
			if ($rfctouse==null) {
				$rfctouse = $a;
			} elseif ($rfc_pool[$rfctouse]['time'] > $b['time']) {
				$rfctouse = $a;
			}
		}
		echo "User:$frs_username RFC: ".$rfc_pool[$rfctouse]['page']."\n";
		
		if (empty($rfc_pool[$rfctouse]['page'])) {
			continue;
		}
		
		$frsinsert = $rfcdb->stmt_init();
		$frsinsert->prepare("INSERT INTO frs_contacts (frsc_userid,frsc_rfcid,frsc_timestamp) VALUES (?,?,?);");
		$newtime = time();
		$frsinsert->bind_param("isi",$frs_userid,$rfctouse,$newtime);
		$frsinsert->execute();
		$frsinsert->close();
		
		
		$randomuser_talkpage = $wiki->page("User talk:" . $frs_username);
		$randomuser_talkpage->resolveRedirects();
		if (substr($randomuser_talkpage,0,strlen("User talk")) != "User talk") {
			continue;
		}
		$randomuser_talkpage->addSection("Please comment on [[" . $rfc_pool[$rfctouse]['page'] . "#rfc_" . $rfctouse . "|" . $rfc_pool[$rfctouse]['page'] . "]]","{{subst:FRS message|title=" . $rfc_pool[$rfctouse]['page'] . "|rfcid=" . $rfctouse . "}} <!-- FRS id " . $rfcdb->insert_id . " --> ~~~~");
		
		sleep(5);
	}
}
