#!/usr/bin/php
<?php
/** noticeboards.php -- Maintains a listing of noticeboard topics
 *  STABLE Version 1.0
 *
 *  (c) 2010 James Hare - http://en.wikipedia.org/wiki/User:Harej
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
 *  Developers (add your self here if you worked on the code):
 *    James Hare - [[User:Harej]] - Wrote everything
 **/
ini_set("display_errors", 1);
error_reporting(E_ALL ^ E_NOTICE);

$botuser = 'Legobot';
require_once 'botclasses.php';  // Botclasses.php was written by User:Chris_G and is available under the GNU General Public License
require_once 'harejpass.php';

echo "Logging in...";
$objwiki = new wikipedia();
$objwiki->login($botuser, $botpass);
echo " done.\n";

function nbprocess ($pagearray, $color, $submissionpage) {
	global $objwiki;
	$noticeboardlisting = "";
	
	foreach ($pagearray as $topic => $page) {
		
		$raw = $objwiki->query("?action=parse&page=". urlencode($page) . "&prop=sections&format=php");
		
		$sections = array();
		$seccount = 0;
	
		for ($i = 0; $i < count($raw["parse"]["sections"]); $i++) {
			if ($raw["parse"]["sections"][$i]["level"] == 2) {
				$sections[$seccount] = $raw["parse"]["sections"][$i]["line"];
				$seccount++;
			}
		}
		
		unset($raw);
		$sections = array_reverse($sections); // i put my thang down, flip it, and reverse it
		
		$listing = "";
		
		for ($i = 0; $i < count($sections); $i++) {
			$sectionlink = str_replace(" ", "_", $sections[$i]);
			$sectionlink = urlencode($sectionlink);
			$sections[$i] = str_replace("{{", "<nowiki>{{</nowiki>", $sections[$i]);
			$sections[$i] = str_replace("}}", "<nowiki>}}</nowiki>", $sections[$i]);
			$sections[$i] = str_replace("[[", "<nowiki>[[</nowiki>", $sections[$i]);
			$sections[$i] = str_replace("]]", "<nowiki>]]</nowiki>", $sections[$i]);
			$sections[$i] = str_replace("~~~~", "<nowiki>~~~~</nowiki>", $sections[$i]);
			if ($i < 3) {
				$headerlisting .= " — [[" . $page . "#" . $sectionlink . "|" . $sections[$i] . "]]";
			}
			else {
				$listing .= "[[" . $page . "#" . $sectionlink . "|" . $sections[$i] . "]] &mdash; ";
			}
		}
		
		$noticeboardlisting .= "{{User:Harej/coordcollapsetop|c=" . $color . "|'''[[" . $page . "|" . $topic . "]]''' (" . $seccount . ")<br /><small>Most recent sections " . $headerlisting . "}}\n" . $listing . "\n\n{{collapse bottom}}\n";
		
		unset($headerlisting);
	}
	
	$objwiki->edit($submissionpage,$noticeboardlisting,"Updating Noticeboard topics",false,true);
}


$noticeboards = array(
"Administrators' noticeboard" => "Wikipedia:Administrators' noticeboard",
"Administrators' noticeboard: Incidents" => "Wikipedia:Administrators' noticeboard/Incidents",
"Edit warring noticeboard" => "Wikipedia:Administrators' noticeboard/Edit warring",
"Bureaucrats' noticeboard" => "Wikipedia:Bureaucrats' noticeboard",
"Bot owners' noticeboard" => "Wikipedia:Bot owners' noticeboard",
"Arbitration Committee noticeboard" => "Wikipedia:Arbitration Committee/Noticeboard",
"Arbitration Enforcement noticeboard" => "Wikipedia:Arbitration/Requests/Enforcement",
//"Wikiquette alerts" => "Wikipedia:Wikiquette alerts",
);

$editboards = array(
"Content noticeboard" => "Wikipedia:Content noticeboard",
"BLP noticeboard" => "Wikipedia:Biographies of living persons/Noticeboard",
"Ethnic and religious conflict noticeboard" => "Wikipedia:Administrators' noticeboard/Geopolitical ethnic and religious conflicts",
//"Fiction noticeboard" => "Wikipedia:Fiction/Noticeboard",
"Fringe theories noticeboard" => "Wikipedia:Fringe theories/Noticeboard",
"Original research noticeboard" => "Wikipedia:No original research/Noticeboard",
"Reliable sources noticeboard" => "Wikipedia:Reliable sources/Noticeboard",
"Notability noticeboard" => "Wikipedia:Notability/Noticeboard",
"Neutral point of view noticeboard" => "Wikipedia:Neutral point of view/Noticeboard",
"External Links noticeboard" => "Wikipedia:External links/Noticeboard",
"Conflict of interest noticeboard" => "Wikipedia:Conflict of interest/Noticeboard",
"Non-free content review" => "Wikipedia:Non-free content review",
"Dispute resolution noticeboard" => "Wikipedia:Dispute resolution noticeboard"
);

$assistboards = array(
"New user help" => "Wikipedia:New contributors' help page/questions",
"Editor assistance" => "Wikipedia:Editor assistance/Requests",
"Help desk" => "Wikipedia:Help desk",
"Requests for feedback" => "Wikipedia:Requests for feedback",
"Drawing board" => "Wikipedia:Drawing board",
"Media copyright questions" => "Wikipedia:Media copyright questions"
);


$villagepump = array(
"Village Pump (policy)" => "Wikipedia:Village pump (policy)",
"Village Pump (technical)" => "Wikipedia:Village pump (technical)",
"Village Pump (proposals)" => "Wikipedia:Village pump (proposals)",
"Village Pump (idea lab)" => "Wikipedia:Village pump (idea lab)",
"Village Pump (miscellaneous)" => "Wikipedia:Village pump (miscellaneous)"
);

nbprocess($noticeboards, "#FFCECE", "Wikipedia:Dashboard/Administrative noticeboards");
nbprocess($editboards, "#D1FFB3", "Wikipedia:Dashboard/Editorial noticeboards");
nbprocess($assistboards, "#CEFFFD", "Wikipedia:Dashboard/Help noticeboards");
nbprocess($villagepump, "#FFFFB5", "Wikipedia:Dashboard/Village pump");

?>
