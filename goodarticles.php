#!/usr/bin/php
<?php

/** goodarticles.php -- Maintains WP:GAN
 *  (c) 2012 Chris Grant - http://en.wikipedia.org/wiki/User:Chris_G
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
 *    Chris Grant - [[User:Chris G]]  - Rewrote the code
 *    James Hare  - [[User:Harej]]    - Wrote the orignial bot
 **/

set_time_limit(600);
ini_set("display_errors", 1);
error_reporting(E_ALL ^ E_NOTICE);
date_default_timezone_set('UTC');

$botuser = 'Legobot';
$databasename = 'locallegobot';
require_once 'botclasses.php';
require_once 'harejpass.php';

class extendedWikiBot extends wikipedia {
	public function getpage ($page,$revid=null,$detectEditConflict=false) {
		$append = '';
		if ($revid!=null)
		    $append = '&rvstartid='.$revid;
	
		for ($i=0;$i<5;$i++) {
			$x = parent::query('?action=query&format=php&prop=revisions&titles='.urlencode($page).'&rvlimit=1&rvprop=content|timestamp'.$append);
			if (isset($x['query']['pages'])) {
				foreach ($x['query']['pages'] as $ret) {
					if (isset($ret['revisions'][0]['*'])) {
						if ($detectEditConflict)
							$this->ecTimestamp = $ret['revisions'][0]['timestamp'];
						return $ret['revisions'][0]['*'];
				    	} elseif (isset($ret['missing'])) {
						return false;
					}
				}
			}
			sleep(1);
		}
		return $x;
	}
}

class editsummary {
	private $passed, $failed, $sNew, $onReview, $onHold;
	
	public function __construct () {
		$this->passed = array();
		$this->failed = array();
		$this->sNew   = array();
		$this->onReview = array();
		$this->onHold = array();
	}

	public function passed ($page,$subcat) {
		$this->passed[$subcat][] = $page;
	}
	
	public function failed ($page,$subcat) {
		$this->failed[$subcat][] = $page;
	}
	
	public function sNew ($page,$subcat) {
		$this->sNew[$subcat][] = $page;
	}
	
	public function onReview ($page,$subcat,$reviewer) {
		$this->onReview[$subcat][] = array($page,$reviewer);
	}
	
	public function onHold ($page,$subcat,$reviewer) {
		$this->onHold[$subcat][] = array($page,$reviewer);
	}
	
	private function rmSubCats ( $var, $subcats=false ) {
		$clean = array();
		foreach ($var as $sub => $x) {
			if ($subcats !== false) {
				if (!in_array($sub,$subcats)) {
					continue;
				}
			}
			foreach ($x as $y) {
				$clean[] = $y;
			}
		}
		return $clean;
	}
	
	public function generate ( $subcats=false ) {
		$sum = '';
		
		if (!empty($this->sNew)) {
			foreach ($this->sNew as $sub => $x) {
				if ($subcats===false) { 
					foreach ($x as $y) {
						$sum .= "[[$y]] ($sub) ";
					}
				} elseif (in_array($sub,$subcats)) {
					foreach ($x as $y) {
						$sum .= "[[$y]] ";
					}
				}
			}
			if (!empty($sum)) {
				$sum = "New $sum";
			}
		}
		
		$passed = $this->rmSubCats($this->passed,$subcats);
		if (!empty($passed)) {
			$sum .= "Passed ";
			foreach ($passed as $x) {
				$sum .= "[[$x]] ";
			}
		}
		
		$failed = $this->rmSubCats($this->failed,$subcats);
		if (!empty($failed)) {
			$sum .= "Failed ";
			foreach ($failed as $x) {
				$sum .= "[[$x]] ";
			}
		}
		
		$onHold = $this->rmSubCats($this->onHold,$subcats);
		if (!empty($onHold)) {
			$sum .= "On hold ";
			foreach ($onHold as $x) {
				$sum .= "[[".$x[0]."]] by ".$x[1]." ";
			}
		}
		
		$onReview = $this->rmSubCats($this->onReview,$subcats);
		if (!empty($onReview)) {
			$sum .= "On review ";
			foreach ($onReview as $x) {
				$sum .= "[[".$x[0]."]] by ".$x[1]." ";
			}
		}
		
		$sum = trim($sum);
		if (empty($sum))
			$sum = 'Maintenance';
		return $sum;
	}
}

class GANom {
	private $unixtime, $timestamp, $reviewpage, $subtopic, $status, $nominator, $note, $article, $valid_statuses, $reviewer, $reviewerRaw;
	
	public function __construct ( $article, $template=null ) {
		$this->unixtime = time();
		$this->timestamp = "Error parsing timestamp.";
		$this->reviewpage = false;
		$this->reviewer = 'Example';
		$this->reviewerRaw = '[[User:Example|Unknown]]';
		$this->subtopic = 'Miscellaneous';
		$this->status = 'new';
		$this->valid_statuses = array('new','passed','failed','on hold','on review','2nd opinion');
		$this->nominator = '[[User:Example|Unknown]]';
		$this->nominator_plain = 'Example';
		$this->note = false;
		$this->article = trim($article);
		
		if ($template != null) {
			$this->parseTemplate($template);
		}
	}
	
	public function __toString () {
		return $this->article;
	}
	
	public function getVar ( $var ) {
		if (in_array($var,array('status','reviewpage','reviewer','subtopic','unixtime','subtopic','nominator','nominator_plain')))
			return $this->$var;
		return false;
	}
	
	public function parseTemplate ( $template ) {
		foreach ($template as $key => $value) {
			$key = trim(strtolower($key));
			if (preg_match('/^\s*\d+:\d\d,?\s+\d+\s+[a-z]+\s+\d{4}\s+\(UTC\)\s*$/i',$value)) {
				$this->timestamp = trim($value);
				$this->setTime($value);
			} elseif ($key=='page') {
				$this->setReviewPage($value);
			} elseif ($key=='subtopic') {
				$this->setSubtopic($value);
			} elseif ($key=='status') {
				$this->setStatus($value);
			} elseif ($key=='nominator') {
				$this->setNominator($value);
			} elseif ($key=='note') {
				$this->setNote($value);
			}
		}
	}
	
	public function setTime ( $timestamp ) {
		$unix = strtotime($timestamp);
		if ($unix === false)
			return false;
		$this->unixtime = $unix;
	}
	
	public function setReviewPage ( $page ) {
		$page = trim($page);
		if (preg_match('/^[0-9]+$/',$page)) {
			$this->reviewpage = $page;
		}
	}
	
	public function setSubtopic ( $topic ) {
		$topic = trim(str_replace(", and", " and", $topic));
		if (!empty($topic))
			$this->subtopic = $topic;
	}
	
	public function setStatus ( $status ) {
		$status = $this->cleanStatus(trim(strtolower($status)));
		if (in_array($status,$this->valid_statuses))
			 $this->status = $status;
	}
	
	private function cleanStatus ( $status ) {
		if (preg_match('/(on ?)?hold/i',$status)) {
			return 'on hold';
		} elseif (preg_match('/(on ?)?review/i',$status)) {
			return 'on review';
		} elseif (preg_match('/(2nd|second|2)? ?op(inion)?/i',$status)) {
			return '2nd opinion';
		}
		return $status;
	}
	
	public function setNominator ( $nominator ) {
		$nominator = trim($nominator);
		if (!empty($nominator)) {
			$this->nominator = $nominator;
			preg_match("/\[\[User:(.+?)\|.+?\]\]/",$nominator,$m);
			if (!empty($m[1]))
				$this->nominator_plain = trim(ucfirst(str_replace('_',' ',$m[1])));
		}
	}
	
	public function setNote ( $note ) {
		$note = trim($note);
		if (!empty($note))
			$this->note = $note;
	}
	
	public function setReviewer ( $reviewer,$raw=false ) {
		$reviewer = trim($reviewer);
		if (!empty($reviewer)) {
			$this->reviewer = $reviewer;
			$this->reviewerRaw = "{{user|$reviewer}}";
		}
		if ($raw != false)
			$this->reviewerRaw = $raw;
	}
	
	private function numOfReviews ($name) {
		global $gaStats;
		foreach ($gaStats as $key => $values) {
			if ($values[0]==$name) {
				return "(Reviews: " . $values[1] . ") ";
			}
		}
	}
	
	public function miniWikiCode () {
		return "# {{GANentry|1=" . $this->article . "|2=" . $this->reviewpage . "}}";
	}
	
	public function wikicode () {
		$code = "# {{GANentry|1=" . $this->article . "|2=" . $this->reviewpage . "}} " . $this->numOfReviews($this->nominator_plain) . $this->nominator . " " . $this->timestamp . "\n";
		if ($this->status == "on hold") {
			$code .= "#:{{GAReview|status=on hold}} " . $this->numOfReviews($this->reviewer) . $this->reviewerRaw . "\n";
		} elseif ($this->status == '2nd opinion') {
			$code .= "#:{{GAReview|status=2nd opinion}} " . $this->numOfReviews($this->reviewer) . $this->reviewerRaw . "\n";
		} elseif ($this->status == 'on review') {
			$code .= "#:{{GAReview}} " . $this->numOfReviews($this->reviewer) . $this->reviewerRaw . "\n";
		}
		
		if ($this->note !== false) {
			$code .= "#: [[File:Ambox_notice.png|15px]] '''Note:''' " . $this->note . "\n";
		}
		
		return $code;
	}
}

class template implements Iterator {
	private $name;
	private $params;
	private $pos;
	
	public function __construct ($name, $params) {
		$this->name = $name;
		if (empty($params)) {
			$this->params = array();
		} else {
			foreach ($params as $key => $value) {
				$this->params[] = array($key,$value);
			}
		}
		$this->pos = 0;
	}
	
	public function getByKey ($key) {
		if (!isset($this->params[$key])) {
			return false;
		} else {
			return $this->params[$key];
		}
	}
	
	public function __toString () {
		return $this->name;
	}
	
	public function current () {
		return $this->params[$this->pos][1];
	}
	
	public function key () {
		return $this->params[$this->pos][0];
	}
	
	public function valid () {
		if (isset($this->params[$this->pos]))
			return true;
		return false;
	}
	
	public function rewind () {
		$this->pos = 0;
	}
	
	public function next () {
		$this->pos++;
	}
}

// Look, don't even bother and read this, just accept that it parses
// $text, and returns an array of templates.
// Yes, it is horrible in every possible way; but it works.
function parsetemplates ($text) {
	$text = str_split($text);
	$template_level = $args = 0;
	$template = $templates = array();
	$ignore_next_char = $in_link = false;
	$arg_name = null;
	
	for ($i=0;$i<count($text);$i++) {
		$prev = $text[($i - 1)];
		$next = $text[($i + 1)];
		$char = $text[$i];
		if ($char=='[' && $prev == '[') {
			$in_link = true;
		} elseif ($char==']' && $next == ']') {
			$in_link = false;
		}
		if ($char=='{' && $prev == '{') {
			$template_level++;
			if ($template_level==1) {
				$start = $i;
				$code = '{{';
				continue;
			}
		} elseif ($char=='}' && $next == '}' && !$ignore_next_char) {
			$template_level--;
			$ignore_next_char = true;
			if ($template_level==0) {
				$args = 0;
				$code .= '}}';
				$template['name'] = trim($template['name']);
				$tmp_args = array();
				if (!empty($template['args'])) {
					foreach ($template['args'] as $tArg) {
						$tmp_args[] = trim($tArg);
					}
				}
				$templates[] = new template($template['name'],$template['args']);
				$template = array();
				continue;
			}
		} elseif ($ignore_next_char) {
			$ignore_next_char = false;
		}
		if ($template_level==1) {
			$code .= $char;
			if ($char=='|' && !$in_link) {
				$args++;
				$arg_name = null;
				continue;
			} elseif ($char=='='  && $arg_name==null) {
				$arg_name = $template['args'][$args];
				unset($template['args'][$args]);
				continue;
			}
			if ($args==0) {
				$template['name'] .= $cont.$char;
			} elseif ($arg_name!=null) {
				$template['args'][$arg_name] .= $cont.$char;
			} else {
				$template['args'][$args] .= $cont.$char;
			}
			$cont = '';
		} elseif ($template_level > 1) {
			$cont .= $char;
			$code .= $char;
		}
	}
	return $templates;
}


echo "Logging in...";
$wiki = new extendedWikiBot();
$wiki->login($botuser, $botpass);
echo " done.\n";

require_once 'new_mediawiki.php';
$wiki2 = new mediawiki($botuser, $botpass);

$http = new http();

/* Connect to the database */
echo "Retrieving database login credentials...";
$toolserver_mycnf = parse_ini_file("/data/project/legobot/.my.cnf");
$toolserver_username = $toolserver_mycnf['user'];
$toolserver_password = $toolserver_mycnf['password'];
unset($toolserver_mycnf);
echo " done.\n";
 
echo "Logging into database...";
try {
	$mysql = new PDO("mysql:host=tools-db;dbname=$databasename", $toolserver_username, $toolserver_password);
} catch(PDOException $e) {
	echo "Cannot connect to database.\n";
	die();
}
echo " done.\n";

$editsummary = new editsummary();

$x = explode("\n",$wiki->getpage("User:GA bot/Stats"));
$gaStats = array();
foreach ($x as $y) {
	preg_match('/\[\[User:(.+?)\|.+?\]\]/i',$y,$m);
	preg_match('/<td>\s*(\d+)\s*<\/td>/i',$y,$n);
	if (empty($m[1]) || empty($n[1])) {
		continue;
	}
	$gaStats[] = array($m[1],$n[1]);
}

// This looks for transclusions of the GA nominee template, with some filtering done to restrict the list to the talk namespace.
// TODO: This filtering should be done at an API level

echo "Checking for transclusions...";
$transcludes = $wiki->getTransclusions("Template:GA nominee");
if (count($transcludes)<1) {
	echo "No transclusions found.\n";
	die();
}
echo " done.\n";
 
$articles = array();
foreach ($transcludes as $trans) {
	if (preg_match("/^Talk:/", $trans)) {
		$articles[] = $trans;
	}
}
unset($transcludes);

$wpgan = $wiki->getpage("Wikipedia:Good article nominations");
if (empty($wpgan)) {
	echo "[[Wikipedia:Good article nominations]] is empty.\n";
	die();
}

// Each GA nominee tag will now be standardized and stripped apart, with each detail found in each tag sorted into the right array

//Prepare some queries for use later
// TODO: rewrite this with the cool DB class
$deleteQuery = $mysql->prepare("DELETE FROM `gan` WHERE `page` = ?;");
$deleteQuery->bindParam(1,$deleteQuery_title,PDO::PARAM_STR);

$insertQuery = $mysql->prepare("INSERT INTO `gan` (`page`, `reviewerplain`, `reviewer`, `subtopic`, `nominator`) VALUES (?,?,?,?,?);");
$insertQuery->bindParam(1,$insertQuery_title,PDO::PARAM_STR);
$insertQuery->bindParam(2,$insertQuery_reviewerplain,PDO::PARAM_STR);
$insertQuery->bindParam(3,$insertQuery_reviewer,PDO::PARAM_STR);
$insertQuery->bindParam(4,$insertQuery_subtopic,PDO::PARAM_STR);
$insertQuery->bindParam(5,$insertQuery_nominator,PDO::PARAM_STR);

$gastats_q = $mysql->prepare("INSERT INTO `reviews` (`review_article`, `review_subpage`, `review_user`, `review_timestamp`) VALUES (?,?,?,?);");
$gastats_q->bindParam(1,$gastats_q_article,PDO::PARAM_INT);
$gastats_q->bindParam(2,$gastats_q_subpage,PDO::PARAM_INT);
$gastats_q->bindParam(3,$gastats_q_user,PDO::PARAM_INT);
$gastats_q->bindParam(4,$gastats_q_timestamp,PDO::PARAM_INT);

$gastats_q2 = $mysql->prepare("INSERT INTO `user` (`user_id`, `user_name`) VALUES (?,?);");
$gastats_q2->bindParam(1,$gastats_q2_userid,PDO::PARAM_INT);
$gastats_q2->bindParam(2,$gastats_q2_username,PDO::PARAM_STR);

$gastats_q3 = $mysql->prepare("INSERT INTO `article` (`article_id`, `article_title`, `article_status`) VALUES (?,?,?);");
$gastats_q3->bindParam(1,$gastats_q3_id,PDO::PARAM_INT);
$gastats_q3->bindParam(2,$gastats_q3_title,PDO::PARAM_STR);
$gastats_q3->bindParam(3,$gastats_q3_status,PDO::PARAM_STR);

$titles = array();
$ganoms = array();
$count = 0;

		
foreach ($articles as $article) {
	$title = substr($article,5); // Remove Talk: from the front
	$titles[] = $title;
	
	$contents = $wiki->getpage($article);
	if (empty($contents)) {
		continue;
	}
	
	$templates = parsetemplates($contents);
	$ganom = null;
	foreach ($templates as $template) {
		if (preg_match("/^GA\s?nominee$/i",$template)) {
			$ganom = $template;
			break;
		}
	}
	if ($ganom==null) {
		continue;
	}
	$currentNom = new GANom($title,$ganom);

	// TODO: The next block of code, could probably be done better
	$reviewpage = "Talk:" . $currentNom . "/GA" . $currentNom->getVar('reviewpage');
	$reviewpage_content = $wiki->getpage($reviewpage);
	if (preg_match("/'''Reviewer:''' .*?(\[\[User:([^|]+)\|[^\]]+\]\]).*?\(UTC\)/", $reviewpage_content, $reviewer)) {
		$currentNom->setReviewer($reviewer[2],str_replace("'''Reviewer:''' ",'',$reviewer[0])); 
		if ($currentNom->getVar('status') == 'new') {
			$currentNom->setStatus('on review');
			$old_contents = $contents;
			
			// TODO: There should be a better way of doing this (i.e. once the page parser is completely written)
			$contents = str_replace("status=|", "status=onreview|", $contents);
			
			if (!preg_match('/\{\{' . preg_quote($reviewpage,'/') . '\}\}/i',$contents)) {
				$contents .= "\n\n{{{$reviewpage}}}";
			}
			
			if ($contents != $old_contents && $wiki->nobots($article,$botuser,$contents) == true) {
				$wiki->edit($article,$contents,"Transcluding GA review",true,true);
			}
			
			// Notify the nom that the page is now on review
			$noms_talk_page = $wiki2->page("User talk:" . $currentNom->getVar('nominator_plain'));
			$noms_talk_page->resolveRedirects();
			if (substr($noms_talk_page,0,strlen("User talk"))=="User talk" && !preg_match('/\[\[' . preg_quote($currentNom,'/') . '\]\].+?' . preg_quote('<!-- Template:GANotice -->','/') . '/',$noms_talk_page->content())) {
				$sig = $currentNom->getVar('reviewer');
				$sig2 = "-- {{subst:user0|User=$sig}} ~~~~~";
				$msg = "{{subst:GANotice|article=$currentNom|days=7}} <small>Message delivered by [[User:$botuser|$botuser]], on behalf of [[User:$sig|$sig]]</small> $sig2";
				$noms_talk_page->edit($noms_talk_page->content() . "\n\n$msg","/* Your [[WP:GA|GA]] nomination of [[" . $currentNom . "]] */ new section");
			}
			
			unset($old_contents);
			
			// TODO: This is a lazy way of doing things, improve it
			$deleteQuery_title = $title;
			$deleteQuery->execute(); // in case it is already defined in the db

			$insertQuery_title = $title;
			$insertQuery_reviewerplain = $currentNom->getVar('reviewer');
			$insertQuery_reviewer = $reviewer[1];
			$insertQuery_subtopic = $currentNom->getVar('subtopic');
			$insertQuery_nominator = $currentNom->getVar('nominator_plain');
			$insertQuery->execute();
			
			$gastats_q2_username = $currentNom->getVar('reviewer');
			$gastats_q2_userid  = $http->get("http://toolserver.org/~chris/db_api.php?action=userid&subject=".urlencode($gastats_q2_username));
			
			$gastats_q_article = $http->get("http://toolserver.org/~chris/db_api.php?action=pageid&subject=".urlencode($title));
			$gastats_q_subpage  = $http->get("http://toolserver.org/~chris/db_api.php?action=pageid&subject=".urlencode($reviewpage));
			$gastats_q_timestamp = $currentNom->getVar('unixtime');
			$gastats_q_user = $gastats_q2_userid;
			$gastats_q->execute();
			
			$gastats_q2->execute();
			
			$gastats_q3_id = $gastats_q_article;
			$gastats_q3_title = $title;
			$gastats_q3_status = '';
			$gastats_q3->execute();
			
			$set = false;
			foreach ($gaStats as $key => $values) {
				if ($values[0]==$gastats_q2_username) {
					$gaStats[$key][1]++;
					$set = true;
				}
			}
			if (!$set) {
				$gaStats[] = array($gastats_q2_username,1);
			}
		}
	}
	
	// Some edit summary stuff
	if (strpos($wpgan, $currentNom->wikicode())  === false) {
		if ($currentNom->getVar('status')=='new' && strpos($wpgan,$currentNom->miniWikiCode())  === false) {
			$editsummary->sNew($title,$currentNom->getVar('subtopic'));
		} elseif ($currentNom->getVar('status')=='on review') {
			$editsummary->onReview($title,$currentNom->getVar('subtopic'),$currentNom->getVar('reviewer'));
		} elseif ($currentNom->getVar('status')=='on hold') {
			$editsummary->onHold($title,$currentNom->getVar('subtopic'),$currentNom->getVar('reviewer'));
			
			$noms_talk_page = $wiki2->page("User talk:" . $currentNom->getVar('nominator_plain'));
			$noms_talk_page->resolveRedirects();
			if (substr($noms_talk_page,0,strlen("User talk"))=="User talk" && !preg_match('/\[\[' . preg_quote($currentNom,'/') . '\]\].+?' . preg_quote('<!-- Template:GANotice result=hold -->','/') . '/',$noms_talk_page->content())) {
				$sig = $currentNom->getVar('reviewer');
				$sig2 = "-- {{subst:user0|User=$sig}} ~~~~~";
				$msg = "{{subst:GANotice|article=$currentNom|result=hold}} <small>Message delivered by [[User:$botuser|$botuser]], on behalf of [[User:$sig|$sig]]</small> $sig2";
				$noms_talk_page->edit($noms_talk_page->content() . "\n\n$msg","/* Your [[WP:GA|GA]] nomination of [[" . $currentNom . "]] */ new section");
			}
		}
	}
	
	unset($title);
	unset($reviewpage);
	unset($reviewer);
	
	$ganoms[] = $currentNom;
}

// Passed or Failed
$selectQuery = $mysql->prepare("SELECT * FROM `gan`;");
$selectQuery->execute();
while ($row = $selectQuery->fetch()) {
	if (in_array($row['page'],$titles))
		continue;
	
	$status = null;
	
	$contents = $wiki->getpage("Talk:" . $row['page']);
	if ( (preg_match("/\|\s?currentstatus\s?=\s?GA/i", $contents) || preg_match("/\{{2}\s?GA(?! nominee)/", $contents)) && !preg_match("/\{{2}\s?FailedGA/i", $contents)) {
		$editsummary->passed($row['page'],$row['subtopic']);
		$article_content = $wiki->getpage($row['page']);
		if(!preg_match("/\{\{good( |_)article\}\}/i", $article_content)) {
			$article_content = "{{good article}}\n" . $article_content;
			$wiki->edit($row['page'],$article_content,"Adding Good Article icon",true,true);
		}
		unset($article_content);
		$status = 'passed';
		
		$noms_talk_page = $wiki2->page("User talk:" . $row['nominator']);
		$noms_talk_page->resolveRedirects();
		if (substr($noms_talk_page,0,strlen("User talk"))=="User talk" && !preg_match('/\[\[' . preg_quote($row['page'],'/') . '\]\].+?' . preg_quote('<!-- Template:GANotice result=pass -->','/') . '/',$noms_talk_page->content())) {
			$sig = $row['reviewerplain'];
			$sig2 = "-- {{subst:user0|User=$sig}} ~~~~~";
			$msg = "{{subst:GANotice|article=".$row['page']."|result=pass}} <small>Message delivered by [[User:$botuser|$botuser]], on behalf of [[User:$sig|$sig]]</small> $sig2";
			$noms_talk_page->edit($noms_talk_page->content() . "\n\n$msg","/* Your [[WP:GA|GA]] nomination of [[" . $row['page'] . "]] */ new section");
		}
	} else {
		$editsummary->failed($row['page'],$row['subtopic']);
		$status = 'failed';
		
		$noms_talk_page = $wiki2->page("User talk:" . $row['nominator']);
		$noms_talk_page->resolveRedirects();
		if (substr($noms_talk_page,0,strlen("User talk"))=="User talk" && !preg_match('/\[\[' . preg_quote($row['page'],'/') . '\]\].+?' . preg_quote('<!-- Template:GANotice result=fail -->','/') . '/',$noms_talk_page->content())) {
			$sig = $row['reviewerplain'];
			$sig2 = "-- {{subst:user0|User=$sig}} ~~~~~";
			$msg = "{{subst:GANotice|article=".$row['page']."|result=fail}} <small>Message delivered by [[User:$botuser|$botuser]], on behalf of [[User:$sig|$sig]]</small> $sig2";
			$noms_talk_page->edit($noms_talk_page->content() . "\n\n$msg","/* Your [[WP:GA|GA]] nomination of [[" . $row['page'] . "]] */ new section");
		}
	}
	
	$deleteQuery_title = $row['page'];
	$deleteQuery->execute();
}

// Use a bubble sort to sort everything by date
$swap = true;
while ($swap) {
	$swap = false;
	for ($i=1;$i<count($ganoms);$i++) {
		$j = $i - 1;
		if ($ganoms[$i]->getVar('unixtime') < $ganoms[$j]->getVar('unixtime')) {
			$tmp = $ganoms[$i];
			$ganoms[$i] = $ganoms[$j];
			$ganoms[$j] = $tmp;
			$swap = true;
		}
	}
}

$lines = explode("\n",$wpgan);

$newpage = '';
$newpage2 = '';
preg_match_all('/\{\{\/nominator\|(.+?)\}\}/',$wiki->getpage('Wikipedia:WikiProject Good articles/Recruitment Centre/Recruiter Central'),$matches);
$valid_nominators = array();
foreach ($matches[1] as $m) {
	$valid_nominators[] = trim(str_replace('_',' ',ucfirst($m)));
}
print_r($valid_nominators);
$subcat = null;
foreach ($lines as $line) {
	if ($subcat==null) {
		if (preg_match('/<!--\s*Bot\s*Start\s*"([^"]+)"\s*-->/i',$line,$m)) {
			$subcat = $m[1];
		}
		$newpage .= $line."\n";
		$newpage2 .= $line."\n";
	} elseif (strpos($line, '<!-- Bot End -->') !== false) {
		foreach ($ganoms as $nom) {
			if ($nom->getVar('subtopic')==$subcat) {
				$newpage .= $nom->wikicode();
				echo $nom->getVar('nominator_plain') . "\n";
				if (in_array($nom->getVar('nominator_plain'),$valid_nominators)) {
					$newpage2 .= $nom->wikicode();
				}
			}
		}
		$newpage .= $line."\n";
		$newpage2 .= $line."\n";
		$subcat = null;
	}
}

$wiki->edit("Wikipedia:Good article nominations",$newpage,$editsummary->generate(),false,true);

$newpage2 = explode('<!-- EVERYTHING BELOW THIS COMMENT IS UPDATED AUTOMATICALLY BY A BOT -->',$newpage2);
$newpage2 = "<!-- EVERYTHING BELOW THIS COMMENT IS UPDATED AUTOMATICALLY BY A BOT -->\n" . $newpage2[1];
$wiki->edit("User:$botuser/Recruitment Centre",$newpage2,$editsummary->generate(),false,true);

$split = explode('<!-- EVERYTHING BELOW THIS COMMENT IS UPDATED AUTOMATICALLY BY A BOT -->',$newpage);
$split2 = explode('<!-- EVERYTHING ABOVE THIS COMMENT IS UPDATED AUTOMATICALLY BY A BOT -->',$split[1]);
$lines = explode("\n",$split2[0]);

$topicLists = array();
foreach ($lines as $line) {
	if (preg_match('/^\s*==([^=]+)==\s*$/',$line,$m)) {
		$subcat = trim($m[1]);
	}
	if (!empty($subcat)) {
		$topicLists[$subcat] .= $line."\n"; 
	}
}

foreach ($topicLists as $subcat => $content) {
	$temp = $wiki->getpage("Wikipedia:Good article nominations/Topic lists/$subcat");
	$split = explode("<!-- BOT STARTS HERE -->",$temp);
	$content = $split[0] . "<!-- BOT STARTS HERE -->\n" . $content;
	
	preg_match_all('/<!--\s*Bot\s*Start\s*"([^"]+)"\s*-->/i',$temp,$m);
	$subcats = array();
	foreach ($m[1] as $cat) {
		$subcats[] = $cat;
	}
	
	$wiki->edit("Wikipedia:Good article nominations/Topic lists/$subcat",$content,$editsummary->generate($subcats),false,true);
}

$n = count($gaStats);
do {
	$newn = 0;
	for ($i=1; $i<$n; $i++) {
		if ($gaStats[($i - 1)][1] < $gaStats[$i][1]) {
			$tmp = $gaStats[$i];
			$gaStats[$i] = $gaStats[($i - 1)];
			$gaStats[($i - 1)] = $tmp;
			$newn = $i;
		}
	}
	$n = $newn;
} while ($n > 0);

$content  = "<table class=\"wikitable\">\n";
$content .= "<tr><th>User</th><th>Reviews</th></tr>\n";
foreach ($gaStats as $x) {
	$user = $x[0];
	$num = $x[1];
	$content .= "<tr> <td> [[User:$user|]] </td> <td> $num </td> </tr>\n";
}
$content .= "</table>\n";
$wiki->edit("User:GA bot/Stats",$content,"Update Stats (Bot)");
