#!/usr/bin/php
<?php
/** afdrelists.php -- Maintains the list and category of AfD debate relists
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

$relists = array("M" => "", "O" => "", "B" => "", "S" => "", "W" => "", "G" => "", "T" => "", "F" => "", "P" => "", "I" => "", "?" => "", "U" => "");

echo "Checking category members...";
$transcludes = $objwiki->categorymembers("Category:Relisted AfD debates");
echo " done.\n";

for ($i = 0; $i < count($transcludes); $i++) {
	preg_match("/(Wikipedia:Articles for deletion)\/(?!Log)/", $transcludes[$i], $m);
	echo "Retrieving $transcludes[$i] contents... \n";
	$contents = $objwiki->getpage($transcludes[$i]);
	if ($m[0] != "") {
		preg_match("/Please do not modify it/", $contents, $p);
		if ($p[0] != "") {
			$contents = str_replace("{{#ifeq:{{FULLPAGENAME}}|" . $transcludes[$i] . "|[[Category:Relisted AfD debates|{{SUBPAGENAME}}]]|}}", "", $contents); // backwards compatibility
			$contents = str_replace("{{#ifeq:{{BASEPAGENAME}}|Articles for deletion|[[Category:Relisted AfD debates|{{SUBPAGENAME}}]]|}}", "", $contents); // backwards compatibility
			$contents = str_replace("[[Category:Relisted AfD debates|{{SUBPAGENAME}}]]", "", $contents);
			$objwiki->edit($transcludes[$i],$contents,"Removing Category:Relisted AfD debates",true,true);
		}
		else {
			preg_match("/\{{2}REMOVE THIS TEMPLATE WHEN CLOSING THIS AfD\|(M|O|B|S|W|G|T|F|P|I|\?|U)\}{2}/i", $contents, $r);
			$delcat = preg_replace("/\{{2}REMOVE THIS TEMPLATE WHEN CLOSING THIS AfD\|/i", "", $r[0]);
			$delcat = preg_replace("/\}{2}/", "", $delcat);
			$prettyname = preg_replace("/Wikipedia:Articles for deletion\//", "", $transcludes[$i]);
			$relists[$delcat] .= "[[" . $transcludes[$i] . "|" . $prettyname . "]] &mdash; ";
		}
	}
	else {
		preg_match("/(?!Wikipedia:Articles for deletion)/", $transcludes[$i], $n);
		if ($n[0] != "") {
			$contents = str_replace("{{#ifeq:{{FULLPAGENAME}}|" . $transcludes[$i] . "|[[Category:Relisted AfD debates|{{SUBPAGENAME}}]]|}}", "", $contents); // backwards compatibility
			$contents = str_replace("{{#ifeq:{{BASEPAGENAME}}|Articles for deletion|[[Category:Relisted AfD debates|{{SUBPAGENAME}}]]|}}", "", $contents); // backwards compatibility
			$contents = str_replace("[[Category:Relisted AfD debates|{{SUBPAGENAME}}]]", "", $contents);
			$objwiki->edit($transcludes[$i],$contents,"Removing Category:Relisted AfD debates",true,true);
		}
	}
}

$submission = "";
$submission .= "{{User:Harej/coordcollapsetop|c=#BDD8FF|'''[[:Category:AfD debates (Media and music)|Media and music]]'''}}\n";
$submission .= $relists["M"] . "\n{{end}}\n";
$submission .= "{{User:Harej/coordcollapsetop|c=#BDD8FF|'''[[:Category:AfD debates (Organisation, corporation, or product)|Organisation, corporation, or product]]'''}}\n";
$submission .= $relists["O"] . "\n{{end}}\n";
$submission .= "{{User:Harej/coordcollapsetop|c=#BDD8FF|'''[[:Category:AfD debates (Biographical)|Biographical]]'''}}\n";
$submission .= $relists["B"] . "\n{{end}}\n";
$submission .= "{{User:Harej/coordcollapsetop|c=#BDD8FF|'''[[:Category:AfD debates (Society topics)|Society topics]]'''}}\n";
$submission .= $relists["S"] . "\n{{end}}\n";
$submission .= "{{User:Harej/coordcollapsetop|c=#BDD8FF|'''[[:Category:AfD debates (Web or Internet)|Web or Internet]]'''}}\n";
$submission .= $relists["W"] . "\n{{end}}\n";
$submission .= "{{User:Harej/coordcollapsetop|c=#BDD8FF|'''[[:Category:AfD debates (Games or sports)|Games or sports]]'''}}\n";
$submission .= $relists["G"] . "\n{{end}}\n";
$submission .= "{{User:Harej/coordcollapsetop|c=#BDD8FF|'''[[:Category:AfD debates (Science and technology)|Science and technology]]'''}}\n";
$submission .= $relists["T"] . "\n{{end}}\n";
$submission .= "{{User:Harej/coordcollapsetop|c=#BDD8FF|'''[[:Category:AfD debates (Fiction and the arts)|Fiction and the arts]]'''}}\n";
$submission .= $relists["F"] . "\n{{end}}\n";
$submission .= "{{User:Harej/coordcollapsetop|c=#BDD8FF|'''[[:Category:AfD debates (Places and transportation)|Places and transportation]]'''}}\n";
$submission .= $relists["P"] . "\n{{end}}\n";
$submission .= "{{User:Harej/coordcollapsetop|c=#BDD8FF|'''[[:Category:AfD debates (Indiscernible or unclassifiable topic)|Indiscernible or unclassifiable topic]]'''}}\n";
$submission .= $relists["I"] . "\n{{end}}\n";
//$submission .= "{{User:Harej/coordcollapsetop|c=#BDD8FF|'''[[:Category:AfD debates (Nominator unsure of category)|Nominator unsure of category]]'''}}\n";
//$submission .= $relists["?"] . "\n{{end}}\n";
$submission .= "{{User:Harej/coordcollapsetop|c=#BDD8FF|'''[[:Category:AfD debates (Not yet sorted)|Not yet sorted]]'''}}\n";
$submission .= $relists["U"] . "\n{{end}}";

$objwiki->edit("Wikipedia:Dashboard/Relisted AfD debates",$submission,"Updating AFD relists",false,true);

?>
