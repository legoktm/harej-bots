#!/usr/bin/php
<?php
/** Chris G Bot 3 - http://en.wikipedia.org/wiki/User:Chris_G_Bot_3
 *  An archving bot for [[WP:CHU]] and [[WP:CHU/SUL]]
 *  Copyright (C) 2008  Chris Grant - http://en.wikipedia.org/wiki/User:Chris_G
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
 *      Kunal - [[User:Legoktm]] - fixed to run on labs
 *  	Chris - [[User:Chris_G]] - Wrote up the code
 **/

ini_set("display_errors", 1);
error_reporting(E_ALL ^ E_NOTICE);

class database {
	protected $dbLink;
	
	public function __construct($host,$username,$password,$database) {
		$this->dbLink = mysql_connect($host, $username, $password) or die('Error connecting to database');
		mysql_select_db($database,$this->dbLink) or die('Error selecting database.');
	}
	
	public function query($query) {
		return mysql_query($query,$this->dbLink);
	}
	
	public function escape($string) {
		return mysql_real_escape_string($string,$this->dbLink);
	}
	
	public function getError() {
		return mysql_error($this->dbLink);
	}
	
	public function __destruct() {
		mysql_close($this->dbLink);
	}
}

class chu {
	protected $page;
	protected $sumclerk;
	protected $crats;
	protected $previous_requests;
	
	public function __construct ($page,$clerk) {
		global $wiki;
		$this->previous_requests = array();
		if ($page=='Wikipedia:Changing username/Simple') {
			$notdone = $page.'/Unfulfilled/'.date('Y').'/'.date('F');
			$done = $this->getarchive('Changing username/Simple/Archive');
		} elseif ($page=='User:Chris G/CHU') {
			$done = 'User:Chris G/CHU/done';
			$notdone = 'User:Chris G/CHU/notdone';
		} else {
			die('Error: unknow page passed to chu class $page = '.$page);
		}
		$this->crats = $this->getcrats();
		$this->sumclerk = false;
		$content = $wiki->getpage($page,true);
		$oldcontent = $content;
		$requests = $this->createrequests($content);
		$header = $requests[0];
		array_shift($requests);
		$donerequests = '';
		$donerequestcount = 0;
		$notdonerequests = '';
		$notdonerequestcount = 0;
		$requeststokeep = '';
		$i = 0;
		foreach ($requests as $r) {
			if ($this->done($r)) {
				$donerequestcount++;
				$donerequests .= $r;
			} elseif ($this->notdone($r)) {
				$notdonerequestcount++;
				$notdonerequests .= $r;
			} else {
				$r = $this->alreadyrenamed($r);
				if ($clerk) {
					$r = $this->clerk($r);
				}
				$requeststokeep .= $r;
			}
			$i++;
		}
		if ($header.$requeststokeep != $oldcontent) {
			$x = $wiki->edit($page,$header.$requeststokeep,$this->editsum($donerequestcount,$notdonerequestcount,$done,$notdone),true);
			if (!$x) {
				die("Error editing $page\n");
			}
			if ($donerequestcount > 0) {
				$this->archive($done,$donerequests,$donerequestcount);
			}
			if ($notdonerequestcount > 0) {
				$this->archive($notdone,$notdonerequests,$notdonerequestcount);
			}
		}
	}
	
	protected function clerk ($request) {
		global $db_enwiki, $db_central, $wiki;
		if (preg_match('/\{\{on\s?hold/i',$request) or preg_match('/\{\{CHU/i',$request)) 
			return $request;
		if (preg_match('/\{\{c(lerk|rat)\s?note/i',$request) or preg_match('/Chris G Bot 3/i',$request) or preg_match('/Robot clerk note/i',$request))
			return $request;
		if (preg_match('/\{\{(not\s?|already\s?)?done\}\}/i',$request))
			return $request;
		if (preg_match('/\{\{renameuser2\s*\|\s*1=([^|]+)/i',$request,$from) && preg_match('/\|\s*2=([^}]+)/i',$request,$to)) {
			$problems = array();
			$from = trim($from[1]);
			$to = trim($to[1]);
			
			if (preg_match('/^CURRENT.+$/',$from)) {
				$from = preg_replace('/^CURRENT\s*/','',$from);
			}
			if (preg_match('/^NEW.+$/',$from)) {
				$to = preg_replace('/^NEW\s*/','',$to);
			}
			
			
			/* Are the usernames the same? */
			if ($from==$to) {
				$problems[] = "The username you have requested is the same as your current name.";
			} else {
				/* Are the names just CURRENT and NEW? */
				if ($from=='CURRENT' && $to=='NEW') {
					$problems[] = "Please replace CURRENT with your current name and NEW with the name you want.";
				} elseif (isset($this->previous_requests[$from])) {
					$anchor = urlencode($from) . '_.E2.86.92_' . urlencode($this->previous_requests[$from]);
					$problems[] = "[[User:$from|]] has already made a request  [[Wikipedia:Changing_username/Simple#$anchor|here]].";
				}
				
				/* Are the usernames the same apart from $to having a lowercase first letter? */
				if ($from==ucfirst($to)) {
					$problems[] = "The requested username is the same as your current name, aside from the first letter being in lowercase. Due to technical limitations, usernames must start with an uppercase letter. However, you may use the template {{tl|lowercase}} on your user and user talk pages, and change your signature to reflect your preference.";
				}
				
				/* Fix case problems. */
				$from = ucfirst($from);
				$to = ucfirst($to);
			
				/* Are the usernames the same apart from $to using underscores instead of spaces. */
				if ($from!=$to && $from==str_replace('_',' ',$to)) {
					$problems[] = "The requested username is the same as your current name, but with underscores where your current name has spaces. These are considered identical by the MediaWiki software.";
				}
			
				/* Check for illegal charaters */
				$illegal_chars = array('@','#');
				foreach ($illegal_chars as $char) {
					if (strpos($to,$char)!==false) {
						$problems[] = "The requested username contains the character \"$char\" which cannot be used in usernames due to technical limitations.";
						break;
					}
				}
			
				/* Does $from exist? */
				$ret = $db_enwiki->query('select user_name,user_editcount from user where user_name="'.$db_enwiki->escape(str_replace('_',' ',$from)).'";');
				$x = mysql_fetch_assoc($ret);
				if (str_replace('_',' ',$from) != $x['user_name']) {
					$problems[] = "The username $from does not exist.";
				}
			
				/* Does $to already exist? */
				$ret = $db_enwiki->query('select user_name,user_editcount from user where user_name="'.$db_enwiki->escape(str_replace('_',' ',$to)).'";');
				$x = mysql_fetch_assoc($ret);
				if (str_replace('_',' ',$to) == $x['user_name']) {
					$url = '<span class="plainlinks">[http://en.wikipedia.org/w/index.php?title=Special:ListUsers&username='.urlencode($to).'&limit=1&offset=0 Special:Listusers]</span>';
					if ($x['user_editcount'] > 0) {
						$problems[] = "The requested username is already registered; see $url. Please choose another name that does not appear on that list.";
					} else {
						$problems[] = "The requested username is already registered; see $url. However, as this requested account has made no edits, it meets the criteria for being usurped; if you wish to usurp it, please read and follow the process at [[Wikipedia:Changing username/Usurpations]]. If you do '''not''' wish to usurp the username, please choose another name to be renamed to. ";
					}
				}
				
				/* Is $from currently blocked? */
				$ret = $db_enwiki->query('select p1.ipb_id, p1.ipb_by_text, p1.ipb_reason from ipblocks as p1, user as p2 where p2.user_name = "'.$db_enwiki->escape(str_replace('_',' ',$form)).'" and p1.ipb_user = p2.user_id;');
				$x = mysql_fetch_assoc($ret);
				if (!empty($x['ipb_id'])) {
					$problems[] = "$from is currently blocked by [[User:".$x['ipb_by_text']."|]] for \"".$x['ipb_reason']."\"";
				}
				
				/* Was the request made by an ip or someone other than the user? */
				if (preg_match('/\* Reason: .+ \[\[(User:|Special:Contributions\/)(.+?)\|.+?\]\] \(\[\[User talk:.+?\|talk\]\]\) \d{2}:\d{2}, \d+ [A-z]+ \d{4} \(UTC\)/i',$request,$m)) {
					if (preg_match('/^[0-9.]+$/',$m[2])) {
						$problems[] = "The request was made by an IP ([[Special:Contributions/".$m[2].'|'.$m[2]."]] please login to request a name change.";
					} elseif (ucfirst(str_replace('_',' ',$m[2])) != ucfirst(str_replace('_',' ',$from))) {
						$problems[] = "The request was made by [[User:".$m[2]."|]], not [[User:$from|]]; please login as $from to request a name change.";
					}
				}
				
				/* Check for an sul account. */
				
				$sulapi = $wiki->query('?action=query&meta=globaluserinfo&guiprop=merged|unattached&guiuser='.urlencode($to));
				print_r($sulapi);
				$url = "<span class=\"plainlinks\">[http://toolserver.org/~quentinv57/tools/sulinfo.php?username=".urlencode($to)." $to]</span>";
				if (!empty($sulapi['query']['globaluserinfo']['merged'])) {
					$count_unattached = count($sulapi['query']['globaluserinfo']['unattached']);
					
					$append = '';
					if ($count_unattached>0) {
						$append = ' {{subst:plural|'.$count_unattached.'|unattached account}} exists with this name.';
						
						foreach ($sulapi['query']['globaluserinfo']['unattached'] as $unattached) {
							if ($unattached['wiki']=='enwiki') {
								$append .= ' The local account is not attached.';
								break;
							}
						}
					}
					$primarywiki = null;
					foreach ($sulapi['query']['globaluserinfo']['merged'] as $merged) {
						if ($merged['method'] == 'primary') {
							$primarywiki = $merged['wiki'];
						}
					}
					if (!empty($primarywiki)) {
						$problems[] = "There is already a [[WP:SUL|global account]] registered for $url (primary wiki: $primarywiki).$append";
					} else {
						$problems[] = "There is already a [[WP:SUL|global account]] registered for $url.$append";
					}
				} elseif (!empty($sulapi['query']['globaluserinfo']['unattached'])) {
					$count_unattached = count($sulapi['query']['globaluserinfo']['unattached']);
				
					if ($count_unattached==1) {
						$problems[] = "There is no [[WP:SUL|global account]] registered for $url, however there is ".$count_unattached." unattached account on another wiki.";
					} else {
						$problems[] = "There is no [[WP:SUL|global account]] registered for $url, however there are ".$count_unattached." unattached accounts on other wikis.";
					}
				}
			}
			
			
			
			if (count($problems)>0) {
				if (count($problems)>1) {
					$request .= ":[[Image:Symbol comment vote.svg|17px]] '''Robot clerk note:''' I have detected the following problems:\r\n";
					foreach ($problems as $problem) {
						$request .= "* $problem\r\n";
					}
					$request = substr($request,0,-2) . " --~~~~\r\n";
				} else {
					$request .= ":[[Image:Symbol comment vote.svg|17px]] '''Robot clerk note:''' ".$problems[0];
					$request .= " --~~~~\r\n";
				}
				$this->sumclerk = true;
			} else {
				$request .= ":[[Image:Symbol comment vote.svg|17px]] '''Robot clerk note:''' No problems found";
				$request .= " --~~~~\r\n";
				$this->sumclerk = true;
			}
			
			$this->previous_requests[$from] = $to;
			return $request;
		} else {
			return $request;
		}
	}
	
	protected function alreadyrenamed ($request) {
		global $wiki;
		if (preg_match('/\{\{(not\s?|already\s?)?done\}\}/i',$request)) {
			return $request;
		}
		if (preg_match('/=== <span id=\".+\">(.+?)<\/span> → (.+) ===/i',$request,$m)) {
			$x = $wiki->query('?action=query&list=logevents&letype=renameuser&letitle=User:'.urlencode($m[1]));
			if (isset($x['query']['logevents'][0]['user'])) {
				if ($x['query']['logevents'][0]['newuser']==trim(ucfirst(str_replace('_',' ',$m[2]))) or preg_match('/'.preg_quote(trim(ucfirst(str_replace('_',' ',$m[2]))),'/').'/i',$x['query']['logevents'][0]['comment'])) {
					$tstamp = strtotime(str_replace(array('T','Z'),' ',$x['query']['logevents'][0]['timestamp']));
					if ((time()-$tstamp) < 600) {
						return $request;
					}
					$text_to_add = ":{{done}} by [[User:".$x['query']['logevents'][0]['user']."|]] --~~~~\r\n";
					if (!preg_match("/\n$/",$request)) {
						$text_to_add = "\r\n".$text_to_add;
					}
					$request = $request.$text_to_add;
					$this->sumclerk = true;
				}
			}
		}
		return $request;
	}
	
	protected function done ($request) {
		foreach ($this->crats as $crat) {
			$crat = str_replace(' ','[ _]',preg_quote($crat,'/'));
			if (preg_match("/\{\{done\}\}.*User(( |_)talk)?:$crat/i", $request)) {
				$unix = $this->getTimestamp($request);
				$time = strtotime('12 hours',$unix);
				if (time() > $time) {
					return true;
				}
			}
		}
		return false;
	}
	
	protected function notdone ($request) {
		foreach ($this->crats as $crat) {
			$crat = str_replace(' ','[ _]',preg_quote($crat,'/'));
			if (preg_match("/\{\{(not\s?|already\s?)done\}\}.*User(( |_)talk)?:$crat/i", $request)) {
				$unix = $this->getTimestamp($request);
				$time = strtotime('36 hours',$unix);
				if (time() > $time) {
					return true;
				}
			}
		}
		return false;
	}
	
	protected function getTimestamp ($text) {
		if (preg_match_all("/([0-9]+:[0-9]+, [0-9]+ [a-z]+ [0-9]+ \((UTC|GMT)\))/i", $text, $m)) {
			return strtotime($m[0][count($m[0])-1]);
		} else {
			return time();
		}
	}
	
	protected function getcrats () {
		global $wiki;
		$x = $wiki->querycontinue('?action=query&list=allusers&augroup=bureaucrat&aulimit=500');
		foreach ($x['query']['allusers'] as $t) {
			$crats[] = $t['name'];
		}
		$page = $wiki->getpage('User:'.$user.'/trustedusers.js');
		$page = str_replace("\r",'',$page);
		$lines = explode("\n",$page);
		foreach ($lines as $line) {
			if (substr($line,0,1) != '#') {
				$crats[] = str_replace('_',' ',$line);
			}
		}
		return $crats;
	}
	
	protected function editsum ($done,$notdone,$done_arch,$notdone_arch) {
		if ($done > 0) {
			$sum = $done . " completed request(s) ([[$done_arch|archive]])";
		}
		if ($notdone > 0) {
			if (isset($sum)) {
				$sum .= ' and ';
			}
			@$sum .= $notdone . " rejected request(s) ([[$notdone_arch|archive]])";
		}
		if ($this->sumclerk) {
			if (isset($sum)) {
				return "Clerking and archiving $sum (bot)";
			} else {
				return 'Clerking (bot)';
			}
		}
		if (isset($sum)) {
			return "Archiving $sum (bot)";
		}
		return '';
	}
	
	protected function archive ($page,$content,$count) {
		global $wiki;
		if ($count < 1) {
			return;
		}
		$content = "\n$content";
		$post = array(
				'title'      => $page,
				'appendtext' => $content,
				'md5'        => md5($content),
				'summary'    => "Adding $count request(s) to archive (bot)",
				'minor'      => true,
				'token'      => $wiki->getedittoken()
			);
		$wiki->query('?action=edit',$post);
	}
	
	protected function createrequests ($content) {
		$requests = array();
		$temp = explode(chr(10), $content);
		$i = 0;
		$top = true;
		foreach ($temp as $t) {
			if (preg_match('/^===.+===$/i', $t)) {
				$i++;
			} elseif (preg_match('/^==[^=]+==$/i', $t)) {
				if ($top) {
					$top = false;
				} else {
					$this->sumclerk = true;
					continue;
				}
			}
			@$requests[$i] = $requests[$i] . $t . "\n";
		}
		return $requests;
	}
	
	protected function getarchive ($page) {
		global $wiki;
		$continue = null;
		$number = 0;
		while (true) {
			$x = $wiki->query('?action=query&list=allpages&apprefix='.urlencode($page).'&apfilterredir=nonredirects&aplimit=500&apnamespace=4'.$continue);
			foreach ($x['query']['allpages'] as $y) {
				if (preg_match('/([0-9]+)$/',$y['title'],$m)) {
					if ($m[1] > $number) {
						$number = $m[1];
					}
				}
			}
			if (isset($x['query-continue']['allpages']['apfrom'])) {
				$continue = '&apfrom='.urldecode($x['query-continue']['allpages']['apfrom']);
			} else {
				break;
			}
		}
		$content = $wiki->getpage('Wikipedia:'.$page.$number);
		if (strlen($content) > 75000) {
			$number++;
		}
		return 'Wikipedia:'.$page.$number;
	}
}

/* Connect to the database */

$toolserver_mycnf = parse_ini_file("/data/project/legobot/replica.my.cnf");
$toolserver_username = $toolserver_mycnf['user'];
$toolserver_password = $toolserver_mycnf['password'];

$db_enwiki = new database('enwiki.labsdb',$toolserver_username,$toolserver_password,'enwiki_p');
$db_central = new database('centralauth.labsdb',$toolserver_username,$toolserver_password,'centralauth_p');
//require_once 'botclasses.php';
require_once 'mediawiki.php';

$user = 'Legobot';
require_once 'harejpass.php';
$wiki = new mediawiki('http://en.wikipedia.org/w/api.php',$user,$botpass);
$page = trim(strtolower($wiki->getpage("User:$user/clerk")));
if ($page=='true') {
	$chu = new chu('Wikipedia:Changing username/Simple',true);
} else {
	$chu = new chu('Wikipedia:Changing username/Simple',false);
}
//$sul = new chu('Wikipedia:Changing username/SUL',false);

?>
