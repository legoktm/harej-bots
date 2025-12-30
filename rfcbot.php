#!/usr/bin/php
<?php

/** rfcbot.php - Automatic update of Wikipedia RFC lists
 *  (c) 2011 Chris Grant and others - http://en.wikipedia.org/wiki/User:Chris_G
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
 *    Chris Grant - [[User:Chris G]]  - Completely rewrote the code
 *    James Hare  - [[User:Harej]]    - Wrote the orignial bot
 **/

ini_set("display_errors", 1);
error_reporting(E_ALL ^ E_NOTICE);
//mysqli_report(MYSQLI_REPORT_ALL);

function generateRfcId ($tries=0) {
	global $rfcdb, $wiki;

	$tries++;
	if ($tries>5) {
		$page = $wiki->page('User talk:Chris G');
		$page->addSection('HELP! PLEASE!','Something is very wrong. I\'m having trouble generating a new RFC id. Please help me. --~~~~');
		die();
	}
	$tempid = substr(strtoupper(md5(rand())), 0, 7);
	
	$return='';
	$rfcSelect = $rfcdb->prepare("SELECT count(rfc_id) FROM rfc WHERE rfc_id=?;");
	$rfcSelect->bind_param("s",$tempid);
	$rfcSelect->execute();
	$rfcSelect->bind_result($return);
	$rfcSelect->fetch();
	$rfcSelect->close();
	
	if ($return>0) {
		generateRfcId($tries);
	} else {
		return $tempid;
	}
}

$botuser = 'Legobot';

//require_once 'database.inc';
require_once 'botclasses.php';
require_once 'new_mediawiki.php';
require_once 'harejpass.php';
$wiki = new mediawiki($botuser, $botpass);

// Definitions
$RFC_categories	= array(
	"bio", "hist", "econ", "sci", "lang", "media", "pol", "reli", "soc", "style", "policy", "proj", "tech", "prop", "unsorted"
			);
$listings = array();

// Crappy hack for edit summaries
$all_expired = array();

$RFC_submissions	= array();
$RFC_dashboard		= array();
$RFC_listofentries	= array();

foreach ($RFC_categories as $cat) {
	$RFC_submissions[$cat]		= "<noinclude>\n{{rfclistintro}}\n</noinclude>\n";
	$RFC_dashboard[$cat]		= "";
	$RFC_listofentries[$cat]	= array();
}

$RFC_pagetitles = array(
"bio"		=> "Wikipedia:Requests for comment/Biographies",
"econ"		=> "Wikipedia:Requests for comment/Economy, trade, and companies",
"hist"		=> "Wikipedia:Requests for comment/History and geography",
"lang"		=> "Wikipedia:Requests for comment/Language and linguistics",
"sci"		=> "Wikipedia:Requests for comment/Maths, science, and technology",
"media"		=> "Wikipedia:Requests for comment/Media, the arts, and architecture",
"pol"		=> "Wikipedia:Requests for comment/Politics, government, and law",
"reli"		=> "Wikipedia:Requests for comment/Religion and philosophy",
"soc"		=> "Wikipedia:Requests for comment/Society, sports, and culture",
"style"		=> "Wikipedia:Requests for comment/Wikipedia style and naming",
"policy"	=> "Wikipedia:Requests for comment/Wikipedia policies and guidelines",
"proj"		=> "Wikipedia:Requests for comment/WikiProjects and collaborations",
"tech"		=> "Wikipedia:Requests for comment/Wikipedia technical issues and templates",
"prop"		=> "Wikipedia:Requests for comment/Wikipedia proposals",
"unsorted"	=> "Wikipedia:Requests for comment/Unsorted",
);

$toolserver_mycnf = parse_ini_file("/data/project/legobot/replica.my.cnf");
$toolserver_username = $toolserver_mycnf['user'];
$toolserver_password = $toolserver_mycnf['password'];

echo "Connecting to tools-db\n";
$rfcdb = new mysqli('tools-db',$toolserver_username,$toolserver_password,'s51043__legobot');
if(mysqli_connect_errno()) {
	echo "Connection Failed: " . mysqli_connect_errno();
	die();
}
echo "Connecting to replica\n";
$replica_mycnf = parse_ini_file("/data/project/legobot/replica.my.cnf");
$replica_username = $replica_mycnf['user'];
$replica_password = $replica_mycnf['password'];

$enwikidb = new mysqli('enwiki.labsdb',$replica_username,$replica_password,'enwiki_p');
if(mysqli_connect_errno()) {
	echo "Connection Failed: " . mysqli_connect_errno();
	die();
}

$rfcid_list = array();

// Step 1: Check for transclusions
$transclusions = $wiki->getTransclusions("Template:Rfc");
foreach ($transclusions as $page) {
	$rfcid = null;

	if ($page==="Template:Rfc") {
		// Ignore self-transclusions of the template
		continue;
	}

	// Get the page content
	$page = $wiki->page($page);
	$content = $page->content();

	if (!$page->exists()) {
		$deleteId = $rfcdb->prepare("DELETE FROM `rfc` WHERE `rfc_page`=?;");
		$deleteId->bind_param("s",$page);
		$deleteId->execute();
		$deleteId->close();
		continue;
	}

	// Syntax Correction. RFC templates with common errors are corrected and then saved on the wiki.
	preg_match_all("/(\{{2}\s?Rfc(tag)?(?!\s+(top|bottom))\s?[^}]*\}{2}(\n|,| )*){2,}/i", $content, $fixes);
	foreach ($fixes[0] as $fix) {
		preg_match_all("/(?=\{{2}\s?Rfc(tag)?(?!\s+(top|bottom))\s?\|\s?)[^}]*/i", $fix, $parts);
		$newtag = "";
		foreach ($parts[0] as $part) {
			$newtag .= $part . "|";
		}
		$newtag		= str_replace("{{rfc|", "", $newtag);
		$newtag		= str_replace("{{rfctag|", "", $newtag);
		$newtag		= str_replace("}}", "", $newtag);
		$newtag		= "{{rfc|" . $newtag . "}}\n\n";
		$newtag		= str_replace("|}}", "}}", $newtag);
		$newtag		= str_replace("|art", "|media", $newtag);
		$content	= str_replace($fix, $newtag, $content);
		echo "Editing [[$page]]\n";
		$page->edit($content,"Fixing RFC template syntax.");
	}
	
	// Step 2: Seeding RFC IDs.
	// Before we read the RFC IDs and match them up to a title, description, etc.,
	// we want to make sure each RFC template has a corresponding RFC ID.
	preg_match_all("/\{{2}\s?Rfc(tag)?(?!\s+(top|bottom))\s?[^}]*\}{2}/i", $content, $matches);
	foreach ($matches[0] as $match) {
		if (strpos($match, "|rfcid=") === false) { // if the rfcid is not found within an RFC template
			$rfcid = generateRfcId(); # a seven-character random string with capital letters and digits
			$content = str_replace($match, "{{subst:DNAU|5|weeks}}\n" . $match . "|rfcid=" . $rfcid . "}}", $content);
			$content = str_replace("}}|rfcid", "|rfcid", $content);
			echo "Editing [[$page]]\n";
			$page->edit($content,"Adding RFC ID.");
			
			$insertId = $rfcdb->prepare("INSERT INTO `rfc` (`rfc_id`, `rfc_page`, `rfc_contacted`) VALUES (?, ?, 0);");
			$insertId->bind_param("ss",$rfcid,$page);
			$insertId->execute();
			$insertId->close();
		}
	}
	
	// Step 3: Check for RFC templates
	preg_match_all("/\{{2}\s?Rfc(tag)?(?!\s+(top|bottom))\s?[^}]*\}{2}/i", $content, $match);
	for ($result=0; $result < count($match[0]); $result++) { # For each result on a page
		//Get the details
		
		// Category
		preg_match_all("/\{{2}\s?Rfc(tag)?(?!\s+(top|bottom))[^2]\s?[^}]*\}{2}/i", $content, $m);
		$categorymeta = preg_replace("/\{*\s?(Rfc(?!id)(tag)?)\s?\|?\s?(1=)?\s?/i", "", $m[0][$result]);
		
		// An RFC can be forced to have a certain timestamp with the time= parameter in RFC template.
		unset($timestamp);
		preg_match("/\|time=([^|]|[^}])*/", $categorymeta, $forcedtimecheck);
		if ($forcedtimecheck[0] != "" || isset($forcedtimecheck[0])) {
			$prettytimestamp = str_replace("|time=", "", $forcedtimecheck[0]);
			$prettytimestamp = str_replace("}", "", $prettytimestamp);
			$timestamp = strtotime($prettytimestamp);
		}
		
		// Description and Timestamp
		if (!isset($timestamp)) {
			$description = preg_replace("/<!--[^\n]+-->/","",$content);
			preg_match_all("/\{{2}\s?Rfc(tag)?(?!\s+(top|bottom))\s?[^}]*\}{2}(.|\n)*?([0-2]\d):([0-5]\d),\s(\d{1,2})\s(\w*)\s(\d{4})\s\(UTC\)/im", $description, $m);
			$description = preg_replace("/\{{2}\s?Rfc(tag)?(?!\s+(top|bottom))\s?[^}]*\}{2}\n*/i", "", $m[0][$result]); // get rid of the RFC template
			$description = preg_replace("/={2,}\n+/", "'''\n\n", $description); // replace section headers with boldness
			$description = preg_replace("/\n+={2,}/", "\n\n'''", $description);
			//$description = preg_replace("/\{\{[^}]+\}\}/", "", $description); // remove any other templates
			$description = "{{rfcquote|text=\n" . $description . "}}"; // indents the description
			preg_match("/([0-2]\d):([0-5]\d),\s(\d{1,2})\s(\w*)\s(\d{4})\s\(UTC\)/i", $description, $t);
			$timestamp = strtotime($t[0]);
		} else {
			$description = $prettytimestamp;
		}
		
		// RFC ID
		preg_match("/(\|)?rfcid=\s*([A-z0-9]*)/", $categorymeta, $rfcidcheck);
		if ($rfcidcheck[0] != "" || isset($rfcidcheck[0])) {
			$rfcid	= $rfcidcheck[2];
		}
		if (empty($rfcid)) {
			continue;
		}
		
		$rfcid_list[] = $rfcid;
		
		$categorymeta = preg_replace("/\s*\}*/", "", $categorymeta);
		$categorymeta = preg_replace("/=*/", "", $categorymeta);
		$categorymeta = preg_replace("/\|time([^|]|[^}])*/", "", $categorymeta);
		$categorymeta = preg_replace("/\|rfcid([^|]|[^}])*/", "", $categorymeta);
		$categories = explode("|", $categorymeta);

		unset($forcedtimecheck);
		unset($rfcidcheck);
		
		// Step 4: Inspecting for expiration. Something that's expired gets removed; something that's not expired gets moved up to the big leagues! Whee!
		if (time() - $timestamp > 2592000 && $timestamp != "" && !preg_match('/<!--\s*RFCBot\s+Ignore\s+Expired\s*-->/i',$content) || preg_match("/\/Archive \d+/", $page)) {
			echo "RFC expired. Removing tag.\n";

			$rfcAnchor = "{{anchor|rfc_" + $rfcid + "}}";
			$content = preg_replace("/\{\{rfc(tag)?(?!\s+(top|bottom))\s*(\|[a-z0-9\., ]*)*\s*\|rfcid=$rfcid\s*(\|[a-z0-9\., \|]*)*\s*\}\}(\n|\s)?/i", $rfcAnchor, $content);
			
			
			echo "Editing [[$page]]\n";
			$page->edit($content,"Removing expired RFC template.");
			
			$all_expired[] = $rfcid;
			
			$updateRow = $rfcdb->prepare("UPDATE `rfc` SET rfc_expired=1 WHERE rfc_id=?;");
			$updateRow->bind_param("s",$rfcid);
			$updateRow->execute();
			$updateRow->close();
		} else {
			$listings[$rfcid]["title"] = $page;
			$listings[$rfcid]["description"] = $description;
			$listings[$rfcid]["timestamp"] = $timestamp;
			foreach ($categories as $category) {
				if (in_array($category,$RFC_categories)) {
					$listings[$rfcid]["category"][] = $category;
				}
			}
			if (count($listings[$rfcid]["category"]) == 0) {
				$listings[$rfcid]["category"][0] = "unsorted";
			}
		
			// Check that the database is upto date with everything
			$return='';
			$rfcSelect = $rfcdb->prepare("SELECT rfc_expired FROM rfc WHERE rfc_id=?;");
			$rfcSelect->bind_param("s",$rfcid);
			$rfcSelect->execute();
			$rfcSelect->bind_result($return);
			$rfcSelect->fetch();
			$rfcSelect->close();
		
			if ($return==1) {
				$notExpired = $rfcdb->prepare("UPDATE `rfc` SET rfc_expired=0 WHERE rfc_id=?;");
				$notExpired->bind_param("s",$rfcid);
				$notExpired->execute();
				$notExpired->close();
			} else {
				$insertId = $rfcdb->prepare("INSERT IGNORE INTO `rfc` (`rfc_id`, `rfc_page`, `rfc_contacted`) VALUES (?, ?, 0);");
				$insertId->bind_param("ss",$rfcid,$page);
				$insertId->execute();
				$insertId->close();
			}
		
			$updateRow = $rfcdb->prepare("UPDATE `rfc` SET rfc_timestamp=? WHERE rfc_id=?;");
			$updateRow->bind_param("is",$listings[$rfcid]["timestamp"],$rfcid);
			$updateRow->execute();
			$updateRow->close();
		
			$return='';
			$rfcSelect = $rfcdb->prepare("SELECT rfcc_category FROM rfc_category WHERE rfcc_id=?;");
			$rfcSelect->bind_param("s",$rfcid);
			$rfcSelect->execute();
			$rfcSelect->bind_result($return);
			$database_cat = array();
			while ($rfcSelect->fetch()) {
				$database_cat[] = $return;
			}
			$rfcSelect->close();
		
			foreach ($listings[$rfcid]["category"] as $category) {
				if (!in_array($category,$database_cat)) {
					$insertId = $rfcdb->prepare("INSERT INTO `rfc_category` (`rfcc_id`, `rfcc_category`) VALUES (?, ?);");
					$insertId->bind_param("ss",$rfcid,$category);
					$insertId->execute();
					$insertId->close();
				}
			}
		}
		unset($timestamp);
		unset($forcedtimecheck);
		unset($prettytimestamp);
		unset($categorymeta);
		unset($description);
		unset($timestamp);
		unset($rfcidcheck);
		unset($rfcid);
		unset($categories);
	}
}

// Update any expired rfcs
$expiredRfcs = $rfcdb->prepare("SELECT rfc_id FROM rfc WHERE rfc_expired=0;");
$expiredRfcs->execute();
$expiredRfcs->bind_result($expRfcid);
$temp_remove = array();
while ($expiredRfcs->fetch()) {
	if (!in_array($expRfcid,$rfcid_list)) {
		$all_expired[] = $expRfcid;
		$temp_remove[] = $expRfcid;
	}
}
$expiredRfcs->close();
if (count($temp_remove)>0) {
	foreach ($temp_remove as $temp) {
		$expiredRfcsUpdate = $rfcdb->prepare("UPDATE rfc SET rfc_expired=1 WHERE rfc_id=?;");
		$expiredRfcsUpdate->bind_param("s",$temp);
		$expiredRfcsUpdate->execute();
		$expiredRfcsUpdate->close();
	}
}

$new_all_expired = array();
$rfcid = '';
foreach ($all_expired as $rfcid) {
	$return='';
	$return2='';
	$rfcSelect = $rfcdb->prepare("SELECT rfc_page,rfcc_category FROM rfc JOIN rfc_category ON rfc_id=rfcc_id WHERE rfc_id=?;");
	$rfcSelect->bind_param("s",$rfcid);
	$rfcSelect->execute();
	$rfcSelect->bind_result($return,$return2);
	while ($rfcSelect->fetch()) {
		$new_all_expired[$return2][] = $return;
	}
	$rfcSelect->close();
}
$all_expired = $new_all_expired;

$rfclisting = "{{navbox\n| name = {{subst:FULLPAGENAME}}\n| title = Requests for comment\n| state = {{{state|plain}}}\n| basestyle = background: #BDD8FF;\n| liststyle = line-height: 220%;\n| oddstyle = background: #EEEEEE;\n| evenstyle = background: #DEDEDE;\n";
$counter = 0;

foreach ($RFC_pagetitles as $RFCcategory => $RFCpage) {
	$summary = '';
	$summary_added = array();
	$summary_removed = array();
	$summary_removed = $all_expired[$RFCcategory];
	
	$RFCpage = $wiki->page($RFCpage);
	$oldpage = $RFCpage->content();
	$newpage = $RFC_submissions[$RFCcategory];
	
	$rfcid='';
	$rfcpage='';
	$rfcSelect = $rfcdb->prepare("SELECT DISTINCT rfc_id,rfc_page FROM rfc JOIN rfc_category ON rfc_id=rfcc_id 
				      WHERE rfcc_category=? AND rfc_expired=0 ORDER BY rfc_timestamp DESC;");
	$rfcSelect->bind_param("s",$RFCcategory);
	$rfcSelect->execute();
	$rfcSelect->bind_result($rfcid,$rfcpage);
	
	$counter++;
	$rfclisting .= "| group" . $counter . " = [[" . $RFCpage . "|" . str_replace("Wikipedia:Requests for comment/", "", $RFCpage) . "]]\n";
	$rfclisting .= "| list" . $counter . " = ";
	$dot=false;
	
	while ($rfcSelect->fetch()) {
		$temp = "[[$rfcpage#rfc_$rfcid|$rfcpage]]";
		
		if (!$dot) {
			$rfclisting .= $temp;
			$dot=true;
		} else {
			$rfclisting .= '{{dot}}'.$temp;
		}
		
		$temp = "'''$temp'''\n";
		
		$newpage .= $temp;
		$newpage .= $listings[$rfcid]["description"]."\n";
		
		// Crappy hack to get the edit summary nice
		if (strpos($oldpage, $temp) === false) {
			$summary_added[] = $rfcpage;
		}
	}
	
	
	$rfclisting .= "\n";
	
	$rfcSelect->close();
	
	$newpage .= "{{RFC list footer|" . $RFCcategory . "|hide_instructions={{{hide_instructions}}} }}";
	
	if (count($summary_added)>0) {
		$summary .= "Added: ";
		foreach ($summary_added as $add) {
			$summary .= "[[$add]] ";
		}
	}
	
	if (!empty($summary_removed)) {
		$summary .= "Removed:";
		foreach ($summary_removed as $removed) {
			$summary .= " [[$removed]]";
		}
	}
	
	if ($oldpage != $newpage) {
		if (empty($summary)) {
			$summary = 'Maintenance';
		}
		echo "Editing: [[$RFCpage]]\n";
		$RFCpage->edit($newpage,trim($summary).'.');
	}
}
$rfclisting .= "}}";

// Update [[Wikipedia:Dashboard/Requests for comment]]
echo "Editing [[Wikipedia:Dashboard/Requests for comment]]\n";
$dashboard = $wiki->page("Wikipedia:Dashboard/Requests for comment");
$dashboard->edit($rfclisting,"Updating RFC listings.");

// Feedback request service
$frs = $wiki->page('Wikipedia:Feedback request service');
$frscontent = explode("\n",$frs->content());
$section = null;
$frs_users = array();
foreach ($frscontent as $line) {
	if (preg_match('/<!--\s*rfc\s*:\s*([a-z]+)\s*-->/i',$line,$m)) {
		$section = strtolower($m[1]);
	}
	if ($section != null && preg_match('/\*\s*\{\{frs user\|([^\|]*)(\|(\d*))?}}/i',$line,$m)) {
		if (empty($m[3])) {
			$m[3] = 1;
		}
		$frs_users[$m[1]][$section] = $m[3];
	}
	
	if (!empty($line) && strpos('<!-- END OF RFC SECTION. DO NOT REMOVE THIS COMMENT. -->',$line) !== false) {
		break;
	}
}

foreach ($frs_users as $username => $extra) {
	// Get their userid
	$userid='';
	$user_ec='';
	$enSelect = $enwikidb->prepare("SELECT user_id,user_editcount FROM user WHERE user_name=?;");
	$enSelect->bind_param("s",$username);
	$enSelect->execute();
	$enSelect->bind_result($userid,$user_ec);
	$enSelect->fetch();
	$enSelect->close();
	
	// Does the user exist
	if (empty($userid)) {
		continue;
	}
	
	$frsusername='';
	$frsdisqualified='';
	$frsquery = $rfcdb->prepare("SELECT frs_username,frs_disqualified FROM frs_user WHERE frs_userid=?;");
	$frsquery->bind_param("i",$userid);
	$frsquery->execute();
	$frsquery->bind_result($frsusername,$frsdisqualified);
	$frsquery->fetch();
	$frsquery->close();
	
	if (empty($frsusername)) {
		$frsquery = $rfcdb->prepare("INSERT INTO frs_user (frs_userid,frs_username,frs_disqualified) VALUES (?,?,0);");
		$frsquery->bind_param("is",$userid,$username);
		$frsquery->execute();
		$frsquery->close();
	}
	
	// Check that the user is vaild:
	$disqualified = false;

	// Are they currently blocked
	$ipb_id='';
	$isblocked = $enwikidb->prepare("SELECT ipb_id FROM ipblocks WHERE ipb_user = ?;");
	$isblocked->bind_param("i",$userid);
	$isblocked->execute();
	$isblocked->bind_result($ipb_id);
	$isblocked->fetch();
	$isblocked->close();
	if (!empty($ipb_id)) {
		$disqualified = true;
	} else {
		// Have they edited in the last 30 days
		$thirtydays = time() - 2592000;
		$timestamp = date('YmdHis',$thirtydays);
		$editsIn30Days='';
		$recentEdits = $enwikidb->prepare("SELECT count(rev_id) FROM revision_userindex WHERE rev_user=? AND rev_timestamp  > ?;");
		$recentEdits->bind_param("ii",$userid,$timestamp);
		$recentEdits->execute();
		$recentEdits->bind_result($editsIn30Days);
		$recentEdits->fetch();
		$recentEdits->close();
		if ($editsIn30Days < 1) {
			$disqualified = true;
		}
	}
	
	if ($disqualified) {
		$frsquery = $rfcdb->prepare("UPDATE frs_user SET frs_disqualified=1 WHERE frs_userid=?;");
		$frsquery->bind_param("i",$userid);
		$frsquery->execute();
		$frsquery->close();
		continue;
	} elseif ($frsdisqualified) {	
		$frsquery = $rfcdb->prepare("UPDATE frs_user SET frs_disqualified=0 WHERE frs_userid=?;");
		$frsquery->bind_param("i",$userid);
		$frsquery->execute();
		$frsquery->close();
	}
	
	$db_limits = array();
	$frsl_category='';
	$frsl_limit='';
	$frsquery = $rfcdb->prepare("SELECT frsl_category,frsl_limit FROM frs_limits WHERE frsl_userid=?;");
	$frsquery->bind_param("i",$userid);
	$frsquery->execute();
	$frsquery->bind_result($frsl_category,$frsl_limit);
	while ($frsquery->fetch()) {
		$db_limits[$frsl_category] = $frsl_limit;
	}
	$frsquery->close();
	
	foreach ($extra as $cat => $limit) {
		if (!array_key_exists($cat,$db_limits)) {
			$frsquery = $rfcdb->prepare("INSERT INTO frs_limits (frsl_userid,frsl_category,frsl_limit) VALUES (?,?,?);");
			$frsquery->bind_param("isi",$userid,$cat,$limit);
			$frsquery->execute();
			$frsquery->close();
		} elseif ($db_limits[$cat] != $limit) {
			$frsquery = $rfcdb->prepare("UPDATE frs_limits SET frsl_limit=? WHERE frsl_userid=?;");
			$frsquery->bind_param("ii",$limit,$userid);
			$frsquery->execute();
			$frsquery->close();
		}
	}
}

$delete = array();
$delete_all = array();
$frsl_userid='';
$frsl_category='';
$frsl_limit='';
$frsquery = $rfcdb->prepare("SELECT frs_username,frs_userid,frsl_category 
			     FROM frs_user JOIN frs_limits ON frs_userid=frsl_userid;");
$frsquery->execute();
$frsquery->bind_result($frs_username,$frs_userid,$frsl_category);
while ($frsquery->fetch()) {
	if (empty($frs_users[$frs_username])) {
		if (!in_array($frs_userid,$delete_all)) {
			$delete_all[] = $frs_userid;
		}
	} elseif (!array_key_exists($frsl_category,$frs_users[$frs_username])) {
		$delete[$frs_userid] = $frsl_category;
	}
}
$frsquery->close();

foreach ($delete_all as $userid) {
	$frsquery = $rfcdb->prepare("DELETE FROM frs_limits WHERE frsl_userid=?;");
	$frsquery->bind_param("i",$userid);
	$frsquery->execute();
	$frsquery->close();
}

foreach ($delete as $userid => $cat) {
	$frsquery = $rfcdb->prepare("DELETE FROM frs_limits WHERE frsl_userid=? AND frsl_category=?;");
	$frsquery->bind_param("is",$userid,$cat);
	$frsquery->execute();
	$frsquery->close();
}
