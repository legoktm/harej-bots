#!/usr/bin/php
<?php
/** mfdarchiver.php -- Moves MfD discussions to the archive.
 *
 *  (c) 2013 Chris Grant - http://en.wikipedia.org/wiki/User:Chris_G
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
 *    Chris Grant - [[User:Chris G]] - Rewrote the bot.
 *    James Hare  - [[User:Harej]]   - Wrote the original bot.
 **/

class mfd_container {
	private $mfds = array(), $old = array();
	
	public function add ($mfd) {
		if ($mfd->isArchive())
				return;
		if ($mfd->isExpired()) {
			$this->old[$mfd->starttime("Y")][$mfd->starttime("n")][$mfd->starttime("j")][] = $mfd;
		} else {
			$this->mfds[$mfd->starttime("Y")][$mfd->starttime("n")][$mfd->starttime("j")][] = $mfd;
		}
	}
	
	private function sort ($array=null) {
		if ($array==null) {
			$this->sort($this->old);
			$this->sort($this->mfds);
			return;
		}
		
		krsort($array);
		foreach ($array as $key => $x) {
			krsort($array[$key]);
			foreach ($array[$key] as $k => $y) {
				krsort($array[$key][$k]);
			}
		}
	}
	
	private function helper ($array) {
		$return = "";
		$months = explode(" ","null January February March April May June July August September October November December");
	
		foreach ($array as $year => $x) {
			foreach ($x as $month => $y) {
				$month = $months[$month];
				foreach ($y as $day => $z) {
					$return .= "===$month $day, $year===\n";
					foreach ($z as $mfd) {
						$return .= '{{' . $mfd . '}}' . "\n";
					}
					$return .= "\n";
				}
			}
		}
		
		return $return;
	}
	
	public function __toString () {
		$this->sort();
		$ret = "";
		
		if (!isset($this->mfds[date("Y")][date("n")][date("j")])) {
			$ret .= "===" . date("F") . " " . date("j") . ", " . date("Y") . "===\n\n";
		}
		
		$ret .= $this->helper($this->mfds);
		
		if (!empty($this->old)) {
			$ret .= "==Old business==\n";
			$ret .= '{{mfdbacklog}}' . "\n";
			$ret .= $this->helper($this->old);
		}
		
		return $ret;
	}
}

class mfd {
	private $page, $starttime, $endtime, $result;
	
	public function __construct ($title) {
		global $wiki;
		
		$this->page = $wiki->page($title);
		$this->page->resolveRedirects();
		
		preg_match_all("/\d\d:\d\d, \d?\d \w+ \d\d\d\d \(UTC\)/i", $this->page->content(), $n);
		
		if ($this->isClosed()) {
			$this->starttime = strtotime($n[0][1]);
			$this->endtime = strtotime($n[0][0]);
			
			preg_match("/The result of the discussion was\s*[^']*'{3}[^']*'{3}/i", $this->page->content(), $o);
			$this->result = str_replace("'''", "", $o[0]);
			$this->result = preg_replace("/The result of the discussion was\s*/", "", $this->result);
		} else {
			$this->starttime = strtotime($n[0][0]);
		}
	}
	
	public function starttime ($format=false) {
		if (!$format)
			return $this->starttime;
		return date($format,$this->starttime);
	}
	
	public function endtime () {
		if (!$this->isClosed())
			return false;
		return $this->endtime;
	}
	
	public function __toString () {
		return $this->page->title();
	}
	
	public function isClosed () {
		return (strpos($this->page->content(), "The following discussion is an archived debate") !== false);
	}
	
	public function isArchive () {
		return ($this->isClosed() && (time() - $this->endtime() >= 64800)); // Archive MFDs older than 18 hours
	}
	
	public function isExpired () {
		return (time() - $this->starttime() >= 691200);
	}
	
	public function result () {
		return $this->result;
	}
}

ini_set("display_errors", 1);
error_reporting(E_ALL ^ E_NOTICE);

$botuser = 'Legobot';
require_once 'botclasses.php';
require_once 'new_mediawiki.php';
require_once 'harejpass.php';

echo "Logging in...";
$wiki = new mediawiki($botuser, $botpass);
echo " done.\n";

echo "Retrieving MFD contents... ";
$page = $wiki->page("Wikipedia:Miscellany for deletion");
$mfdpage = $page->content();
preg_match_all("/\{\{(Wikipedia:Miscellany for deletion\/(?!Front matter)(.*?)*)\}\}/i", $mfdpage, $m);

$container = new mfd_container();

// Loop through each MFD
foreach ($m[1] as $title) {
	$mfd = new mfd($title);
	$container->add($mfd);
	
	if ($mfd->isArchive()) {
		$origmonth = $mfd->starttime("F");
		$origyear = $mfd->starttime("Y");
		
		$archive = $wiki->page("Wikipedia:Miscellany for deletion/Archived debates/" . $origmonth . " " . $origyear);
		
		if (!$archive->exists()) {
			// Create the archive page
			$archivecontents = "";
			$days_in_month = date("t",$mfd->starttime());
			for ($i=1;$i<$days_in_month;$i++) {
				$archivecontents = "=== $origmonth $i, $origyear ===\n\n" . $archivecontents;
			}
			$archivecontents = '{{TOCright}}' . "\n\n" . $archivecontents;
		} else {
			$archivecontents = $archive->content();
		}
		
		// Add the archived mfd under the correct section heading
		$old = "=== " . $mfd->starttime("F j, Y") . " ===";
		$new = "$old\n* [[" . $mfd . "]] (" . $mfd->result() . ")";
		
		$archivecontents = str_replace($old, $new, $archivecontents);
		$archivecontents = str_replace("( ", "(", $archivecontents);
		
		$archive->edit($archivecontents,"Archiving: [[" . $mfd . "]]");
	}
}


$x = explode("<!-- PLEASE ADD your discussion BELOW this line, creating a new dated section where necessary. -->",$mfdpage);
$x2 = explode("==Closed discussions==",$mfdpage);

$top = $x[0] . "<!-- PLEASE ADD your discussion BELOW this line, creating a new dated section where necessary. -->\n\n";
$bottom = "==Closed discussions==" . $x2[1];

$mfdpage = $top . $container . $bottom;

$page->edit($mfdpage,"Removing archived MfD debates");
