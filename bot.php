<?php
/* 
This php script is to be improved
Made by Pieter Voors
Made for the TU/e CPPS Honors track's website
Last updated: 2019-02-06

This script is to be called ones every minute (for example by a cronjob) and updates every 10 seconds. The intervals can be changed in the config.php file.

This script takes credentials and settings from config.php, so make sure to fill in that file.

Note that this script requires a database with one table with two colums, 'id' and 'lastid' with one row in it. 
Executing the following commands will set up such a database and table named 'compprogslackbot.slack':
# CREATE DATABASE IF NOT EXISTS `compprogslackbot`;
# CREATE TABLE IF NOT EXISTS `slack` (`id` int(11) NOT NULL, `lastid` int(11) NOT NULL) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1;
# INSERT INTO `slack` (`id`, `lastid`) VALUES (0, -1);
# ALTER TABLE `slack` ADD PRIMARY KEY (`id`);
# ALTER TABLE `slack` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=2;
*/

// Make sure only this script loads the settings
$CONFIG_IMPORTING_INTO = "slackbot";

// Loading in the settings
require_once("config.php");

// Connect to the database in which this bots data is stored
$mysqllocal = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_DATABASE);
if (mysqli_connect_errno()) {
	die("Connect failed: " . $mysqllocal->connect_error . "\n");
}

// Check whether that database is set up properly
$idqueryresult = $mysqllocal->query("SELECT * FROM ".$DB_TABLE.";");
if ($idqueryresult->num_rows == 1) {
	$row = $idqueryresult->fetch_assoc();
	$lastid = $row["lastid"];
	$dbid = $row["id"];
} else {
	echo "Not exactly 1 row for lastID in local DB";
	exit();
}

if (! is_numeric($lastid)) {
	die("lastID not numeric");
}
if (! is_numeric($dbid)) {
	die("dbid not numberic");
}

// Connect to the Domjudge database
$domjudge = new mysqli($DOMJUDGE_HOST, $DOMJUDGE_USER, $DOMJUDGE_PASS, $DOMJUDGE_DATABASE);
if (mysqli_connect_errno()) {
	die("Connect failed: " . $domjudge->connect_error . "\n");
}

// Loop as many times as needed. 
// Each loop runs a check for newly solved problems
for ($i = 0; $i < $LENGTH; $i += $INTERVAL) {
	// Check for new submissions
	$result = $domjudge->query("SELECT * FROM judging WHERE judgingid > ".$lastid.";");
	if ($result->num_rows > 0) {
		while($judging = $result->fetch_assoc()) {
			// New submission
			if ($judging["judgingid"] > $lastid) {
				$lastid = $judging["judgingid"];
			}
			if ($judging["result"] === "correct") {
				// Correct submision
				echo("- Correct submission with judge id ".$judging["judgingid"]."\n");
				handle_correct_judging($judging, $domjudge, $mysqllocal, $lastid, $SLACK_HOOK);
			}
		}
		$mysqllocal->query("UPDATE ".$DB_TABLE." SET lastid = " . $lastid . " WHERE id = " . $dbid . ";");
	}
	sleep($INTERVAL);
}

// Called once for every judging that is correct
// Handles reporting it to Slack if this is the first time it is solved by the user
function handle_correct_judging($judging, $domjudge, $mysqllocal, $lastid, $slack_hook) {
	// Collect data to use in the message to Slack
	$problemlinkbase = "https://compprog.win.tue.nl/problems/2/";

	$submission = getSubmission($domjudge, $judging["submitid"]);
	$language = $submission["langid"];

	$team = getTeam($domjudge, $submission["teamid"]);
	$name = $team["name"];

	$contestproblem = getContestProblem($domjudge, $submission["cid"], $submission["probid"]);
	$problemshortname = $contestproblem["shortname"];

	$problem = getProblem($domjudge, $submission["probid"]);
	$problemlongname = $problem["name"];

	// Check whether this is the first time this user has submitted
	// a correct solution. (If not, skip this submision)
	$attempts = getAttempts($domjudge, $submission);
	$numattempts = getNumAttempts($domjudge, $attempts);
	$numcorrectattempts = getNumCorrectAttempts($domjudge, $attempts);
	if ($numcorrectattempts != 1) {
		echo("Checking a correct submission that is the ".$numcorrectattempts."th correct submission, skipping\n");
		return;
	}

	list($numsolved, $fire) = getNumSolvedAndFire($domjudge, $submission["cid"], $submission["teamid"], $judging["submitid"]);

	// Build the payload to send to Slack
	$problemlink = $problemlinkbase . $problemshortname;
	$problemname = $problemshortname . ": " . $problemlongname;
	$payload = '{
		"text": "'.$name.' just solved <'.$problemlink.'|'.$problemname.'> in '.$language.'.';
	if ($numattempts == 1) {
		$payload .= ' They solved it on the first attempt.';
	} else {
		$payload .= ' It took them '.$numattempts.' attempts.';
	}
	if ($fire) {
		$payload .= ' :fire:';
	}
	$payload .= '"}';
	sendSlackMessage($payload, $slack_hook);

	// Check whether to send the x messages solved message
	if (($numsolved % 10) == 0) {
		$payload = '{
			"text": ":tada: '.$name.' just reached a total of '.$numsolved.' solved problems!"}';
		sendSlackMessage($payload, $slack_hook);
	}
}

// Sends a message to slack with a given payload
function sendSlackMessage($payload, $slack_hook) {
	$data = "payload=" . urlencode($payload);
	$options = array(
		'http' => array(
			'header' => "Content-type: application/x-www-form-urlencoded\r\n",
	        'method'  => 'POST',
        	'content' => $data,
		)
	);
	$context = stream_context_create($options);
	file_get_contents($slack_hook, false, $context);

}

// Get a submision by its ID
function getSubmission($domjudge, $submitid) {
	$submissionresult = $domjudge->query("SELECT * FROM submission WHERE `submitid` = ".$domjudge->real_escape_string($submitid).";");
	if ($submissionresult->num_rows == 1) {
		return $submissionresult->fetch_assoc();
	} else {
		echo("Error: No submission found associated with $submitid ".$submitid."\n");
		return;
	}
}

// Get all submissions of a person by their teamid and contest id
// Only considers submissions with submitid < the given submitid
function getSubmissions($domjudge, $cid, $teamid, $submitid) {
	$submissionresult = $domjudge->query("SELECT * FROM submission WHERE `cid` = ".$domjudge->real_escape_string($cid)." AND `teamid` = ".$domjudge->real_escape_string($teamid)." AND `submitid` <= ".$domjudge->real_escape_string($submitid).";");
	return $submissionresult;	
}

// Get a person's information by their ID
function getTeam($domjudge, $teamid) {
	$teamresult = $domjudge->query("SELECT * FROM team WHERE `teamid` = ".$domjudge->real_escape_string($teamid).";");
	if ($teamresult->num_rows == 1) {
		return $teamresult->fetch_assoc();
	} else {
		echo("Error: No team found associated with teamid ".$teamid."\n");
		return;
	}
}

// Get a contest-problem by its contest id and problem id
function getContestProblem($domjudge, $cid, $probid) {
	$contestprobresult = $domjudge->query("SELECT * FROM contestproblem WHERE `cid` = ".$domjudge->real_escape_string($cid)." AND `probid` = ".$domjudge->real_escape_string($probid).";");
	if ($contestprobresult->num_rows == 1) {
		return $contestprobresult->fetch_assoc();
	} else {
		echo("Error: No contestproblem found associated with cid".$cid. " and probid ".$probid."\n");
		return;
	}
}

// Get a problem by its problem id
function getProblem($domjudge, $probid) {
	$probresult = $domjudge->query("SELECT * FROM problem WHERE `probid` = ".$domjudge->real_escape_string($probid).";");
	if ($probresult->num_rows == 1) {
		return $probresult->fetch_assoc();
	} else {
		echo("Error: No problem found associated with probid ".$probid."\n");
		return;
	}
}

// Find the number of attempts made given an attempts query
function getNumAttempts($domjudge, $attempts) {
	if ($attempts->num_rows >= 1) {
		return $attempts->num_rows;
	} else {
		echo("Error: No submissions found while already one found earlier?\n");
		return;
	}
}

// Get the attempts a person made for the same problem as a given submission
// Automatically gathers which person is meant from the submission
function getAttempts($domjudge, $submission) {
	$query = "SELECT * FROM submission WHERE `cid` = ".$domjudge->real_escape_string($submission["cid"])." AND `teamid` = ".$domjudge->real_escape_string($submission["teamid"])." AND `probid` = ".$domjudge->real_escape_string($submission["probid"])."  AND `submitid` <= ".$domjudge->real_escape_string($submission["submitid"]).";";
	$submissionresult = $domjudge->query($query);
	return $submissionresult;
}

// Get the latest judging that corresponds to a submit id
function getJudgingBySubmitid($domjudge, $submitid) {
	$judgeresult = $domjudge->query("SELECT * FROM judging WHERE `submitid` = ".$domjudge->real_escape_string($submitid).";");
	if ($judgeresult->num_rows > 0) {
		$lastjudging;
		$lastjudgingid = -1;
		while ($judging = $judgeresult->fetch_assoc()) {
			if ($judging["judgingid"] > $lastjudgingid) {
				$lastjudging = $judging;
				$lastjudgingid = $judging["judgingid"];
			}
		}
		return $lastjudging;
	} else {
		echo("Error: No judging found associated with submitid ".$submitid."\n");
		return;
	}
}

// Get the number of correct attempts in a query of attempts
function getNumCorrectAttempts($domjudge, $attempts) {
	$numcorrectattempts = 0;
	if ($attempts->num_rows > 0) {		
		while($attempt = $attempts->fetch_assoc()) {
			$judging = getJudgingBySubmitid($domjudge, $attempt["submitid"]);
			if ($judging["result"] === "correct") {
				$numcorrectattempts ++;
			}
		}
	}
	return $numcorrectattempts;
}

// For a user, get the number of total solved problems and whether
// or not they deserve fire for their latest solution
// Fire is given if the last 3 solutions had a gap of at most 1 day between solutions
// Takes into account only submissions made before the specified submitid
function getNumSolvedAndFire($domjudge, $cid, $teamid, $submitid) {
	$allsubmissions = getSubmissions($domjudge, $cid, $teamid, $submitid);
	$correctset = array();
	$time3 = 0;
	$time2 = 0;
	$time1 = 0;
	if ($allsubmissions->num_rows > 0) {
		while ($submission = $allsubmissions->fetch_assoc()) {
			$judging = getJudgingBySubmitid($domjudge, $submission["submitid"]);
			if ($judging["result"] === "correct") {
				$correctset[$submission["probid"]] = true;
				$time3 = $time2;
				$time2 = $time1;
				$time1 = $submission["submittime"];
			}
		}
	}
	$SECONDSPERDAY = 60 * 60 * 24;
	$fire = (
		$time1 < $time2 + $SECONDSPERDAY &&
		$time2 < $time3 + $SECONDSPERDAY
	);
	return array(sizeof($correctset), $fire);
}